<?php

namespace App\Livewire\Papers;

use App\Models\BusinessDocument;
use App\Services\DocumentComposer;
use App\Services\Documents\DocumentActivity;
use App\Services\Documents\DocumentSignatureRequests;
use App\Support\DocumentTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use RuntimeException;

class Show extends Component
{
    use AuthorizesRequests;

    public BusinessDocument $paper;

    public bool $voidingOpen = false;

    public string $voidReason = '';

    // ── Request signatures — §53 said this stayed API-only; it does not anymore ──

    public bool $signatureFormOpen = false;

    public string $signatureMode = 'parallel';

    /** @var array<int, array{name: string, email: string}> */
    public array $signers = [['name' => '', 'email' => '']];

    public function mount(BusinessDocument $paper): void
    {
        $this->paper = $paper->load('verificationToken');
    }

    public function issue(): void
    {
        $this->authorize('papers.issue');

        try {
            app(DocumentComposer::class)->issue($this->paper, auth()->user());
        } catch (RuntimeException $e) {
            session()->flash('paperError', $e->getMessage());

            return;
        }

        $this->paper = $this->paper->fresh()->load('verificationToken');
    }

    /**
     * §5 item 1 of the Documents completion plan: a fresh draft that starts
     * from this document's content, through DocumentComposer::duplicate() —
     * never a second creation path.
     */
    public function duplicate(): void
    {
        $this->authorize('papers.create');

        $copy = app(DocumentComposer::class)->duplicate($this->paper, auth()->user());

        $this->redirectRoute('papers.edit', $copy);
    }

    /** Sought before issuing; the engine's verdict shows on this screen. */
    public function submitForApproval(): void
    {
        $this->authorize('update', $this->paper);

        try {
            app(DocumentComposer::class)->submitForApproval($this->paper, auth()->user());
        } catch (RuntimeException $e) {
            session()->flash('paperError', $e->getMessage());

            return;
        }

        $this->paper = $this->paper->fresh()->load('verificationToken');
        $this->dispatch('toast', message: 'Sent for approval. It will show up in the approver\'s actions.');
    }

    public function openVoid(): void
    {
        $this->voidingOpen = true;
        $this->resetErrorBag();
    }

    public function closeVoid(): void
    {
        $this->voidingOpen = false;
    }

    public function voidPaper(): void
    {
        $this->authorize('papers.void');

        try {
            app(DocumentComposer::class)->void(
                $this->paper,
                auth()->user(),
                $this->voidReason !== '' ? $this->voidReason : null,
            );
        } catch (RuntimeException $e) {
            $this->addError('voidReason', $e->getMessage());

            return;
        }

        $this->reset('voidingOpen', 'voidReason');
        $this->paper = $this->paper->fresh()->load('verificationToken');
    }

    public function openSignatureForm(): void
    {
        $this->authorize('share', $this->paper);

        $this->signatureFormOpen = true;
        $this->signatureMode = 'parallel';
        $this->signers = [['name' => '', 'email' => '']];
        $this->resetErrorBag();
    }

    public function closeSignatureForm(): void
    {
        $this->signatureFormOpen = false;
    }

    public function addSigner(): void
    {
        $this->signers[] = ['name' => '', 'email' => ''];
    }

    public function removeSigner(int $index): void
    {
        unset($this->signers[$index]);
        $this->signers = array_values($this->signers);

        if ($this->signers === []) {
            $this->signers = [['name' => '', 'email' => '']];
        }
    }

    public function requestSignatures(): void
    {
        $this->authorize('share', $this->paper);

        $this->validate([
            'signatureMode' => ['required', 'in:'.implode(',', DocumentSignatureRequests::MODES)],
            'signers' => ['required', 'array', 'min:1'],
            'signers.*.name' => ['required', 'string', 'max:120'],
            'signers.*.email' => ['required', 'email', 'max:190'],
        ]);

        try {
            app(DocumentSignatureRequests::class)->request(
                $this->paper,
                array_values($this->signers),
                $this->signatureMode,
            );
        } catch (RuntimeException $e) {
            session()->flash('paperError', $e->getMessage());

            return;
        }

        $this->signatureFormOpen = false;
        $this->paper = $this->paper->fresh()->load('verificationToken');
        $this->dispatch('toast', message: 'Signature request sent.');
    }

    public function render(): View
    {
        /*
         * §53 side panel: everything below was already built as a service
         * or a relation for the API layer (DocumentActivity, the version/
         * comment/share/signature relations, the retention flags on the
         * model) — this is the audit's finding that Show.php simply never
         * rendered any of it. Wiring it here is read-only and cheap; a
         * write UI for shares/signatures/retention stays out (§53 lists
         * the panel, not new write flows, and each of those already has
         * one via its own screen or API).
         */
        return view('livewire.papers.show', [
            // A draft's chips re-resolve on every view — that is the whole
            // point of a "living document" (§3.3). An issued document is a
            // frozen legal snapshot; showing it with a chip re-resolved to a
            // value that postdates the signature would contradict the very
            // guarantee issuance exists to make, so this stays exactly as
            // rendered before this feature existed once isIssued() is true.
            'bodyHtml' => app(DocumentComposer::class)->toHtml(
                $this->paper->body,
                $this->paper->isIssued() ? null : $this->paper,
            ),
            'notice' => ($this->paper->template()['binding'] ?? false)
                ? DocumentTemplates::reviewNotice()
                : null,
            'activity' => auth()->user()?->can('papers.manage')
                ? app(DocumentActivity::class)->timeline($this->paper)->sortByDesc('at')->take(8)->values()
                : collect(),
            'versionCount' => $this->paper->versions()->count(),
            'commentCount' => $this->paper->comments()->count(),
            'shares' => $this->paper->shares()->latest()->limit(5)->get(),
            'signatures' => $this->paper->signatures()->latest()->limit(5)->get(),
            'signatureStatus' => app(DocumentSignatureRequests::class)->status($this->paper),
        ])->layout('components.layouts.app', [
            'title' => $this->paper->title,
            'active' => 'papers',
        ]);
    }
}
