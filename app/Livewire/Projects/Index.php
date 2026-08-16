<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The projects workspace: search, filter by status, create, and the
 * essentials for each — client, manager, budget, cost so far against it.
 */
class Index extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public bool $creating = false;

    public string $name = '';

    public string $code = '';

    public ?float $budget = null;

    public bool $isBillable = true;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'budget' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function save(): void
    {
        $this->authorize('create', Project::class);

        $this->validate();

        Project::create([
            'name' => $this->name,
            'code' => $this->code ?: null,
            'budget' => $this->budget,
            'is_billable' => $this->isBillable,
            'status' => 'planning',
            'created_by' => auth()->id(),
        ]);

        $this->reset(['name', 'code', 'budget', 'creating']);
        $this->isBillable = true;
    }

    public function archive(string $id): void
    {
        $project = Project::findOrFail($id);

        $this->authorize('update', $project);

        $project->update(['status' => 'cancelled']);
    }

    public function render(): View
    {
        $this->authorize('viewAny', Project::class);

        // Paginated, never unbounded (§60): a business with two hundred
        // projects must not send all two hundred to the browser at once.
        $projects = Project::query()
            ->with(['contact', 'manager', 'company'])
            ->when($this->search !== '', fn ($q) => $q
                ->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(20);

        return view('livewire.projects.index', [
            'projects' => $projects,
        ])->layout('components.layouts.app', ['title' => 'Projects', 'active' => 'projects']);
    }
}
