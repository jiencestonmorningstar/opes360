<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\PaymentAllocation;
use App\Models\Property;
use App\Models\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the agency owes one landlord for one period, line by line.
 *
 * The mirror of SupplierAccount, and it exists for the same conversation:
 * this page is what the landlord's own arithmetic gets laid beside. Rent
 * COLLECTED on their units (not billed — the landlord is owed what came in),
 * minus the agency's commission at each property's own percentage, minus
 * expenses recorded against the property, minus payouts already made. The
 * closing balance is what the landlord is owed today.
 *
 * ── Sign convention ────────────────────────────────────────────────────────
 *
 * Balances are what the business OWES the landlord, positive when money is
 * due out — the same convention SupplierAccount chose, for the same reason:
 * nobody should have to flip signs in their head.
 *
 * ── Read-only, from the classes that own the figures ───────────────────────
 *
 * Rent collected is `payments` rows; expenses and payouts are `expenses`
 * rows through ExpenseRecorder. Nothing here is a second copy of money.
 *
 * ── Attribution ────────────────────────────────────────────────────────────
 *
 * A payment belongs to a property when the paying contact holds (or held) a
 * tenancy on one of its units and the invoice was issued inside that
 * tenancy's window. Generated invoices do not store their schedule id, so
 * the window test is the exact join available; for an agency, whose tenants'
 * invoices are rent, it is also the true one.
 */
class LandlordStatement
{
    public function __construct(
        protected Contact $landlord,
        protected CarbonInterface $from,
        protected CarbonInterface $to,
    ) {}

    /**
     * @return array{
     *     landlord: Contact,
     *     from: CarbonInterface,
     *     to: CarbonInterface,
     *     currency: string,
     *     opening_balance: float,
     *     lines: array<int, array<string, mixed>>,
     *     closing_balance: float,
     *     totals: array{collected: float, commission: float, expenses: float, paid_out: float},
     * }
     */
    public function build(): array
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();

        $properties = $this->properties();

        $opening = $this->balanceBefore($properties, $from);
        $lines = $this->movements($properties, $from, $to);

        $balance = $opening;
        $totals = ['collected' => 0.0, 'commission' => 0.0, 'expenses' => 0.0, 'paid_out' => 0.0];

        foreach ($lines as $index => $line) {
            $balance = round($balance + $line['credit'] - $line['debit'], 2);
            $lines[$index]['balance'] = $balance;

            match ($line['kind']) {
                'rent' => $totals['collected'] += $line['credit'],
                'commission' => $totals['commission'] += $line['debit'],
                'expense' => $totals['expenses'] += $line['debit'],
                'payout' => $totals['paid_out'] += $line['debit'],
            };
        }

        return [
            'landlord' => $this->landlord,
            'from' => $from,
            'to' => $to,
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
            'opening_balance' => round($opening, 2),
            'lines' => $lines,
            'closing_balance' => round($balance, 2),
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
        ];
    }

    /** @return Collection<int, Property> */
    protected function properties(): Collection
    {
        return Property::query()
            ->where('landlord_contact_id', $this->landlord->id)
            ->with('units.tenancies')
            ->get();
    }

    /**
     * Everything that moved the account inside the period, oldest first.
     * A rent receipt credits the landlord and immediately debits its own
     * commission, so the pair reads together on the page.
     *
     * @param  Collection<int, Property>  $properties
     * @return array<int, array<string, mixed>>
     */
    protected function movements(Collection $properties, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];

        foreach ($properties as $property) {
            foreach ($this->rentAllocations($property, $from, $to) as $allocation) {
                $amount = round((float) $allocation->amount, 2);
                $rate = (float) ($property->commission_percent ?? 0);
                $when = $allocation->payment?->received_at ?? $allocation->created_at;

                $rows[] = [
                    'date' => $when,
                    'kind' => 'rent',
                    'reference' => $allocation->document?->number,
                    'description' => 'Rent collected — '.$property->name,
                    'debit' => 0.0,
                    'credit' => $amount,
                    'sequence' => ($allocation->created_at?->getTimestamp() ?? 0) * 2,
                ];

                if ($rate > 0) {
                    $rows[] = [
                        'date' => $when,
                        'kind' => 'commission',
                        'reference' => $allocation->document?->number,
                        'description' => 'Agency commission '.rtrim(rtrim(number_format($rate, 2), '0'), '.').'% — '.$property->name,
                        'debit' => round($amount * $rate / 100, 2),
                        'credit' => 0.0,
                        'sequence' => ($allocation->created_at?->getTimestamp() ?? 0) * 2 + 1,
                    ];
                }
            }

            foreach ($this->pinnedExpenses($property)->whereBetween('issue_date', [$from, $to])->get() as $expense) {
                $isPayout = $expense->supplier_id === $this->landlord->id;

                $rows[] = [
                    'date' => $expense->issue_date,
                    'kind' => $isPayout ? 'payout' : 'expense',
                    'reference' => $expense->reference ?: $expense->number,
                    'description' => ($isPayout ? 'Paid out — ' : 'Property expense — ').$expense->description,
                    'debit' => round((float) $expense->total, 2),
                    'credit' => 0.0,
                    'sequence' => ($expense->created_at?->getTimestamp() ?? 0) * 2,
                ];
            }
        }

        usort($rows, function (array $a, array $b) {
            $byDate = Carbon::parse($a['date'])->getTimestamp() <=> Carbon::parse($b['date'])->getTimestamp();

            return $byDate !== 0 ? $byDate : $a['sequence'] <=> $b['sequence'];
        });

        return array_values($rows);
    }

    /**
     * The single figure standing at the start of the period — the same
     * arithmetic run over everything earlier, so a past period's statement
     * stays right about that period.
     *
     * @param  Collection<int, Property>  $properties
     */
    protected function balanceBefore(Collection $properties, CarbonInterface $from): float
    {
        $balance = 0.0;

        foreach ($properties as $property) {
            $rate = (float) ($property->commission_percent ?? 0);

            $collected = 0.0;

            foreach ($this->rentAllocations($property, null, $from->copy()->subSecond()) as $allocation) {
                $collected += round((float) $allocation->amount, 2);
            }

            $spent = (float) $this->pinnedExpenses($property)
                ->where('issue_date', '<', $from)
                ->sum('total');

            $balance += $collected - round($collected * $rate / 100, 2) - $spent;
        }

        return round($balance, 2);
    }

    /**
     * Rent receipts for one property: payment allocations by its tenants
     * against invoices issued inside their tenancy windows. Allocations
     * rather than payments, because a payment can settle several documents
     * and only the rent invoice's share belongs to the landlord.
     *
     * @return Collection<int, PaymentAllocation>
     */
    protected function rentAllocations(Property $property, ?CarbonInterface $from, CarbonInterface $to): Collection
    {
        /** @var Collection<int, Tenancy> $tenancies */
        $tenancies = $property->units->flatMap->tenancies;

        if ($tenancies->isEmpty()) {
            return collect();
        }

        $allocations = PaymentAllocation::query()
            ->with(['payment', 'document'])
            ->whereHas('document', fn ($q) => $q
                ->whereIn('contact_id', $tenancies->pluck('tenant_contact_id')->unique())
                ->where('type', DocumentType::Invoice)
                ->where('status', '!=', DocumentStatus::Void))
            ->whereHas('payment', fn ($q) => $q
                ->where('received_at', '<=', $to)
                ->when($from !== null, fn ($qq) => $qq->where('received_at', '>=', $from)))
            ->get()
            ->sortBy(fn (PaymentAllocation $a) => $a->payment?->received_at)
            ->values();

        return $allocations->filter(function (PaymentAllocation $allocation) use ($tenancies) {
            $document = $allocation->document;

            if ($document === null || $document->issue_date === null) {
                return false;
            }

            return $tenancies->contains(function (Tenancy $t) use ($document) {
                if ($t->tenant_contact_id !== $document->contact_id) {
                    return false;
                }

                $issued = $document->issue_date->copy()->startOfDay();

                return $issued->gte($t->moved_in_on->copy()->startOfDay())
                    && ($t->moved_out_on === null || $issued->lte($t->moved_out_on->copy()->endOfDay()));
            });
        })->values();
    }

    protected function pinnedExpenses(Property $property)
    {
        return Expense::query()
            ->where('property_id', $property->id)
            ->where('status', '!=', 'void');
    }
}
