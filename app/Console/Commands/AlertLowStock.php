<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Item;
use App\Notifications\LowStockNotification;
use App\Support\CurrentCompany;
use App\Support\NotifyCompany;
use Illuminate\Console\Command;

/**
 * The daily "you are about to run out" mail.
 *
 * Runs once a day rather than on every stock movement: a shop that sells the
 * last of something at 09:00 does not need the same alert again at 09:05, and
 * reordering is a daily rhythm anyway.
 */
class AlertLowStock extends Command
{
    protected $signature = 'opes:alert-low-stock {--dry-run : List what would be sent without sending it}';

    protected $description = 'Notify each business about products at or below their reorder level';

    public function handle(): int
    {
        $sent = 0;

        Company::query()
            ->whereNull('suspended_at')
            ->where('account_type', '!=', 'demo')
            ->chunkById(50, function ($companies) use (&$sent) {
                foreach ($companies as $company) {
                    // Everything below runs inside the tenant scope; the scope
                    // fails closed, so a query outside it would silently return
                    // nothing rather than leak another company's stock.
                    $low = app(CurrentCompany::class)->as($company, fn () => Item::query()
                        ->where('track_stock', true)
                        ->whereNotNull('reorder_level')
                        ->get()
                        ->filter(fn (Item $item) => $item->isLowStock())
                        ->values());

                    if ($low->isEmpty()) {
                        continue;
                    }

                    $this->line("{$company->name}: {$low->count()} item(s) low");

                    if (! $this->option('dry-run')) {
                        NotifyCompany::about($company, 'products.view', new LowStockNotification($low));
                    }

                    $sent++;
                }
            });

        $this->info($this->option('dry-run')
            ? "{$sent} business(es) would be notified."
            : "Notified {$sent} business(es).");

        return self::SUCCESS;
    }
}
