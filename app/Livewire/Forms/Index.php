<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Opes Forms — the list. "New form" creates an untitled draft and drops the
 * user straight into the builder, the way every forms product works: naming
 * a thing before it exists is backwards.
 */
class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createForm()
    {
        $this->authorize('create', Form::class);

        $form = Form::create([
            'title' => 'Untitled form',
            'status' => 'draft',
            'share_token' => Form::newShareToken(),
            'created_by' => auth()->id(),
        ]);

        return $this->redirectRoute('forms.build', $form);
    }

    public function deleteForm(string $formId): void
    {
        $form = Form::findOrFail($formId);

        $this->authorize('delete', $form);

        $form->delete();
    }

    public function render(): View
    {
        $query = Form::query()->withCount('responses')->latest('updated_at');

        if (($term = trim($this->search)) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where('title', 'like', $like);
        }

        return view('livewire.forms.index', [
            'forms' => $query->paginate(12),
        ])->layout('components.layouts.app', [
            'title' => 'Forms',
            'active' => 'forms',
        ]);
    }
}
