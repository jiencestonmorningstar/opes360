<?php

namespace App\Livewire\Spreadsheets;

use App\Models\Spreadsheet;
use App\Services\Spreadsheets\SpreadsheetEngine;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * The grid itself — §8.2 of the master spec. A fixed 8-column, 25-row grid
 * for v1 rather than an infinite/resizable one: real spreadsheet grids grow
 * dynamically, but that is a meaningfully bigger frontend (virtualised
 * scrolling, insert/delete row-and-shift) than this pass scoped, and a
 * fixed grid this size already fits every example in the master spec
 * (`OPES_SUM`, `OPES_LOOKUP`, a running total). Documented, not hidden —
 * see the collaborative-editor roadmap plan.
 */
class Edit extends Component
{
    use AuthorizesRequests;

    /** @var array<int, string> */
    public const COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    public const ROWS = 25;

    public Spreadsheet $sheet;

    public string $title = '';

    /** @var array<string, string> ref => raw formula/literal, as typed. */
    public array $cells = [];

    public function mount(Spreadsheet $sheet): void
    {
        $this->authorize('view', $sheet);

        $this->sheet = $sheet;
        $this->title = $sheet->title;
        $this->cells = $sheet->cells ?? [];
    }

    /** @return array<int, string> */
    public function refs(): array
    {
        $refs = [];

        foreach (range(1, self::ROWS) as $row) {
            foreach (self::COLUMNS as $column) {
                $refs[] = $column.$row;
            }
        }

        return $refs;
    }

    public function save(): void
    {
        $this->authorize('update', $this->sheet);

        $this->validate(['title' => ['required', 'string', 'max:160']]);

        // Blank cells are noise a formula never needs to resolve — dropped
        // on save rather than stored as empty strings forever.
        $cells = collect($this->cells)->filter(fn ($value) => trim((string) $value) !== '')->all();

        $this->sheet->update(['title' => trim($this->title), 'cells' => $cells]);
        $this->cells = $cells;

        $this->dispatch('toast', message: 'Saved.');
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        // The engine reads from the model's own `cells` — but unsaved edits
        // in this request's $cells should already be visible on screen, so
        // evaluate against a clone carrying them rather than the persisted
        // row, which save() has not necessarily been called for yet.
        $preview = clone $this->sheet;
        $preview->cells = $this->cells;
        $results = $company !== null ? app(SpreadsheetEngine::class)->evaluate($preview, $company) : [];

        return view('livewire.spreadsheets.edit', [
            'columns' => self::COLUMNS,
            'rows' => range(1, self::ROWS),
            'results' => $results,
        ])->layout('components.layouts.app', [
            'title' => 'Edit '.$this->sheet->title,
            'active' => 'spreadsheets',
        ]);
    }
}
