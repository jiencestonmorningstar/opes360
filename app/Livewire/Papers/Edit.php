<?php

namespace App\Livewire\Papers;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Services\Documents\DocumentComments;
use App\Services\Documents\DocumentVersioner;
use App\Services\Documents\EditLocks;
use App\Services\Documents\HtmlSanitizer;
use App\Services\DocumentComposer;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * The rich editor: free-form writing on a draft document.
 *
 * Compose fills a template; this screen is for the writing that comes after —
 * several people taking turns on the prose. Turns, not keystrokes: one person
 * holds a soft edit-lock and writes, everyone else reads live-ish (poll) and
 * comments. The keystroke-realtime upgrade is documented in
 * docs/handoff/rich-editor.md, not faked here.
 */
class Edit extends Component
{
    use AuthorizesRequests;

    public BusinessDocument $paper;

    /** The editor's HTML. Sanitized server-side on every save — never trusted. */
    public string $body = '';

    public string $title = '';

    /** Whether this session holds the edit lock (read-only otherwise). */
    public bool $editable = false;

    public string $commentBody = '';

    public function mount(BusinessDocument $paper): void
    {
        $this->authorize('view', $paper);

        // An issued document is frozen; the model would throw on save anyway,
        // but the person deserves the refusal before they start typing.
        abort_if($paper->isIssued(), 403, 'This document is issued and cannot be edited.');

        $this->paper = $paper;
        $this->title = $paper->title;
        $this->body = app(DocumentComposer::class)->toHtml((string) $paper->body);

        // Claim the lock if the person may write at all; a viewer without
        // update rights simply gets the read-only screen.
        if (auth()->user()->can('update', $paper)) {
            $this->editable = app(EditLocks::class)->claim($paper, auth()->user());
        }
    }

    /**
     * The wire:poll tick: prove we are still here, or notice we no longer
     * hold the lock (someone took over a stale one while this tab slept).
     */
    public function heartbeat(): void
    {
        $locks = app(EditLocks::class);

        if ($this->editable) {
            $locks->heartbeat($this->paper, auth()->user());
            $this->editable = $locks->heldBy($this->paper, auth()->user());
        } else {
            // A reader polls too, so the banner updates when the writer leaves.
            $this->paper->refresh();
        }
    }

    /** A reader asking for the pen. Granted only when the lock is stale or free. */
    public function requestTakeover(): void
    {
        $this->authorize('update', $this->paper);

        $this->editable = app(EditLocks::class)->claim($this->paper, auth()->user());

        if (! $this->editable) {
            $holder = app(EditLocks::class)->holder($this->paper);
            $this->dispatch('toast', message: ($holder?->name ?? 'Someone').' is still editing. Try again when they pause.');
        }
    }

    /** Hand the pen back without closing the tab. */
    public function releaseLock(): void
    {
        if ($this->editable) {
            app(DocumentVersioner::class)->checkpoint($this->paper->refresh());
            app(EditLocks::class)->release($this->paper, auth()->user());
            $this->editable = false;
        }
    }

    /**
     * The autosave, debounced client-side. Refused without the lock: a save
     * from a tab that lost the pen would overwrite the current writer.
     */
    public function save(): void
    {
        $this->authorize('update', $this->paper);

        $locks = app(EditLocks::class);

        if (! $this->editable || ! $locks->heldBy($this->paper, auth()->user())) {
            $this->editable = false;
            $this->addError('body', 'You no longer hold the editing lock, so this change was not saved.');

            return;
        }

        $this->validate(
            ['title' => ['required', 'string', 'max:160']],
            ['title.required' => 'Give the document a name you will recognise later.'],
        );

        $clean = app(HtmlSanitizer::class)->clean($this->body);
        $this->body = $clean;

        // Throttled versioning: the save always lands, the version history
        // gains a row at most every few minutes — see DocumentVersioner.
        app(DocumentVersioner::class)->withAutosaveCadence(
            $this->paper,
            fn () => $this->paper->update(['title' => trim($this->title), 'body' => $clean]),
        );

        $locks->heartbeat($this->paper, auth()->user());
    }

    /** The editor closing cleanly: capture the tail of the session, free the pen. */
    public function close(): void
    {
        $this->releaseLock();
        $this->redirectRoute('papers.show', $this->paper);
    }

    public function restoreVersion(string $versionId): void
    {
        $this->authorize('update', $this->paper);

        abort_unless($this->editable, 403, 'Take over editing before restoring a version.');

        $version = BusinessDocumentVersion::query()
            ->where('business_document_id', $this->paper->id)
            ->findOrFail($versionId);

        app(DocumentVersioner::class)->restore($this->paper, $version, auth()->user());

        $this->paper->refresh();
        $this->title = $this->paper->title;
        $this->body = app(DocumentComposer::class)->toHtml((string) $this->paper->body);
        $this->dispatch('editor-set-content', html: $this->body);
    }

    public function postComment(): void
    {
        $this->authorize('view', $this->paper);
        $this->validate(['commentBody' => ['required', 'string', 'max:2000']]);

        app(DocumentComments::class)->post($this->paper, auth()->user(), trim($this->commentBody));

        $this->commentBody = '';
        $this->paper->refresh();
    }

    public function render(): View
    {
        $holder = app(EditLocks::class)->holder($this->paper);

        return view('livewire.papers.edit', [
            'holder' => $holder,
            'versions' => $this->paper->versions()->orderByDesc('version_number')->with('creator')->limit(30)->get(),
            'comments' => $this->paper->comments()->whereNull('parent_id')->with('author')->orderByDesc('created_at')->limit(50)->get(),
        ])->layout('components.layouts.app', [
            'title' => 'Edit '.$this->paper->title,
            'active' => 'papers',
            'bottomNav' => false,
        ]);
    }
}
