<?php

namespace App\Livewire\Imports;

use App\Services\RecordImporter;
use App\Support\UploadGate;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Bringing an existing business's records in.
 *
 * Upload, look at what it found, then commit. The preview step is the point:
 * an import that writes first and reports afterwards leaves a business picking
 * half-right rows out of its customer book by hand.
 */
class Index extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    #[Url]
    public string $type = 'customers';

    public $file;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var array<int, array{line: int, reason: string}> */
    public array $skipped = [];

    /** @var array<int, string> */
    public array $matched = [];

    /** @var array<int, string> */
    public array $unmatched = [];

    public bool $previewed = false;

    public ?string $error = null;

    public function setType(string $type): void
    {
        $this->type = array_key_exists($type, RecordImporter::TYPES) ? $type : 'customers';
        $this->reset(['rows', 'skipped', 'matched', 'unmatched', 'previewed', 'error', 'file']);
    }

    public function updatedFile(): void
    {
        $this->preview();
    }

    public function preview(): void
    {
        $this->authorizeImport();

        $this->reset(['rows', 'skipped', 'matched', 'unmatched', 'previewed', 'error']);

        $this->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx,xls'],
        ], [
            'file.mimes' => 'Upload an Excel (.xlsx) or CSV file.',
            'file.max' => 'That file is larger than 5 MB.',
        ]);

        try {
            // The one gate every upload passes: tabular files only, sniffed
            // bytes agreeing with the name, scanned when clamd is there.
            app(UploadGate::class)->accept($this->file, 'import');

            $result = app(RecordImporter::class)->preview(
                $this->type,
                file_get_contents($this->file->getRealPath())
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->rows = $result['rows'];
        $this->skipped = $result['skipped'];
        $this->matched = $result['matched'];
        $this->unmatched = $result['unmatched'];
        $this->previewed = true;
    }

    public function commit(): void
    {
        $this->authorizeImport();

        if ($this->rows === []) {
            $this->error = 'There is nothing to import.';

            return;
        }

        $result = app(RecordImporter::class)->commit($this->type, $this->rows, auth()->user());

        $noun = $this->type === 'products' ? 'product' : 'customer';

        session()->flash('status', sprintf(
            '%d %s added%s.',
            $result['created'],
            Str::plural($noun, $result['created']),
            $result['updated'] > 0 ? ', '.$result['updated'].' updated' : ''
        ));

        $this->reset(['rows', 'skipped', 'matched', 'unmatched', 'previewed', 'file']);
    }

    public function render(): View
    {
        return view('livewire.imports.index')
            ->layout('components.layouts.app', ['title' => 'Import', 'active' => 'settings']);
    }

    protected function authorizeImport(): void
    {
        // The import writes the same records the create screens write, so it
        // asks for the same permission rather than inventing an import one.
        Gate::authorize($this->type === 'products' ? 'products.create' : 'customers.create');
    }
}
