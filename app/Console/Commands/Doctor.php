<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Pre-flight check for a deployment.
 *
 * Every item here is something that looks fine in a browser and is discovered
 * later at the worst moment: mail that silently goes to a log file, a queue
 * with no worker so reset links are never sent at all, debug mode leaking stack
 * traces to customers. Run it after deploying, before letting anyone in.
 */
class Doctor extends Command
{
    protected $signature = 'opes:doctor {--mail= : Send a test message to this address}';

    protected $description = 'Check that this environment is configured to run in production';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    protected array $results = [];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=white;options=bold>'.config('opes.brand.name').' deployment check</>');
        $this->newLine();

        $this->checkApp();
        $this->checkDatabase();
        $this->checkMail();
        $this->checkQueue();
        $this->checkStorage();

        if ($address = $this->option('mail')) {
            $this->sendTestMail($address);
        }

        return $this->report();
    }

    protected function checkApp(): void
    {
        $production = app()->environment('production');

        $this->assert(
            'App key set',
            config('app.key') !== null && config('app.key') !== '',
            'Run `php artisan key:generate`. Without it, every encrypted tax ID and 2FA secret is unreadable.',
        );

        $this->assert(
            'Debug mode off',
            ! config('app.debug'),
            'APP_DEBUG=true shows stack traces — including database credentials — to whoever triggers an error.',
            warnOnly: ! $production,
        );

        $this->assert(
            'HTTPS enforced',
            str_starts_with((string) config('app.url'), 'https://'),
            'APP_URL must be https in production: session cookies and the sync API ride on it.',
            warnOnly: ! $production,
        );

        $this->assert(
            'Session cookies encrypted at rest',
            config('session.driver') !== 'array',
            'A non-persistent session driver logs everyone out on every deploy.',
        );
    }

    protected function checkDatabase(): void
    {
        try {
            $driver = DB::connection()->getDriverName();

            DB::connection()->getPdo();

            $this->assert('Database reachable', true, '');

            $this->assert(
                'Running on MySQL',
                $driver === 'mysql',
                "Connected via [{$driver}]. The product is written for MySQL 8.4; other drivers are untested in production.",
                warnOnly: true,
            );

            if ($driver === 'mysql') {
                $mode = (string) DB::selectOne('SELECT @@sql_mode AS mode')->mode;

                $this->assert(
                    'Strict SQL mode',
                    str_contains($mode, 'STRICT_TRANS_TABLES'),
                    'Without STRICT_TRANS_TABLES, MySQL silently truncates over-long values instead of refusing them.',
                );
            }
        } catch (Throwable $e) {
            $this->assert('Database reachable', false, $e->getMessage());
        }
    }

    protected function checkMail(): void
    {
        $mailer = config('mail.default');

        $this->assert(
            'Mail transport configured',
            ! in_array($mailer, ['log', 'array'], true),
            "MAIL_MAILER is [{$mailer}] — password reset links go to the log file, not to users.",
        );

        $from = config('mail.from.address');

        $this->assert(
            'From address set',
            filled($from) && ! str_contains((string) $from, 'example.com'),
            'MAIL_FROM_ADDRESS is still a placeholder. It must be an address on a domain you control, with SPF and DKIM published, or mail lands in spam.',
        );
    }

    protected function checkQueue(): void
    {
        $connection = config('queue.default');

        $this->assert(
            'Queue is not synchronous',
            $connection !== 'sync',
            'QUEUE_CONNECTION=sync sends mail inside the web request, which makes the password-reset endpoint leak whether an address exists through its response time.',
            warnOnly: true,
        );

        if ($connection === 'database') {
            try {
                $pending = DB::table('jobs')->count();
                $failed = DB::table('failed_jobs')->count();

                // A backlog on a queue that is meant to drain in seconds means
                // no worker is running — the single most common way a correctly
                // configured mail setup still delivers nothing.
                $this->assert(
                    'Queue is being drained',
                    $pending < 100,
                    "{$pending} jobs are waiting. Start a worker: `php artisan queue:work --tries=3`.",
                    warnOnly: $pending < 500,
                );

                $this->assert(
                    'No failed jobs',
                    $failed === 0,
                    "{$failed} failed jobs. Inspect with `php artisan queue:failed`.",
                    warnOnly: true,
                );
            } catch (Throwable) {
                $this->assert('Queue tables migrated', false, 'Run `php artisan migrate`.');
            }
        }
    }

    protected function checkStorage(): void
    {
        $link = public_path('storage');

        $this->assert(
            'Public storage linked',
            file_exists($link),
            'Run `php artisan storage:link`, or uploaded logos and avatars 404.',
        );

        $this->assert(
            'Storage writable',
            is_writable(storage_path('app')),
            'The web user cannot write to storage/app — uploads and generated PDFs will fail.',
        );
    }

    protected function sendTestMail(string $address): void
    {
        try {
            Mail::raw(
                'This is a test message from '.config('opes.brand.name').". If you are reading it, mail delivery works.\n\nSent ".now()->toDayDateTimeString().'.',
                fn ($message) => $message->to($address)->subject(config('opes.brand.name').' — mail test'),
            );

            $this->assert("Test mail sent to {$address}", true, '');
        } catch (Throwable $e) {
            $this->assert("Test mail sent to {$address}", false, $e->getMessage());
        }
    }

    protected function assert(string $label, bool $passed, string $remedy, bool $warnOnly = false): void
    {
        $status = $passed ? 'pass' : ($warnOnly ? 'warn' : 'fail');

        $this->results[] = [$status, $label, $remedy];

        $icon = match ($status) {
            'pass' => '<fg=green>✓</>',
            'warn' => '<fg=yellow>!</>',
            'fail' => '<fg=red>✗</>',
        };

        $this->line("  {$icon} {$label}");

        if (! $passed && $remedy !== '') {
            $this->line("      <fg=gray>{$remedy}</>");
        }
    }

    protected function report(): int
    {
        $failed = collect($this->results)->where(0, 'fail')->count();
        $warned = collect($this->results)->where(0, 'warn')->count();

        $this->newLine();

        if ($failed > 0) {
            $this->line("  <fg=red;options=bold>{$failed} blocking issue".($failed === 1 ? '' : 's').'</> — do not let users in yet.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line($warned > 0
            ? "  <fg=yellow;options=bold>Ready, with {$warned} warning".($warned === 1 ? '' : 's').'.</>'
            : '  <fg=green;options=bold>Ready.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
