<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Document;
use App\Models\Payment;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Brings the books up to date with what already happened.
 *
 * The ledger records events as they flow through the services, which means an
 * install that predates it has issued documents and taken payments the books
 * know nothing about — a balance générale that shows no receivables for
 * invoices that are genuinely outstanding. This walks the history and posts
 * it, each event at its own original date, so the grand livre reads as if the
 * books had been kept all along.
 *
 * Safe to run repeatedly and safe to run on a live install: posting is
 * idempotent per source (Ledger checks before writing), so an event already
 * in the books is skipped, whether it got there from a previous run or from
 * the live path. Voided documents are skipped — their net effect is nothing,
 * and the books start honest rather than busy.
 */
class BackfillLedger extends Command
{
    protected $signature = 'opes:backfill-ledger
        {--company= : Only this company (slug)}
        {--dry-run : Report what would be posted without writing anything}';

    protected $description = 'Post historical issued documents and payments into the ledger';

    public function handle(RecordsBusinessEvents $events, CurrentCompany $current): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        foreach ($companies as $company) {
            $current->as($company, function () use ($company, $events, $dry) {
                ChartOfAccounts::seed($company);

                // Only what the live path would have recorded: receivable
                // types, past draft, not void.
                $documents = Document::query()
                    ->whereNotIn('status', ['draft', 'void'])
                    ->with(['contact', 'lines.item', 'company'])
                    ->orderBy('issue_date')
                    ->get()
                    ->filter(fn (Document $d) => $d->type->isReceivable());

                $payments = Payment::query()->with('contact')->orderBy('received_at')->get();

                $posted = ['documents' => 0, 'payments' => 0, 'skipped' => 0, 'failed' => 0];

                /*
                 * Failures are counted and shown, not folded into "skipped".
                 * The live path swallows posting errors because a customer is
                 * standing at the counter; here nobody is waiting, and a
                 * back-fill that silently half-completes leaves books that
                 * look done and are not.
                 */
                $post = function (string $kind, callable $record) use (&$posted, $dry) {
                    if ($dry) {
                        $posted[$kind]++;

                        return;
                    }

                    try {
                        $entry = $record();
                        // wasRecentlyCreated distinguishes "posted now" from
                        // "was already in the books".
                        $entry?->wasRecentlyCreated ? $posted[$kind]++ : $posted['skipped']++;
                    } catch (\Throwable $e) {
                        $posted['failed']++;
                        $this->warn('  failed: '.$e->getMessage());
                    }
                };

                foreach ($documents as $document) {
                    $post('documents', fn () => $events->recordIssuedDocument($document, $company));
                }

                foreach ($payments as $payment) {
                    $post('payments', fn () => $events->recordPayment($payment, $company));
                }

                $this->info(sprintf(
                    '%s%s: %d document(s), %d payment(s) posted, %d already in the books, %d failed.',
                    $company->name,
                    $dry ? ' (dry run)' : '',
                    $posted['documents'],
                    $posted['payments'],
                    $posted['skipped'],
                    $posted['failed'],
                ));
            });
        }

        return self::SUCCESS;
    }
}
