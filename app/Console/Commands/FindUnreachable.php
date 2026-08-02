<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pages that exist and that nothing links to.
 *
 * A feature nobody can reach is a feature that does not exist, and this
 * codebase has produced that failure four times now: credit notes were an enum
 * case with no button, invitations were a column with no form, the secretariat
 * demo account was seeded in full and never offered on the login page, and
 * customer editing had a route, a form and a policy with no link anywhere.
 * Every one of them was found by accident.
 *
 * Nothing in a test suite catches this. A route that answers 200 passes every
 * check you can write about it while being unreachable by a human, because the
 * test knows the URL and the human does not.
 *
 *     php artisan opes:unreachable
 *
 * ── Reading the output ───────────────────────────────────────────────────
 *
 * It reports GET routes whose *name* appears nowhere outside the route file.
 * That is a starting point, not a verdict, and there are two honest reasons a
 * route can be listed and be fine:
 *
 *   · it is reached by a URL built somewhere else — `$event->publicUrl()`
 *     rather than `route('event.public', …)`
 *   · it is infrastructure a browser requests directly: the service worker,
 *     storage, Livewire's own endpoints
 *
 * So the list wants reading, not obeying. It is short enough to read.
 */
class FindUnreachable extends Command
{
    protected $signature = 'opes:unreachable';

    protected $description = 'List GET routes that nothing in the codebase links to';

    /** Requested by the browser or the framework, never by a link. */
    protected const INFRASTRUCTURE = [
        'service-worker', 'storage.local', 'livewire.preview-file',
        'livewire.update', 'livewire.upload-file',
    ];

    public function handle(): int
    {
        $sources = $this->sources();
        $navigation = collect(config('opes.navigation'))->pluck('route')
            ->merge(collect(config('opes.quick_actions'))->pluck('route'))
            ->filter()->all();

        $orphans = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($name, self::INFRASTRUCTURE, true) || in_array($name, $navigation, true)) {
                continue;
            }

            $referenced = false;

            foreach ($sources as $body) {
                if (str_contains($body, "'{$name}'") || str_contains($body, "\"{$name}\"")) {
                    $referenced = true;
                    break;
                }
            }

            if (! $referenced) {
                $orphans[] = [
                    $name,
                    $route->uri(),
                    collect($route->gatherMiddleware())
                        ->filter(fn ($m) => is_string($m) && (str_starts_with($m, 'can:') || $m === 'auth'))
                        ->implode(' '),
                ];
            }
        }

        if ($orphans === []) {
            $this->info('Every named GET route is linked from somewhere.');

            return self::SUCCESS;
        }

        $this->table(['Route', 'URI', 'Guard'], $orphans);

        $this->newLine();
        $this->line(sprintf('  %d of the named GET routes are not referenced by name.', count($orphans)));
        $this->line('  Some will be reached by a URL built elsewhere — check before acting.');

        // Not a failure: the list is advisory and always has a few honest
        // entries. Failing the build on it would train people to ignore it.
        return self::SUCCESS;
    }

    /**
     * Everything a link could plausibly live in, minus the route file itself —
     * a route naming itself is not a reference to it.
     *
     * @return array<int, string>
     */
    protected function sources(): array
    {
        $out = [];

        foreach (['app', 'resources/views', 'resources/js', 'config', 'database/seeders'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if ($file->isDir() || ! preg_match('/\.(php|js)$/', $file->getPathname())) {
                    continue;
                }

                $out[] = file_get_contents($file->getPathname());
            }
        }

        return $out;
    }
}
