<?php

namespace App\Livewire\Papers;

use App\Models\BusinessDocument;
use App\Services\DocumentComposer;
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

    public function render(): View
    {
        return view('livewire.papers.show', [
            'bodyHtml' => app(DocumentComposer::class)->toHtml($this->paper->body),
            'notice' => ($this->paper->template()['binding'] ?? false)
                ? DocumentTemplates::reviewNotice()
                : null,
        ])->layout('components.layouts.app', [
            'title' => $this->paper->title,
            'active' => 'papers',
        ]);
    }
}
