<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;

/*
 * Mail diagnosis for shared hosting.
 *
 * When sending is queued, a failure is invisible. When it is synchronous, it
 * surfaces as a 500 with the reason buried in the log. This page digs the
 * reason out, and — more usefully — tries every host and port combination a
 * cPanel account normally accepts, so the one that works is found by testing
 * rather than by guessing.
 *
 * Delete it once mail is working.
 */

$root = null;
foreach ([__DIR__.'/..', __DIR__.'/../opes360'] as $candidate) {
    if (is_file($candidate.'/artisan')) {
        $root = realpath($candidate);
        break;
    }
}

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * Opens an SMTP conversation far enough to prove the port is usable.
 *
 * Port 465 speaks TLS from the first byte; 587 and 25 start in the clear. A
 * shared host that blocks outbound SMTP fails here with a connection error,
 * which is the single most common reason mail dies on cPanel.
 */
function probe(string $host, int $port, float $timeout = 8.0): array
{
    $scheme = $port === 465 ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => [
        // Proving reachability, not validating identity — a self-signed
        // certificate on the host's own mail server must not read as "blocked".
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ]]);

    $started = microtime(true);
    $socket = @stream_socket_client(
        $scheme.$host.':'.$port,
        $errNo,
        $errStr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context,
    );
    $elapsed = round((microtime(true) - $started) * 1000);

    if (! $socket) {
        return ['ok' => false, 'detail' => trim($errStr) ?: "error {$errNo}", 'ms' => $elapsed];
    }

    stream_set_timeout($socket, (int) $timeout);
    $banner = trim((string) fgets($socket, 512));
    @fclose($socket);

    // A 220 greeting is SMTP saying "ready"; anything else is a door that
    // opened onto something that is not a mail server.
    return [
        'ok' => str_starts_with($banner, '220'),
        'detail' => $banner !== '' ? $banner : 'connected, but no SMTP greeting',
        'ms' => $elapsed,
    ];
}

/**
 * Attempts a real SMTP login, so a password can be checked without editing
 * .env and reloading the application. Returns [ok, transcript].
 *
 * 535 means the server understood the attempt and rejected the credentials —
 * which is a different problem from a port that will not open, and the two are
 * worth telling apart before changing anything.
 */
function smtpAuth(string $host, int $port, string $user, string $pass): array
{
    $log = [];
    $scheme = $port === 465 ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
    ]]);

    $socket = @stream_socket_client($scheme.$host.':'.$port, $n, $err, 10, STREAM_CLIENT_CONNECT, $context);
    if (! $socket) {
        return [false, ['could not connect: '.($err ?: $n)]];
    }

    stream_set_timeout($socket, 10);

    $read = function () use ($socket, &$log) {
        $out = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $out .= $line;
            // A multiline reply keeps a hyphen in the fourth column.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $log[] = '< '.trim($out);

        return $out;
    };
    $write = function (string $cmd, bool $secret = false) use ($socket, &$log) {
        $log[] = '> '.($secret ? '(hidden)' : trim($cmd));
        fwrite($socket, $cmd."\r\n");
    };

    $read();
    $write('EHLO opes360.local');
    $read();

    // Plain STARTTLS ports must be upgraded before credentials are offered.
    if ($port !== 465) {
        $write('STARTTLS');
        $up = $read();
        if (str_starts_with(trim($up), '220')) {
            @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write('EHLO opes360.local');
            $read();
        }
    }

    $write('AUTH LOGIN');
    $read();
    $write(base64_encode($user), true);
    $read();
    $write(base64_encode($pass), true);
    $result = $read();

    $write('QUIT');
    @fclose($socket);

    return [str_starts_with(trim($result), '235'), $log];
}

// ---------------------------------------------------------------- boot Laravel
$config = null;
$bootError = null;

if ($root !== null && is_file($root.'/vendor/autoload.php')) {
    try {
        require_once $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $config = [
            'mailer' => config('mail.default'),
            'host' => (string) config('mail.mailers.smtp.host'),
            'port' => (int) config('mail.mailers.smtp.port'),
            'scheme' => (string) (config('mail.mailers.smtp.scheme') ?: ''),
            'username' => (string) config('mail.mailers.smtp.username'),
            'from' => (string) config('mail.from.address'),
            'queue' => config('queue.default'),
            'cached' => is_file($root.'/bootstrap/cache/config.php'),
        ];
    } catch (Throwable $e) {
        $bootError = $e->getMessage();
    }
}

// ---------------------------------------------------------------- last failure
$logTail = null;

if ($root !== null && is_file($log = $root.'/storage/logs/laravel.log')) {
    $size = filesize($log);
    $handle = @fopen($log, 'r');
    if ($handle) {
        // Only the tail: these files reach tens of megabytes.
        fseek($handle, max(0, $size - 120000));
        $chunk = (string) fread($handle, 120000);
        fclose($handle);

        // The last entry mentioning mail is the one that matters.
        $entries = preg_split('/^\[\d{4}-\d{2}-\d{2}/m', $chunk) ?: [];
        foreach (array_reverse($entries) as $entry) {
            if (stripos($entry, 'mail') !== false || stripos($entry, 'smtp') !== false || stripos($entry, 'swift') !== false) {
                $logTail = substr(trim($entry), 0, 2500);
                break;
            }
        }
        $logTail ??= substr(trim((string) end($entries)), 0, 2000);
    }
}

// ---------------------------------------------------------------- port probes
$targets = [];

if ($config !== null && $config['host'] !== '') {
    $targets[] = [$config['host'], $config['port'] ?: 465];
}

// The combinations a cPanel account normally accepts. Local delivery usually
// survives even when outbound SMTP to the wider internet is blocked.
foreach ([['localhost', 465], ['localhost', 587], ['localhost', 25], ['127.0.0.1', 587]] as $pair) {
    $targets[] = $pair;
}

if ($config !== null && $config['host'] !== '') {
    $targets[] = ['mail.'.ltrim($config['host'], 'mail.'), 587];
}

$seen = [];
$probes = [];

foreach ($targets as [$host, $port]) {
    $key = $host.':'.$port;
    if (isset($seen[$key]) || $host === '') {
        continue;
    }
    $seen[$key] = true;
    $probes[$key] = probe($host, (int) $port) + ['host' => $host, 'port' => (int) $port];
}

// ---------------------------------------------------------------- live send
$send = null;
$to = isset($_GET['to']) && is_string($_GET['to']) ? trim($_GET['to']) : '';

if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && $config !== null) {
    try {
        Mail::raw(
            "This is a test from your OPES360 install.\n\nIf you are reading it, sending works.",
            fn ($message) => $message->to($to)->subject('OPES360 mail test'),
        );
        $send = ['ok' => true, 'detail' => 'Accepted by the mail server. Check the inbox, and the spam folder.'];
    } catch (Throwable $e) {
        $send = ['ok' => false, 'detail' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------- credential test
$auth = null;
$authUser = isset($_POST['smtp_user']) && is_string($_POST['smtp_user']) ? trim($_POST['smtp_user']) : '';
$authPass = $_POST['smtp_pass'] ?? '';
$authHost = isset($_POST['smtp_host']) && is_string($_POST['smtp_host']) ? trim($_POST['smtp_host']) : '';
$authPort = isset($_POST['smtp_port']) ? (int) $_POST['smtp_port'] : 465;

if ($authUser !== '' && $authPass !== '' && $authHost !== '') {
    [$ok, $transcript] = smtpAuth($authHost, $authPort, $authUser, (string) $authPass);
    $auth = ['ok' => $ok, 'log' => $transcript];
}

?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mail check · OPES360</title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;background:#eef2f7;color:#0f172a;padding:32px 16px}
.card{max-width:860px;margin:0 auto 18px;background:#fff;border-radius:14px;padding:26px;box-shadow:0 4px 24px rgba(15,23,42,.10)}
h1{font-size:21px;font-weight:800;letter-spacing:-.02em}
h2{font-size:15px;font-weight:700;margin:20px 0 8px}
p{margin-top:8px}
pre{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:9px;overflow:auto;font-size:12.5px;margin-top:8px;white-space:pre-wrap;word-break:break-word;max-height:340px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:13.5px}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13.5px}
th,td{text-align:left;padding:7px 9px;border-bottom:1px solid #e2e8f0;vertical-align:top}
th{color:#64748b;font-weight:600}
.ok{color:#15803d;font-weight:700}.bad{color:#b91c1c;font-weight:700}.warn{color:#b45309;font-weight:700}
input{height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;min-width:260px}
button{height:40px;padding:0 18px;border:0;border-radius:8px;background:#2563eb;color:#fff;font:inherit;font-weight:700;cursor:pointer}
.note{background:#f8fafc;border-left:3px solid #cbd5e1;padding:10px 14px;margin-top:12px;font-size:13.5px}
</style></head><body>

<div class="card">
<h1>Mail check</h1>
<?php if ($root === null) { ?>
    <p class="bad">Could not find the application.</p>
<?php } elseif ($bootError !== null) { ?>
    <p class="bad">The application could not start: <?= h($bootError) ?></p>
<?php } else { ?>
    <p>Application: <code><?= h($root) ?></code></p>
<?php } ?>
</div>

<?php if ($config !== null) { ?>
<div class="card">
<h2>Current settings</h2>
<table>
<tr><th>Transport</th><td><?= $config['mailer'] === 'smtp' ? '<span class="ok">smtp</span>' : '<span class="bad">'.h($config['mailer']).'</span>' ?></td></tr>
<tr><th>Host</th><td><code><?= h($config['host']) ?></code></td></tr>
<tr><th>Port</th><td><code><?= h((string) $config['port']) ?></code></td></tr>
<tr><th>Scheme</th><td><?= $config['port'] === 465 && $config['scheme'] !== 'smtps'
    ? '<span class="bad">'.h($config['scheme'] ?: '(not set)').' — port 465 needs smtps</span>'
    : '<code>'.h($config['scheme'] ?: '(not set)').'</code>' ?></td></tr>
<tr><th>Username</th><td><code><?= h($config['username']) ?></code></td></tr>
<tr><th>Queue</th><td><code><?= h((string) $config['queue']) ?></code><?= $config['queue'] === 'sync' ? ' — sent during the request' : ' — sent by the worker cron' ?></td></tr>
<tr><th>Config cache</th><td><?= $config['cached']
    ? '<span class="warn">present — edits to .env are being ignored until it is deleted</span>'
    : '<span class="ok">none — .env is read live</span>' ?></td></tr>
</table>
</div>
<?php } ?>

<div class="card">
<h2>Which mail routes this server actually allows</h2>
<p>Each row opens a real connection. A blocked port is the usual reason mail fails on shared hosting.</p>
<table>
<tr><th>Host and port</th><th>Result</th><th>Server said</th></tr>
<?php foreach ($probes as $key => $p) { ?>
<tr>
    <td><code><?= h($key) ?></code></td>
    <td><?= $p['ok'] ? '<span class="ok">works</span>' : '<span class="bad">no</span>' ?> <span style="color:#94a3b8"><?= (int) $p['ms'] ?>ms</span></td>
    <td style="font-size:12.5px"><?= h($p['detail']) ?></td>
</tr>
<?php } ?>
</table>
<?php
$working = array_filter($probes, fn ($p) => $p['ok']);
$current = $config !== null ? ($config['host'].':'.$config['port']) : null;
if ($working !== [] && $current !== null && ! ($probes[$current]['ok'] ?? false)) {
    $pick = reset($working);
    ?>
    <div class="note"><strong>Your configured route does not answer, but <code><?= h($pick['host'].':'.$pick['port']) ?></code> does.</strong>
    Edit <code>opes360/.env</code> to use it:
    <pre>MAIL_HOST=<?= h($pick['host'])."\n" ?>MAIL_PORT=<?= h((string) $pick['port'])."\n" ?>MAIL_SCHEME=<?= $pick['port'] === 465 ? 'smtps' : 'tls' ?></pre>
    then delete <code>opes360/bootstrap/cache/config.php</code> if it exists, and reload this page.</div>
<?php } ?>
</div>

<div class="card">
<h2>Test a mailbox password</h2>
<p>This logs in to the mail server directly, so a password can be checked before it goes
anywhere near <code>.env</code>. Nothing is saved.</p>
<form method="post">
    <p><input name="smtp_host" placeholder="opesbusiness.com" value="<?= h($authHost !== '' ? $authHost : ($config['host'] ?? '')) ?>" required>
    <input name="smtp_port" style="min-width:90px" value="<?= h((string) ($authPort ?: 465)) ?>" required></p>
    <p style="margin-top:8px"><input name="smtp_user" placeholder="notifications@opesbusiness.com" value="<?= h($authUser !== '' ? $authUser : ($config['username'] ?? '')) ?>" required>
    <input type="password" name="smtp_pass" placeholder="mailbox password" required>
    <button type="submit">Test login</button></p>
</form>
<?php if ($auth !== null) { ?>
    <?php if ($auth['ok']) { ?>
        <p class="ok" style="margin-top:12px">235 — accepted. This password is correct; put it in <code>.env</code> as
        <code>MAIL_PASSWORD="…"</code> (with the quotes).</p>
    <?php } else { ?>
        <p class="bad" style="margin-top:12px">Rejected. The conversation with the server:</p>
    <?php } ?>
    <pre><?= h(implode("\n", $auth['log'])) ?></pre>
<?php } ?>
</div>

<div class="card">
<h2>Send a real test message</h2>
<form method="get">
    <input type="email" name="to" placeholder="you@gmail.com" value="<?= h($to) ?>" required>
    <button type="submit">Send test</button>
</form>
<p style="font-size:13px;color:#64748b;margin-top:8px">Use a Gmail or Outlook address, not one on your own domain —
mail to your own domain can succeed locally while still being broken for everyone else.</p>
<?php if ($send !== null) { ?>
    <?php if ($send['ok']) { ?>
        <p class="ok" style="margin-top:12px"><?= h($send['detail']) ?></p>
    <?php } else { ?>
        <p class="bad" style="margin-top:12px">Sending failed. The server's own words:</p>
        <pre><?= h($send['detail']) ?></pre>
    <?php } ?>
<?php } ?>
</div>

<?php if ($logTail !== null) { ?>
<div class="card">
<h2>Most recent mail error in the log</h2>
<pre><?= h($logTail) ?></pre>
</div>
<?php } ?>

<div class="card">
<div class="note"><strong>Delete this file</strong> from <code>public_html</code> once mail is working.</div>
</div>

</body></html>
