<?php

namespace Tests\Feature\Automation;

use App\Support\DomainEvents;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every event the code actually emits must be in DomainEvents::CATALOGUE.
 *
 * The catalogue is what the automation and notification rule screens offer,
 * and the runners match on exact name — an uncatalogued event fires into a
 * void where no rule can ever be written against it, and nothing complains.
 * The 2026-08 flow audit found twenty of those (service.*, procurement,
 * contract.*, asset.*); this test is the reason it cannot happen a twenty-first
 * time. Static by design: it greps the source rather than exercising it, so a
 * brand-new emit site is caught the day it is written.
 */
class DomainEventCatalogueParityTest extends TestCase
{
    /**
     * Dynamic emit sites whose names cannot be read off the call. Each one
     * must justify itself here.
     *
     * - WorkflowEngine::announce() forwards a name its callers built; every
     *   name those callers use is a catalogued `workflow.*` literal elsewhere
     *   in the same file.
     *
     * @var array<int, string>
     */
    private const DYNAMIC_EXCEPTIONS = [
        'app/Services/Workflow/WorkflowEngine.php',
    ];

    public function test_every_emitted_event_name_is_catalogued(): void
    {
        $emitted = [];

        foreach (File::allFiles(app_path()) as $file) {
            $contents = $file->getContents();

            if (! str_contains($contents, 'emitDomainEvent')) {
                continue;
            }

            preg_match_all(
                "/emitDomainEvent\\(\\s*'([^']+)'/",
                $contents,
                $matches
            );

            foreach ($matches[1] as $name) {
                $emitted[$name][] = Str::of($file->getPathname())->after(base_path())->ltrim('\\/')->replace('\\', '/')->toString();
            }
        }

        $this->assertNotEmpty($emitted, 'The grep found no emit sites at all — the pattern has rotted.');

        foreach ($emitted as $name => $files) {
            $this->assertTrue(
                DomainEvents::exists($name),
                "`{$name}` is emitted (".implode(', ', array_unique($files)).') but is not in DomainEvents::CATALOGUE — '
                .'no automation or notification rule can ever be written against it. Catalogue it.'
            );
        }
    }

    /** A dynamic call is only tolerable in a file this test has vouched for. */
    public function test_dynamic_emit_calls_only_appear_in_the_documented_exceptions(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            $relative = Str::of($file->getPathname())->after(base_path())->ltrim('\\/')->replace('\\', '/')->toString();

            // The trait defines the method; everything else must call it.
            if ($relative === 'app/Models/Concerns/EmitsDomainEvents.php') {
                continue;
            }

            $contents = $file->getContents();

            preg_match_all('/emitDomainEvent\((?!\s\')[^)]*/', $contents, $dynamic);

            $calls = array_filter($dynamic[0], fn ($c) => ! preg_match("/emitDomainEvent\\(\\s*'/", $c));

            if ($calls !== []) {
                $this->assertContains(
                    $relative,
                    self::DYNAMIC_EXCEPTIONS,
                    "{$relative} calls emitDomainEvent with a non-literal name. Use a literal so the "
                    .'catalogue parity test can see it, or document the exception in this test.'
                );
            }
        }
    }

    /** The four hr.* events stopped being aspirational in Wave 2; keep it so. */
    public function test_the_hr_events_are_actually_emitted_somewhere(): void
    {
        $source = collect(File::allFiles(app_path()))->map->getContents()->implode("\n");

        foreach (DomainEvents::forModule('hr') as $name) {
            $this->assertStringContainsString(
                "emitDomainEvent('{$name}'",
                $source,
                "`{$name}` is catalogued but nothing emits it — a rule written against it never fires."
            );
        }
    }
}
