<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/*
 * Cron helper for shared hosting.
 *
 * The cron lines in the guide contain two things this file cannot know in
 * advance: the absolute path to the account's home directory, and which of the
 * several PHP binaries a cPanel machine carries is the right one. Getting the
 * second wrong is the usual reason a cron job silently does nothing — the only
 * symptom being that no email ever arrives.
 *
 * So: visit this page, and it works both out, tests them, and prints the exact
 * lines to paste into cPanel. It also reports what the mail queue is doing,
 * since that is the other half of the same problem.
 *
 * Delete it when the cron jobs are running. It reveals paths and configuration
 * (never passwords), which is no use to anyone but is no use to you either once
 * the job is set up.
 */

// ---------------------------------------------------------------- find the app
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

/** Runs a command if the host allows it; many shared hosts disable exec(). */
function run(string $command): ?string
{
    foreach (['shell_exec', 'exec'] as $fn) {
        if (! function_exists($fn) || in_array($fn, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            continue;
        }

        if ($fn === 'shell_exec') {
            $out = @shell_exec($command.' 2>&1');

            return $out === null ? null : trim($out);
        }

        @exec($command.' 2>&1', $lines);

        return trim(implode("\n", $lines));
    }

    return null;
}

$canRun = run('echo ok') === 'ok';

// ---------------------------------------------------------------- find PHP
/*
 * The web request runs under php-fpm, whose PHP_BINARY is not the CLI binary
 * cron needs, so that is a last resort rather than the answer. These are the
 * paths cPanel actually uses, most specific first.
 */
$series = ['84', '83', '82'];
$candidates = ['/usr/local/bin/php'];

foreach ($series as $v) {
    $candidates[] = "/opt/cpanel/ea-php{$v}/root/usr/bin/php";
    $candidates[] = "/usr/local/bin/ea-php{$v}";
}

$candidates[] = '/usr/bin/php';
$candidates[] = '/usr/local/cpanel/3rdparty/bin/php';

if (defined('PHP_BINARY') && PHP_BINARY !== '') {
    $candidates[] = PHP_BINARY;
}

$found = [];

foreach (array_unique($candidates) as $path) {
    if (! is_file($path)) {
        continue;
    }

    $version = null;
    $usable = null;

    if ($canRun) {
        $out = run(escapeshellarg($path)." -r 'echo PHP_VERSION;'");
        if ($out !== null && preg_match('/^\d+\.\d+\.\d+/', $out, $m)) {
            $version = $m[0];
        }

        // The real test is not "does php run" but "does it run this app".
        if ($root !== null && $version !== null) {
            $probe = run('cd '.escapeshellarg($root).' && '.escapeshellarg($path).' artisan --version');
            $usable = $probe !== null && stripos($probe, 'Laravel') !== false;
        }
    }

    $found[$path] = ['version' => $version, 'usable' => $usable];
}

// The best candidate: one proven to run artisan, else the newest that runs.
$best = null;
foreach ($found as $path => $info) {
    if ($info['usable'] === true) {
        $best = $path;
        break;
    }
}
if ($best === null) {
    foreach ($found as $path => $info) {
        if ($info['version'] !== null) {
            $best = $path;
            break;
        }
    }
}
$best ??= array_key_first($found) ?: '/usr/local/bin/php';

// ---------------------------------------------------------------- queue + mail
$queue = null;
$mail = null;

if ($root !== null && is_file($root.'/vendor/autoload.php') && is_file($root.'/.env')) {
    try {
        require_once $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $mail = [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'scheme' => config('mail.mailers.smtp.scheme') ?: '(not set)',
            'username' => config('mail.mailers.smtp.username'),
            'from' => config('mail.from.address'),
        ];

        $queue = [
            'pending' => DB::table('jobs')->count(),
            'failed' => DB::table('failed_jobs')->count(),
            'last_error' => DB::table('failed_jobs')
                ->orderByDesc('id')->value('exception'),
        ];
    } catch (Throwable $e) {
        $queue = ['error' => $e->getMessage()];
    }
}

$cronApp = $root ?? '/home/YOURUSER/opes360';
$scheduler = "cd {$cronApp} && {$best} artisan schedule:run >> /dev/null 2>&1";
$worker = "cd {$cronApp} && {$best} artisan queue:work --stop-when-empty --tries=3 --max-time=50 >> /dev/null 2>&1";
$doctor = "cd {$cronApp} && {$best} artisan opes:doctor --mail=YOUR-EMAIL@gmail.com";

?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cron setup · OPES360</title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;background:#eef2f7;color:#0f172a;padding:32px 16px}
.card{max-width:820px;margin:0 auto 18px;background:#fff;border-radius:14px;padding:26px;box-shadow:0 4px 24px rgba(15,23,42,.10)}
h1{font-size:21px;font-weight:800;letter-spacing:-.02em}
h2{font-size:15px;font-weight:700;margin:22px 0 8px}
p{margin-top:8px}
pre{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:9px;overflow:auto;font-size:13px;margin-top:8px;white-space:pre-wrap;word-break:break-all}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:13.5px}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13.5px}
th,td{text-align:left;padding:7px 9px;border-bottom:1px solid #e2e8f0}
th{color:#64748b;font-weight:600}
.ok{color:#15803d;font-weight:700}.bad{color:#b91c1c;font-weight:700}.warn{color:#b45309;font-weight:700}
.note{background:#f8fafc;border-left:3px solid #cbd5e1;padding:10px 14px;margin-top:12px;font-size:13.5px}
</style></head><body>

<div class="card">
<h1>Cron setup</h1>
<?php if ($root === null) { ?>
    <p class="bad">Could not find the application. This file belongs in <code>public_html</code>,
    with the application in <code>opes360</code> beside it.</p>
<?php } else { ?>
    <p>Application: <code><?= h($root) ?></code></p>
    <p>This page runs as PHP <?= h(PHP_VERSION) ?> under the web server.
    <?= $canRun
        ? 'Commands can be tested from here, so the paths below are verified rather than guessed.'
        : '<strong>This host blocks running commands from PHP</strong>, so the binaries below are listed by existence only — try the first one and check the cron output.' ?></p>
<?php } ?>
</div>

<div class="card">
<h2>PHP binaries on this server</h2>
<?php if ($found === []) { ?>
    <p class="bad">None of the usual paths exist. Ask Namecheap support for the CLI PHP path for your account.</p>
<?php } else { ?>
<table>
<tr><th>Path</th><th>Version</th><th>Runs this app</th></tr>
<?php foreach ($found as $path => $info) { ?>
<tr>
    <td><code><?= h($path) ?></code></td>
    <td><?= $info['version'] ? h($info['version']) : '<span class="warn">unknown</span>' ?></td>
    <td><?php
        echo $info['usable'] === true ? '<span class="ok">yes</span>'
            : ($info['usable'] === false ? '<span class="bad">no</span>' : '<span class="warn">not testable</span>');
    ?></td>
</tr>
<?php } ?>
</table>
<p style="margin-top:14px">Using: <code><?= h($best) ?></code></p>
<?php } ?>
</div>

<div class="card">
<h2>Paste these into cPanel → Cron Jobs</h2>
<p>Set <strong>Common Settings</strong> to <em>Once Per Minute</em> for both. If your plan
does not allow every minute, use every 5 minutes — only the mail delay grows.</p>

<h2>1. Queue worker — <span class="bad">this is the one that sends your email</span></h2>
<pre><?= h($worker) ?></pre>

<h2>2. Scheduler — renewal reminders, low-stock alerts, lease expiry</h2>
<pre><?= h($scheduler) ?></pre>

<div class="note">Both end with <code>&gt;&gt; /dev/null 2&gt;&amp;1</code> so cPanel does not email you
every minute. While you are still setting up, delete that part from the end and cPanel will
email you whatever the command prints — which is how you find out it is failing.</div>
</div>

<?php if ($mail !== null) { ?>
<div class="card">
<h2>Mail configuration in use</h2>
<table>
<tr><th>Transport</th><td><?= $mail['mailer'] === 'smtp'
    ? '<span class="ok">smtp</span>'
    : '<span class="bad">'.h((string) $mail['mailer']).' — nothing will be delivered</span>' ?></td></tr>
<tr><th>Host</th><td><code><?= h((string) $mail['host']) ?></code></td></tr>
<tr><th>Port</th><td><code><?= h((string) $mail['port']) ?></code></td></tr>
<tr><th>Scheme</th><td><?= $mail['port'] == 465 && $mail['scheme'] !== 'smtps'
    ? '<span class="bad">'.h((string) $mail['scheme']).' — port 465 needs smtps, or the server drops the connection</span>'
    : '<code>'.h((string) $mail['scheme']).'</code>' ?></td></tr>
<tr><th>Username</th><td><code><?= h((string) $mail['username']) ?></code></td></tr>
<tr><th>From</th><td><code><?= h((string) $mail['from']) ?></code></td></tr>
</table>
<p style="margin-top:10px;font-size:13px;color:#64748b">The password is never shown here.</p>
</div>
<?php } ?>

<?php if ($queue !== null && ! isset($queue['error'])) { ?>
<div class="card">
<h2>The mail queue right now</h2>
<table>
<tr><th>Waiting to send</th><td><?= $queue['pending'] > 0
    ? '<span class="warn">'.(int) $queue['pending'].' — queued but not sent, so the worker cron is missing or failing</span>'
    : '<span class="ok">0</span>' ?></td></tr>
<tr><th>Failed</th><td><?= $queue['failed'] > 0
    ? '<span class="bad">'.(int) $queue['failed'].'</span>'
    : '<span class="ok">0</span>' ?></td></tr>
</table>
<?php if ($queue['pending'] == 0 && $queue['failed'] == 0) { ?>
    <p style="margin-top:10px">Nothing queued and nothing failed. If you were expecting a message,
    either it was already sent, or the action that sends it has not been performed since the app
    was configured.</p>
<?php } ?>
<?php if (! empty($queue['last_error'])) { ?>
    <h2>Why the last one failed</h2>
    <pre><?= h(substr((string) $queue['last_error'], 0, 1500)) ?></pre>
<?php } ?>
</div>
<?php } ?>

<div class="card">
<h2>Test mail end to end</h2>
<p>Add this as a one-off cron with <strong>no</strong> <code>&gt;&gt; /dev/null</code>, let it run once,
then delete it. cPanel emails you the result, which names anything still wrong:</p>
<pre><?= h($doctor) ?></pre>
<p>Send it to a Gmail address rather than one on your own domain — mail to your own
domain can succeed locally while still being broken for everyone else.</p>
<div class="note"><strong>Delete this file</strong> from <code>public_html</code> once the cron jobs
are running.</div>
</div>

</body></html>
