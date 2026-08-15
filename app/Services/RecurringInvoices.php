<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning standing arrangements into invoices.
 *
 * Runs nightly from a console command with no tenant in scope, so every query
 * here names its company explicitly rather than leaning on the global scope.
 */
class RecurringInvoices
{
    /**
     * How many periods one schedule may catch up in a single run.
     *
     * A schedule generates every period it has missed, not just the latest —
     * a server down for a week must not silently skip a week's billing. The
     * cap stops a badly-dated schedule (a start date years in the past) from
     * generating hundreds of invoices in one night; when it bites, the run
     * reports it rather than trimming quietly.
     */
    public const CATCH_UP_LIMIT = 12;

    public function __construct(protected DocumentIssuer $issuer) {}

    /**
     * Generate everything owed up to today.
     *
     * @return array{created: int, schedules: int, capped: array<int, string>}
     */
    public function run(?Carbon $on = null): array
    {
        $on ??= now();

        $created = 0;
        $capped = [];

        $due = RecurringInvoice::withoutGlobalScopes()
            ->with(['company', 'contact'])
            ->due($on)
            ->get();

        foreach ($due as $schedule) {
            $made = $this->catchUp($schedule, $on);

            $created += $made['created'];

            if ($made['capped']) {
                $capped[] = $schedule->name;
            }
        }

        return ['created' => $created, 'schedules' => $due->count(), 'capped' => $capped];
    }

    /**
     * Run one schedule forward until it is no longer due.
     *
     * @return array{created: int, capped: bool}
     */
    public function catchUp(RecurringInvoice $schedule, ?Carbon $on = null): array
    {
        $on ??= now();
        $created = 0;

        while ($created < self::CATCH_UP_LIMIT) {
            if (! $schedule->isActive() || $schedule->next_run_on === null) {
                break;
            }

            if ($schedule->next_run_on->gt($on->copy()->endOfDay())) {
                break;
            }

            $this->generate($schedule);
            $created++;

            $schedule->refresh();
        }

        $stillDue = $schedule->isActive()
            && $schedule->next_run_on !== null
            && $schedule->next_run_on->lte($on->copy()->endOfDay());

        return ['created' => $created, 'capped' => $stillDue];
    }

    /**
     * One invoice, dated on the period it bills for.
     *
     * The issue date is the schedule's own next_run_on rather than today, so an
     * invoice generated late by a catch-up still belongs to the month it is
     * charging for — which is what the customer, and the books, expect.
     */
    public function generate(RecurringInvoice $schedule): Document
    {
        /*
         * Run inside the schedule's own tenant.
         *
         * Creating a document is not just an insert: it draws a sync sequence,
         * and issuing draws a number from the company's series. Both read the
         * current company, and this runs from a console command where there is
         * none — so without this the nightly run dies on a not-null constraint
         * rather than billing anybody. Setting it per schedule also keeps two
         * companies' documents from ever being numbered against each other.
         */
        return app(CurrentCompany::class)->as(
            $schedule->company,
            fn () => $this->generateWithin($schedule),
        );
    }

    protected function generateWithin(RecurringInvoice $schedule): Document
    {
        return DB::transaction(function () use ($schedule) {
            /*
             * Locked, as every other generator here locks. Two nightly runs
             * overlapping — a retry, a second worker — would otherwise both
             * read the same next_run_on and bill the customer twice for one
             * period. The lock makes the second wait and see the advanced date.
             */
            $locked = RecurringInvoice::withoutGlobalScopes()
                ->whereKey($schedule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $company = $locked->company;
            $issueDate = $locked->next_run_on->copy();

            $lines = $this->normalise($locked->lines);

            $vat = Vat::forCompany($company, $lines, (float) $locked->discount_percent);

            $document = Document::withoutGlobalScopes()->create([
                'company_id' => $locked->company_id,
                'type' => DocumentType::Invoice,
                'contact_id' => $locked->contact_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->copy()->addDays($locked->payment_terms_days)->toDateString(),
                'currency' => $company->currency,
                'subtotal' => $vat['subtotal'],
                'discount_total' => $vat['discount_total'],
                'tax_total' => $vat['tax_total'],
                'total' => $vat['total'],
                'amount_paid' => 0,
                'balance' => $vat['total'],
                'notes' => $locked->notes,
                'created_by' => $locked->created_by,
            ]);

            foreach ($lines as $index => $line) {
                DocumentLine::withoutGlobalScopes()->create([
                    'document_id' => $document->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'] ?? 'unit',
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $vat['lines'][$index]['tax'] ?? 0.0,
                    'line_total' => $vat['lines'][$index]['net'] ?? 0.0,
                    'sort_order' => $index,
                ]);
            }

            if ($locked->auto_issue) {
                $actor = $locked->created_by ? User::find($locked->created_by) : null;

                if ($actor !== null) {
                    $document = $this->issuer->issue($document->fresh(), $actor);
                }
            }

            $next = $locked->advanceFrom($issueDate);
            $occurrences = $locked->occurrences + 1;

            $locked->forceFill([
                'occurrences' => $occurrences,
                'next_run_on' => $next->toDateString(),
                'last_run_at' => now(),
                'last_document_id' => $document->id,
            ])->save();

            // Re-checked after the counter moved, so a schedule capped at six
            // invoices stops on the sixth rather than after a seventh.
            if ($locked->fresh()->hasFinished($next)) {
                $locked->forceFill(['status' => RecurringInvoice::FINISHED])->save();
            }

            return $document;
        });
    }

    /**
     * Fill in what a stored line may be missing.
     *
     * Schedules outlive the form that made them, so a line written by an older
     * version can arrive without a unit or a quantity. Rejecting it would stop
     * a customer being billed; defaulting it bills them correctly.
     *
     * @param  array<int, array<string, mixed>>|null  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function normalise(?array $lines): array
    {
        return collect($lines ?? [])
            ->map(fn ($line) => [
                'description' => trim((string) ($line['description'] ?? 'Item')),
                'quantity' => (float) ($line['quantity'] ?? 1),
                'unit' => $line['unit'] ?? 'unit',
                'unit_price' => (float) ($line['unit_price'] ?? 0),
            ])
            ->reject(fn ($line) => $line['description'] === '' && $line['unit_price'] === 0.0)
            ->values()
            ->all();
    }

    /**
     * Start a schedule on its first run date.
     *
     * A schedule created with a start date in the past is due immediately,
     * which is usually what somebody migrating an existing arrangement means.
     */
    public function firstRun(Carbon $startsOn): Carbon
    {
        return $startsOn->copy()->startOfDay();
    }
}
