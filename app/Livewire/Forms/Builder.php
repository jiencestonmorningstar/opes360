<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use App\Support\FormFields;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * The form builder — the Google Forms half of Opes Forms.
 *
 * The whole field set is edited in one component and saved in one write.
 * Every structural action (add, remove, move) saves immediately; the text
 * fields save on blur. There is no explicit save button to forget.
 */
class Builder extends Component
{
    use AuthorizesRequests;

    public Form $form;

    public string $title = '';

    public string $description = '';

    /** @var array<int, array<string, mixed>> */
    public array $fields = [];

    public function mount(Form $form): void
    {
        $this->authorize('update', $form);

        $this->form = $form;
        $this->title = $form->title;
        $this->description = (string) $form->description;
        $this->fields = $form->fieldDefinitions();
    }

    public function addField(string $type): void
    {
        $this->fields[] = FormFields::blank($type);
        $this->persist();
    }

    public function removeField(int $index): void
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
        $this->persist();
    }

    public function moveField(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->fields[$index], $this->fields[$target])) {
            return;
        }

        [$this->fields[$index], $this->fields[$target]] = [$this->fields[$target], $this->fields[$index]];
        $this->persist();
    }

    public function addOption(int $index): void
    {
        if (isset($this->fields[$index])) {
            $this->fields[$index]['options'][] = 'Option '.(count($this->fields[$index]['options']) + 1);
            $this->persist();
        }
    }

    public function removeOption(int $index, int $option): void
    {
        if (isset($this->fields[$index]['options'][$option])) {
            unset($this->fields[$index]['options'][$option]);
            $this->fields[$index]['options'] = array_values($this->fields[$index]['options']);
            $this->persist();
        }
    }

    /** Text edits arrive through wire:model.blur — persist on every update. */
    public function updated(): void
    {
        $this->persist();
    }

    public function setStatus(string $status): void
    {
        if (! in_array($status, ['draft', 'open', 'closed'], true)) {
            return;
        }

        // An open form with nothing to fill in collects nothing but confusion.
        if ($status === 'open' && $this->publishableFields() === []) {
            $this->addError('fields', 'Add at least one labelled question before opening the form.');

            return;
        }

        $this->form->update(['status' => $status]);
        $this->form->refresh();
    }

    protected function publishableFields(): array
    {
        return array_values(array_filter(
            array_map(FormFields::normalise(...), $this->fields),
            fn (array $field) => trim($field['label']) !== '',
        ));
    }

    protected function persist(): void
    {
        $this->form->update([
            'title' => trim($this->title) !== '' ? trim($this->title) : 'Untitled form',
            'description' => trim($this->description) !== '' ? trim($this->description) : null,
            'fields' => array_map(FormFields::normalise(...), $this->fields),
        ]);
    }

    public function render(): View
    {
        return view('livewire.forms.builder', [
            'types' => FormFields::TYPES,
        ])->layout('components.layouts.app', [
            'title' => $this->form->title,
            'active' => 'forms',
        ]);
    }
}
