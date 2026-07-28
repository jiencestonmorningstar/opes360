<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    public Document $document;

    public function mount(Document $document): void
    {
        // Route model binding already applied the tenant scope; loading here keeps
        // the view free of lazy loads (which are fatal in development).
        $this->document = $document->load([
            'contact',
            'lines',
            'allocations.payment',
            'verificationToken',
        ]);
    }

    public function render(): View
    {
        return view('livewire.documents.show')
            ->layout('components.layouts.app', [
                'title' => $this->document->number ?? 'Document',
                'active' => 'sales',
            ]);
    }
}
