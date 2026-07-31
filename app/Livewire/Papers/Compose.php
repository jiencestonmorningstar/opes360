<?php

namespace App\Livewire\Papers;

use App\Models\BusinessDocument;
use App\Services\DocumentComposer;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use RuntimeException;

/**
 * Fill in a template and see the result as you type.
 *
 * The preview is the point: these documents get signed, and a business owner
 * needs to read the sentences their answers produce — not trust that the
 * placeholders landed somewhere sensible.
 */
class Compose extends Component
{
    use AuthorizesRequests;

    public string $templateKey = '';

    public ?BusinessDocument $paper = null;

    public string $title = '';

    /** @var array<string, string> */
    public array $fields = [];

    public function mount(?string $template = null, ?BusinessDocument $paper = null): void
    {
        $this->paper = $paper?->exists ? $paper : null;

        if ($this->paper) {
            // An issued document is frozen; editing it would leave this copy and
            // the signed one disagreeing.
            abort_if($this->paper->isIssued(), 403, 'This document is issued and cannot be edited.');

            $this->templateKey = $this->paper->template;
            $this->title = $this->paper->title;
            $this->fields = array_map(fn ($v) => (string) $v, $this->paper->fields ?? []);
        } else {
            abort_unless(DocumentTemplates::exists((string) $template), 404);

            $this->templateKey = (string) $template;
            $this->title = DocumentTemplates::find($this->templateKey)['name'];
            $this->fields = collect($this->definition()['fields'])
                ->mapWithKeys(fn (array $field) => [$field['key'] => (string) ($field['default'] ?? '')])
                ->all();
        }
    }

    /** @return array<string, mixed> */
    protected function definition(): array
    {
        return DocumentTemplates::find($this->templateKey);
    }

    public function preview(): string
    {
        return app(DocumentComposer::class)->merge(
            $this->templateKey,
            $this->fields,
            app(CurrentCompany::class)->get(),
        );
    }

    public function save(bool $issue = false): void
    {
        $this->authorize($issue ? 'papers.issue' : 'papers.create');

        $rules = ['title' => ['required', 'string', 'max:160']];
        $messages = ['title.required' => 'Give the document a name you will recognise later.'];

        foreach ($this->definition()['fields'] as $field) {
            if ($field['required'] ?? false) {
                $rules["fields.{$field['key']}"] = ['required', 'string'];
                $messages["fields.{$field['key']}.required"] = $field['label'].' is needed.';
            }
        }

        $this->validate($rules, $messages);

        $composer = app(DocumentComposer::class);
        $company = app(CurrentCompany::class)->get();

        $attributes = [
            'template' => $this->templateKey,
            'title' => trim($this->title),
            'recipient' => $this->recipient(),
            'fields' => $this->fields,
            // Composed once and stored: a later edit to the template library
            // must never rewrite a document someone has already been shown.
            'body' => $composer->merge($this->templateKey, $this->fields, $company),
        ];

        if ($this->paper) {
            $this->paper->update($attributes);
        } else {
            $this->paper = BusinessDocument::create($attributes + [
                // Set explicitly rather than left to the column default: the
                // code reads the status back immediately, and a default only
                // the database knows about leaves the model holding null.
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
        }

        if ($issue) {
            try {
                $composer->issue($this->paper, auth()->user());
            } catch (RuntimeException $e) {
                $this->addError('title', $e->getMessage());

                return;
            }
        }

        $this->redirectRoute('papers.show', $this->paper);
    }

    public function saveAndIssue(): void
    {
        $this->save(issue: true);
    }

    /** The first named party, used as the list subtitle. */
    protected function recipient(): ?string
    {
        foreach (['client_name', 'other_party', 'employee_name', 'recipient', 'debtor_name'] as $key) {
            if (filled($this->fields[$key] ?? null)) {
                return trim($this->fields[$key]);
            }
        }

        return null;
    }

    public function render(): View
    {
        $definition = $this->definition();

        return view('livewire.papers.compose', [
            'definition' => $definition,
            'bodyHtml' => app(DocumentComposer::class)->toHtml($this->preview()),
            'notice' => ($definition['binding'] ?? false) ? DocumentTemplates::reviewNotice() : null,
            // For the preview's letterhead, so the on-screen sheet carries the
            // same header the printed one will.
            'company' => app(CurrentCompany::class)->get(),
        ])->layout('components.layouts.app', [
            'title' => $this->paper ? 'Edit '.$this->paper->title : $definition['name'],
            'active' => 'papers',
            'bottomNav' => false,
        ]);
    }
}
