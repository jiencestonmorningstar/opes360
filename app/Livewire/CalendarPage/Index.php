<?php

namespace App\Livewire\CalendarPage;

use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The calendar a small business actually needs first: money with a date on it.
 * Overdue invoices, then everything falling due in the next 30 days, plus
 * quotations about to expire.
 */
class Index extends Component
{
    public function render(): View
    {
        $today = now()->startOfDay();

        $overdue = Document::query()
            ->invoices()
            ->outstanding()
            ->whereDate('due_date', '<', $today)
            ->with('contact')
            ->orderBy('due_date')
            ->get();

        $upcoming = Document::query()
            ->invoices()
            ->outstanding()
            ->whereBetween('due_date', [$today, $today->copy()->addDays(30)])
            ->with('contact')
            ->orderBy('due_date')
            ->get()
            ->groupBy(fn (Document $d) => $d->due_date->toDateString());

        $expiring = Document::query()
            ->ofType(DocumentType::Quotation)
            ->issued()
            ->whereBetween('valid_until', [$today, $today->copy()->addDays(14)])
            ->with('contact')
            ->orderBy('valid_until')
            ->get();

        return view('livewire.calendar.index', [
            'overdue' => $overdue,
            'upcoming' => $upcoming,
            'expiring' => $expiring,
        ])->layout('components.layouts.app', ['title' => 'Calendar', 'active' => 'calendar']);
    }
}
