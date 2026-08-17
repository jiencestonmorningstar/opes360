<?php

namespace App\Livewire\Spreadsheets;

use App\Models\Spreadsheet;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * The list of a company's spreadsheets, and where a new one is created —
 * §8.2 of the master spec.
 */
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', Spreadsheet::class);
    }

    public function create(): void
    {
        $this->authorize('create', Spreadsheet::class);

        $sheet = Spreadsheet::create([
            'title' => 'Untitled spreadsheet',
            'cells' => [],
            'created_by' => auth()->id(),
        ]);

        $this->redirectRoute('spreadsheets.edit', $sheet);
    }

    public function delete(string $id): void
    {
        $sheet = Spreadsheet::findOrFail($id);
        $this->authorize('delete', $sheet);

        $sheet->delete();
    }

    public function render(): View
    {
        return view('livewire.spreadsheets.index', [
            'sheets' => Spreadsheet::query()->with('creator')->latest('updated_at')->get(),
        ])->layout('components.layouts.app', [
            'title' => 'Spreadsheets',
            'active' => 'spreadsheets',
        ]);
    }
}
