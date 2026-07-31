<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What came back — Opes Forms responses.
 *
 * Choice-type fields get a per-option tally above the list: for the "which
 * session are you attending" kind of form, the tally is the entire reason it
 * was sent out.
 */
class Responses extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public Form $form;

    public function mount(Form $form): void
    {
        $this->authorize('responses', $form);

        $this->form = $form;
    }

    /**
     * option => count for each field that has options, in field order.
     *
     * @return array<string, array{label: string, counts: array<string, int>}>
     */
    public function summaries(): array
    {
        $fields = collect($this->form->fieldDefinitions())
            ->filter(fn (array $field) => $field['options'] !== []);

        if ($fields->isEmpty()) {
            return [];
        }

        $answers = $this->form->responses()->pluck('answers');

        return $fields->mapWithKeys(function (array $field) use ($answers) {
            $counts = array_fill_keys($field['options'], 0);

            foreach ($answers as $set) {
                $value = $set[$field['id']] ?? null;

                foreach ((array) $value as $picked) {
                    if (array_key_exists($picked, $counts)) {
                        $counts[$picked]++;
                    }
                }
            }

            return [$field['id'] => ['label' => $field['label'], 'counts' => $counts]];
        })->all();
    }

    public function render(): View
    {
        return view('livewire.forms.responses', [
            'fields' => $this->form->fieldDefinitions(),
            'responses' => $this->form->responses()->latest()->paginate(25),
            'total' => $this->form->responses()->count(),
            'summaries' => $this->summaries(),
        ])->layout('components.layouts.app', [
            'title' => $this->form->title.' · Responses',
            'active' => 'forms',
        ]);
    }
}
