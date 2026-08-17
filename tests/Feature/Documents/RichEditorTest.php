<?php

namespace Tests\Feature\Documents;

use App\Livewire\Papers\Edit;
use App\Models\Role;
use App\Services\DocumentComposer;
use App\Services\Documents\DocumentVersioner;
use App\Services\Documents\EditLocks;
use App\Services\Documents\HtmlSanitizer;
use Livewire\Livewire;

class RichEditorTest extends DocumentsTestCase
{
    /* ------------------------------------------------------------------ *
     * Sanitizer
     * ------------------------------------------------------------------ */

    public function test_sanitizer_keeps_the_toolbar_vocabulary(): void
    {
        $html = '<h1>Title</h1><p>Some <strong>bold</strong>, <em>italic</em>, <u>underlined</u> text.</p>'
            .'<ul><li>One</li></ul><ol><li>Two</li></ol><blockquote><p>Quote</p></blockquote><hr>'
            .'<table><tbody><tr><td colspan="2">Cell</td></tr></tbody></table>';

        $this->assertSame($html, app(HtmlSanitizer::class)->clean($html));
    }

    public function test_sanitizer_neutralises_script_payloads(): void
    {
        $clean = app(HtmlSanitizer::class)->clean(
            '<p>Hello</p><script>alert(1)</script><style>*{display:none}</style><iframe src="https://evil"></iframe>',
        );

        $this->assertSame('<p>Hello</p>', $clean);
    }

    public function test_sanitizer_strips_event_handlers_and_unknown_attributes(): void
    {
        $clean = app(HtmlSanitizer::class)->clean(
            '<p onclick="alert(1)" style="color:red" data-x="y">Hi <img src=x onerror=alert(1)></p>',
        );

        $this->assertSame('<p>Hi </p>', $clean);
    }

    public function test_sanitizer_refuses_javascript_and_data_urls_but_hardens_real_links(): void
    {
        $clean = app(HtmlSanitizer::class)->clean(
            '<p><a href="javascript:alert(1)">bad</a> <a href="data:text/html,x">worse</a> <a href="https://example.com">good</a></p>',
        );

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('data:', $clean);
        $this->assertStringContainsString(
            '<a href="https://example.com" rel="noopener noreferrer" target="_blank">good</a>',
            $clean,
        );
    }

    public function test_sanitizer_unwraps_unknown_tags_but_keeps_their_text(): void
    {
        $this->assertSame(
            '<p>kept text</p>',
            app(HtmlSanitizer::class)->clean('<p><span class="x">kept</span> <marquee>text</marquee></p>'),
        );
    }

    /* ------------------------------------------------------------------ *
     * Rendering: HTML bodies pass through; template prose is unaffected
     * ------------------------------------------------------------------ */

    public function test_to_html_passes_editor_html_through_sanitized(): void
    {
        $this->assertSame(
            '<h2>Terms</h2><p>Agreed.</p>',
            app(DocumentComposer::class)->toHtml('<h2>Terms</h2><p>Agreed.</p><script>x()</script>'),
        );
    }

    public function test_to_html_still_converts_plain_template_prose(): void
    {
        $this->assertSame(
            "<h1>Title</h1>\n<p>Paragraph.</p>",
            app(DocumentComposer::class)->toHtml("# Title\n\nParagraph."),
        );
    }

    public function test_print_view_renders_an_html_body(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document(['body' => '<h2>Terms</h2><p>Printed prose.</p>']);

        $this->get(route('papers.print', $paper))
            ->assertOk()
            ->assertSee('Printed prose.');
    }

    /* ------------------------------------------------------------------ *
     * Lock lifecycle
     * ------------------------------------------------------------------ */

    public function test_opening_the_editor_claims_the_lock(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();

        Livewire::test(Edit::class, ['paper' => $paper])
            ->assertSet('editable', true);

        $this->assertSame($this->owner->id, $paper->fresh()->editing_user_id);
    }

    public function test_second_person_gets_read_only_and_a_refused_save(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        app(EditLocks::class)->claim($paper, $this->owner);

        $admin = $this->memberAt(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        Livewire::test(Edit::class, ['paper' => $paper->fresh()])
            ->assertSet('editable', false)
            ->assertSee($this->owner->name.' is editing')
            ->set('body', '<p>overwrite attempt</p>')
            ->call('save')
            ->assertHasErrors('body');

        $this->assertSame('The agreed terms.', $paper->fresh()->body);
    }

    public function test_a_stale_lock_is_claimable(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        app(EditLocks::class)->claim($paper, $this->owner);

        $admin = $this->memberAt(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        $this->travel(EditLocks::STALE_SECONDS + 1)->seconds();

        Livewire::test(Edit::class, ['paper' => $paper->fresh()])
            ->assertSet('editable', true);

        $this->assertSame($admin->id, $paper->fresh()->editing_user_id);
    }

    public function test_takeover_is_refused_while_the_lock_is_live(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        app(EditLocks::class)->claim($paper, $this->owner);

        $admin = $this->memberAt(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        Livewire::test(Edit::class, ['paper' => $paper->fresh()])
            ->call('requestTakeover')
            ->assertSet('editable', false);

        $this->assertSame($this->owner->id, $paper->fresh()->editing_user_id);
    }

    public function test_releasing_the_lock_frees_the_document(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();

        Livewire::test(Edit::class, ['paper' => $paper])
            ->call('releaseLock')
            ->assertSet('editable', false);

        $this->assertNull($paper->fresh()->editing_user_id);
    }

    public function test_heartbeat_keeps_the_lock_alive(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        $locks = app(EditLocks::class);
        $locks->claim($paper, $this->owner);

        $this->travel(60)->seconds();
        $locks->heartbeat($paper, $this->owner);
        $this->travel(60)->seconds();

        // 120s since claim, 60s since last beat: still live.
        $this->assertTrue($locks->heldBy($paper->fresh(), $this->owner));
    }

    /* ------------------------------------------------------------------ *
     * Saving and autosave/version cadence
     * ------------------------------------------------------------------ */

    public function test_saving_stores_sanitized_html(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();

        Livewire::test(Edit::class, ['paper' => $paper])
            ->set('body', '<p>New terms</p><script>alert(1)</script>')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<p>New terms</p>', $paper->fresh()->body);
    }

    public function test_autosave_burst_mints_at_most_one_version(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document(); // v1 on create

        // The creation snapshot anchors the window too; age past it so the
        // burst starts on a cold history.
        $this->travel(DocumentVersioner::AUTOSAVE_WINDOW_SECONDS + 1)->seconds();

        $editor = Livewire::test(Edit::class, ['paper' => $paper]);

        $editor->set('body', '<p>First pass</p>')->call('save');
        $this->travel(30)->seconds();
        $editor->set('body', '<p>Second pass</p>')->call('save');
        $this->travel(30)->seconds();
        $editor->set('body', '<p>Third pass</p>')->call('save');

        // One burst of autosaves inside the window: exactly one new version.
        $this->assertSame(2, $paper->fresh()->versions()->count());
    }

    public function test_a_new_version_is_minted_after_the_window(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        $this->travel(DocumentVersioner::AUTOSAVE_WINDOW_SECONDS + 1)->seconds();

        $editor = Livewire::test(Edit::class, ['paper' => $paper]);
        $editor->set('body', '<p>Morning draft</p>')->call('save'); // mints v2

        $this->travel(DocumentVersioner::AUTOSAVE_WINDOW_SECONDS + 1)->seconds();
        $editor->set('body', '<p>Afternoon draft</p>')->call('save');

        $this->assertSame(3, $paper->fresh()->versions()->count());
    }

    public function test_closing_the_editor_checkpoints_the_tail_of_the_session(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document();
        $this->travel(DocumentVersioner::AUTOSAVE_WINDOW_SECONDS + 1)->seconds();

        $editor = Livewire::test(Edit::class, ['paper' => $paper]);
        $editor->set('body', '<p>First pass</p>')->call('save'); // mints v2
        $editor->set('body', '<p>Final wording</p>')->call('save'); // throttled

        $editor->call('close');

        $latest = $paper->fresh()->versions()->orderByDesc('version_number')->first();
        $this->assertSame('<p>Final wording</p>', $latest->body);
        $this->assertNull($paper->fresh()->editing_user_id);
    }

    /* ------------------------------------------------------------------ *
     * Immutability and existing behaviour preserved
     * ------------------------------------------------------------------ */

    public function test_an_issued_document_cannot_be_opened_in_the_editor(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document(['status' => 'issued', 'issued_at' => now()]);

        Livewire::test(Edit::class, ['paper' => $paper])
            ->assertStatus(403);
    }

    public function test_restore_from_the_sidebar_needs_the_lock(): void
    {
        $this->actingAs($this->owner);
        $paper = $this->document(['title' => 'One']);
        $paper->update(['title' => 'Two']); // v2
        $v1 = $paper->versions()->where('version_number', 1)->first();

        $editor = Livewire::test(Edit::class, ['paper' => $paper->fresh()]);
        $editor->call('restoreVersion', $v1->id);

        $fresh = $paper->fresh();
        $this->assertSame('One', $fresh->title);
        $this->assertSame(3, $fresh->versions()->count()); // restore is its own version
    }
}
