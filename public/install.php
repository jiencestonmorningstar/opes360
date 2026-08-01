<?php

use Illuminate\Contracts\Console\Kernel;

/*
 * Browser installer for shared hosting.
 *
 * cPanel accounts frequently have no SSH, and the setup a fresh install needs —
 * generating the app key, writing .env, migrating, seeding the roles that
 * registration depends on, creating the first administrator — is otherwise all
 * artisan. This does it from a web page instead.
 *
 * It refuses to run once the install is finished, and the last step tells you to
 * delete this file. Deleting it is belt and braces: the lock alone is enough.
 */

// ---------------------------------------------------------------- locating things
$root = null;
foreach ([__DIR__.'/..', __DIR__.'/../opes360'] as $candidate) {
    if (is_file($candidate.'/composer.json') && is_dir($candidate.'/bootstrap')) {
        $root = realpath($candidate);
        break;
    }
}

if ($root === null) {
    fail('OPES360 could not find its application files. They belong either in the parent directory of this one, or in a folder named "opes360" beside it.');
}

$lock = $root.'/storage/app/installed';
$envPath = $root.'/.env';

if (is_file($lock)) {
    fail('This install is already set up. Delete <code>install.php</code> from your public folder — it is no longer needed.', 'Already installed');
}

// ---------------------------------------------------------------- tiny helpers
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function fail(string $message, string $title = 'Cannot continue'): void
{
    page($title, '<p class="bad">'.$message.'</p>');
    exit;
}

function page(string $title, string $body, string $step = ''): void
{
    $s = $step !== '' ? '<p class="step">'.e($step).'</p>' : '';
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · OPES360 setup</title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;background:#eef2f7;color:#0f172a;padding:32px 16px}
.card{max-width:640px;margin:0 auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 24px rgba(15,23,42,.10)}
h1{font-size:21px;font-weight:800;letter-spacing:-.02em;margin-bottom:4px}
.step{font-size:12.5px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:14px}
label{display:block;margin-top:14px;font-size:13.5px;font-weight:600;color:#334155}
input{width:100%;height:42px;margin-top:5px;padding:0 12px;border:1px solid #cbd5e1;border-radius:9px;font:inherit}
input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
button{margin-top:22px;width:100%;height:46px;border:0;border-radius:9px;background:#2563eb;color:#fff;font:inherit;font-weight:700;cursor:pointer}
button:hover{opacity:.92}
.hint{font-size:12.5px;color:#64748b;margin-top:4px}
.ok{color:#15803d}.bad{color:#b91c1c}
ul{margin:12px 0 0 18px}li{margin:3px 0}
.done{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px;margin-top:16px}
pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:9px;overflow:auto;font-size:12.5px;margin-top:12px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:13px}
</style></head><body><div class="card">{$s}<h1>{$title}</h1>{$body}</div></body></html>
HTML;
}

/** Boots Laravel and runs an artisan command, returning [exitCode, output]. */
function artisan(string $root, string $command, array $params = []): array
{
    static $kernel = null;

    if ($kernel === null) {
        require_once $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
    }

    $code = $kernel->call($command, $params);

    return [$code, $kernel->output()];
}

// ---------------------------------------------------------------- step 1: requirements
/*
 * Only what the application genuinely loads. The older guides also listed
 * bcmath, which nothing here calls — the whole suite passes without it — and
 * requiring it would turn a perfectly good host into a dead end.
 */
$extensions = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'ctype', 'json'];
$problems = [];

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    $problems[] = 'PHP '.PHP_VERSION.' is too old — choose 8.2 or newer in cPanel → MultiPHP Manager.';
}

foreach ($extensions as $ext) {
    if (! extension_loaded($ext)) {
        $problems[] = "The PHP extension <code>{$ext}</code> is not enabled (cPanel → Select PHP Extensions).";
    }
}

foreach (['storage', 'storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $dir) {
    if (! is_writable($root.'/'.$dir)) {
        $problems[] = "The folder <code>{$dir}</code> is not writable — set its permissions to 775 in File Manager.";
    }
}

if (! is_file($root.'/vendor/autoload.php')) {
    $problems[] = 'The <code>vendor</code> folder is missing. Upload the full release archive, not the source code.';
}

if ($problems !== []) {
    fail('<strong>Fix these first, then reload this page:</strong><ul><li>'.implode('</li><li>', $problems).'</li></ul>', 'Hosting check');
}

// ---------------------------------------------------------------- where are we up to?
$env = is_file($envPath) ? file_get_contents($envPath) : '';
$hasKey = (bool) preg_match('/^APP_KEY=base64:\S+/m', $env);

// An install that stopped halfway resumes where it left off: the .env carrying
// a real key is what says the first step is done.
$stage = $hasKey ? 'admin' : 'configure';

// ---------------------------------------------------------------- handle submissions
$action = post('action');
$errors = [];

if ($action === 'configure') {
    $appName = post('app_name', 'OPES360');
    $appUrl = rtrim(post('app_url'), '/');
    $dbHost = post('db_host', 'localhost');
    $dbName = post('db_name');
    $dbUser = post('db_user');
    $dbPass = $_POST['db_pass'] ?? '';

    if ($appUrl === '' || ! preg_match('#^https?://#', $appUrl)) {
        $errors[] = 'The website address must start with http:// or https://.';
    }
    if (! str_starts_with($appUrl, 'https://')) {
        $errors[] = 'Use the https:// address. Sessions, the QR codes on your cards and the offline mode all depend on it.';
    }
    if ($dbName === '' || $dbUser === '') {
        $errors[] = 'The database name and user are both required.';
    }

    if ($errors === []) {
        try {
            new PDO("mysql:host={$dbHost};dbname={$dbName}", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to the database: '.e($e->getMessage())
                .'<br>In cPanel the names are prefixed — asking for <code>opes360</code> gives you <code>youruser_opes360</code>. Use the full name, and check the user is added to the database in MySQL Databases.';
        }
    }

    if ($errors === []) {
        $template = is_file($root.'/.env.example') ? file_get_contents($root.'/.env.example') : '';
        $key = 'base64:'.base64_encode(random_bytes(32));

        $values = [
            'APP_NAME' => '"'.str_replace('"', '', $appName).'"',
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $dbHost,
            'DB_PORT' => '3306',
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => '"'.str_replace('"', '\"', (string) $dbPass).'"',
            'SESSION_DRIVER' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'OPES_DEMO_LOGINS' => 'false',
        ];

        foreach ($values as $k => $v) {
            $template = preg_match("/^{$k}=.*/m", $template)
                ? preg_replace("/^{$k}=.*/m", "{$k}={$v}", $template)
                : $template."\n{$k}={$v}";
        }

        if (@file_put_contents($envPath, $template) === false) {
            $errors[] = 'Could not write the <code>.env</code> file. Check that the application folder is writable.';
        } else {
            @chmod($envPath, 0600);
            header('Location: install.php?step=admin');
            exit;
        }
    }
}

if ($action === 'admin') {
    $adminName = post('admin_name', 'Administrator');
    $adminEmail = post('admin_email');
    $adminPass = $_POST['admin_pass'] ?? '';

    if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address for the administrator.';
    }
    if (strlen($adminPass) < 12) {
        $errors[] = 'The password must be at least 12 characters, with letters, numbers and a symbol.';
    }

    if ($errors === []) {
        try {
            [$code, $out] = artisan($root, 'migrate', ['--force' => true]);
            if ($code !== 0) {
                $errors[] = 'Creating the database tables failed:<pre>'.e($out).'</pre>';
            }

            if ($errors === []) {
                [$code, $out] = artisan($root, 'opes:install', [
                    '--admin-email' => $adminEmail,
                    '--admin-name' => $adminName,
                    '--admin-password' => $adminPass,
                ]);

                if ($code !== 0) {
                    $errors[] = 'Setting up the first administrator failed:<pre>'.e($out).'</pre>';
                }
            }

            if ($errors === []) {
                // Best effort — an install is still usable without these.
                try {
                    artisan($root, 'storage:link', []);
                } catch (Throwable $e) {
                }
                foreach (['config:cache', 'route:cache', 'view:cache'] as $c) {
                    try {
                        artisan($root, $c, []);
                    } catch (Throwable $e) {
                    }
                }

                @file_put_contents($lock, date('c')."\n");

                $url = trim(preg_match('/^APP_URL=(.*)$/m', file_get_contents($envPath), $m) ? $m[1] : '', '"');

                page('Setup complete', '
                    <div class="done">
                        <p><strong class="ok">OPES360 is installed.</strong></p>
                        <p style="margin-top:6px">Sign in at <a href="'.e($url).'/admin/login">'.e($url).'/admin/login</a> as
                        <strong>'.e($adminEmail).'</strong> and turn on two-factor authentication straight away.</p>
                    </div>
                    <p style="margin-top:18px"><strong>Two things still to do, both in cPanel — no shell needed:</strong></p>
                    <ul>
                      <li><strong>Cron Jobs</strong> — add the two entries from the deployment guide, or scheduled work and
                          queued email never run. Nothing reports an error if you skip this.</li>
                      <li><strong>Email</strong> — set the SMTP details in <code>.env</code> (File Manager → Edit), or password
                          resets go nowhere.</li>
                    </ul>
                    <p style="margin-top:16px">Then delete <code>install.php</code> from your public folder. It has already
                    locked itself, so this is simply tidying up.</p>
                ', 'Finished');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Setup failed: <pre>'.e($e->getMessage()).'</pre>';
        }
    }

    $stage = 'admin';
}

if (isset($_GET['step']) && $_GET['step'] === 'admin' && $hasKey) {
    $stage = 'admin';
}

// ---------------------------------------------------------------- render the form
$errorHtml = $errors === [] ? '' : '<p class="bad" style="margin-top:14px">'.implode('<br>', $errors).'</p>';

if ($stage === 'configure') {
    $guessedUrl = 'https://'.($_SERVER['HTTP_HOST'] ?? 'yourdomain.com');

    page('Connect your database', $errorHtml.'
        <p style="margin-top:10px;color:#475569">Create the database first in <strong>cPanel → MySQL Databases</strong>:
        add a database, add a user, then add that user to the database with all privileges.</p>
        <form method="post">
          <input type="hidden" name="action" value="configure">
          <label>Business name shown in the app
            <input name="app_name" value="'.e(post('app_name', 'OPES360')).'" required></label>
          <label>Website address
            <input name="app_url" value="'.e(post('app_url', $guessedUrl)).'" required>
            <span class="hint">Must be the https:// address — your card QR codes are built from it.</span></label>
          <label>Database host
            <input name="db_host" value="'.e(post('db_host', 'localhost')).'" required></label>
          <label>Database name
            <input name="db_name" value="'.e(post('db_name')).'" placeholder="youruser_opes360" required></label>
          <label>Database user
            <input name="db_user" value="'.e(post('db_user')).'" placeholder="youruser_opes" required></label>
          <label>Database password
            <input type="password" name="db_pass"></label>
          <button type="submit">Test connection and continue</button>
        </form>', 'Step 1 of 2');
    exit;
}

page('Create your administrator', $errorHtml.'
    <p style="margin-top:10px;color:#475569">This account manages the whole platform — every business, plan and suspension.
    Setting up the database tables happens now too, so this step can take up to a minute.</p>
    <form method="post">
      <input type="hidden" name="action" value="admin">
      <label>Your name
        <input name="admin_name" value="'.e(post('admin_name', 'Administrator')).'" required></label>
      <label>Your email
        <input type="email" name="admin_email" value="'.e(post('admin_email')).'" required></label>
      <label>Password
        <input type="password" name="admin_pass" required>
        <span class="hint">At least 12 characters, with letters, numbers and a symbol.</span></label>
      <button type="submit">Install OPES360</button>
    </form>', 'Step 2 of 2');
