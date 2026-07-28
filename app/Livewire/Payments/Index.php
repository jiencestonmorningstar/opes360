<?php

namespace App\Livewire\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $method = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public function setMethod(string $method): void
    {
        $this->method = $method === 'all' || PaymentMethod::tryFrom($method) ? $method : 'all';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();
        $now = CarbonImmutable::now($company?->timezone ?? 'UTC');

        return view('livewire.payments.index', [
            'payments' => $this->query()->paginate(20),
            'currency' => $company?->currency ?? 'USD',
            'totals' => [
                'today' => $this->sumBetween($now->startOfDay(), $now->endOfDay()),
                'week' => $this->sumBetween($now->startOfWeek(), $now->endOfWeek()),
                'month' => $this->sumBetween($now->startOfMonth(), $now->endOfMonth()),
            ],
        ])->layout('components.layouts.app', ['title' => 'Payments', 'active' => 'payments']);
    }

    protected function sumBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) Payment::query()->whereBetween('received_at', [$from, $to])->sum('amount');
    }

    protected function query(): Builder
    {
        return Payment::query()
            ->with(['contact', 'receipt'])
            ->when($this->method !== 'all', fn (Builder $q) => $q->where('method', $this->method))
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('reference', 'like', $term)
                    ->orWhereHas('receipt', fn (Builder $r) => $r->where('number', 'like', $term))
                    ->orWhereHas('contact', fn (Builder $c) => $c
                        ->where('name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)));
            })
            ->latest('received_at');
    }
}
