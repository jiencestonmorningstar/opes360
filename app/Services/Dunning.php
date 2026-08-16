<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DunningReminder;
use App\Notifications\InvoiceOverdueNotification;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Chasing overdue invoices, politely and exactly once per step.
 *
 * A ladder of reminders at fixed days past due. The rungs are the point: an
 * invoice seven days late and one ninety days late are different conversations,
 * and a system that sends the same message for both teaches customers to ignore
 * both.
 *
 * Every send is written to `dunning_reminders` with a unique key on
 * (document, step). That table is not a log kept for interest — it is the
 * mechanism. Without it a nightly sweep would re-send the "30 days" reminder
 * every night for a month, and a customer chased daily stops reading anything
 * the business sends.
 *
 * Off unless a company turns it on. These messages go out under the business's
 * name to its own customers, about invoices that may well have been settled in
 * cash without anybody recording it yet.
 */
class Dunning
{
    /** Days past due at which a reminder fires, unless a company says otherwise. */
    public const DEFAULT_STEPS = [7, 30, 60];

    /**
     * Below this, chasing costs more than it recovers.
     *
     * A reminder about six hundred francs annoys a customer the business wants
     * to keep, for less than the cost of the conversation it starts.
     */
    public const DEFAULT_MINIMUM = 1000.0;

    /**
     * @return array{sent: int, companies: int, skipped: int}
     */
    public function run(?Carbon $on = null): array
    {
        $on ??= now();

        $sent = 0;
        $skipped = 0;
        $companies = 0;

        $active = Company::query()
            ->whereNotNull('dunning')
            ->get()
            ->filter(fn (Company $c) => $this->settingsFor($c)['enabled']);

        foreach ($active as $company) {
            $companies++;

            $result = app(CurrentCompany::class)->as(
                $company,
                fn () => $this->runForCompany($company, $on),
            );

            $sent += $result['sent'];
            $skipped += $result['skipped'];
        }

        return ['sent' => $sent, 'companies' => $companies, 'skipped' => $skipped];
    }

    /**
     * @return array{sent: int, skipped: int}
     */
    public function runForCompany(Company $company, ?Carbon $on = null): array
    {
        $on ??= now();
        $settings = $this->settingsFor($company);

        $sent = 0;
        $skipped = 0;

        $documents = Document::query()
            ->receivables()
            ->outstanding()
            ->with('contact')
            ->get();

        foreach ($documents as $document) {
            $due = $document->due_date ?? $document->issue_date;

            if ($due === null) {
                continue;
            }

            $days = (int) $due->copy()->startOfDay()->diffInDays($on->copy()->startOfDay(), false);

            $step = $this->stepFor($days, $settings['steps']);

            if ($step === null) {
                continue;
            }

            if ((float) $document->balance < $settings['minimum']) {
                $skipped++;

                continue;
            }

            $contact = $document->contact;

            if ($contact === null || blank($contact->email)) {
                $skipped++;

                continue;
            }

            if ($this->send($company, $document, $contact, $step, $days)) {
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * The highest rung this invoice has reached.
     *
     * Highest rather than exact, so an invoice that slips past a rung — the
     * sweep did not run that night, the invoice was created already overdue —
     * still gets chased rather than falling through the gaps forever.
     *
     * @param  array<int, int>  $steps
     */
    public function stepFor(int $daysOverdue, array $steps): ?int
    {
        $reached = array_filter($steps, fn (int $s) => $daysOverdue >= $s);

        return $reached === [] ? null : max($reached);
    }

    /**
     * Record first, then send.
     *
     * The unique key on (document, step) is what makes this safe to run twice.
     * Writing the row first means a duplicate is refused by the database before
     * any mail leaves — the other order would send the message and then discover
     * it was a repeat.
     */
    protected function send(Company $company, Document $document, Contact $contact, int $step, int $days): bool
    {
        try {
            DunningReminder::create([
                'company_id' => $company->id,
                'document_id' => $document->id,
                'contact_id' => $contact->id,
                'step' => $step,
                'days_overdue' => $days,
                'balance' => $document->balance,
                'channel' => 'mail',
                'sent_to' => $contact->email,
                'sent_at' => now(),
            ]);
        } catch (QueryException) {
            // Already chased at this rung. Not an error — the expected outcome
            // of a sweep running a second time in one day.
            return false;
        }

        Notification::route('mail', $contact->email)
            ->notify(new InvoiceOverdueNotification($document, $company, $step, $days));

        return true;
    }

    /**
     * @return array{enabled: bool, steps: array<int, int>, minimum: float}
     */
    public function settingsFor(Company $company): array
    {
        $stored = is_array($company->dunning) ? $company->dunning : [];

        $steps = collect($stored['steps'] ?? self::DEFAULT_STEPS)
            ->map(fn ($s) => (int) $s)
            ->filter(fn (int $s) => $s > 0 && $s <= 365)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            // A ladder with no rungs would chase nothing while looking switched
            // on, which is the most confusing possible state.
            'steps' => $steps === [] ? self::DEFAULT_STEPS : $steps,
            'minimum' => max(0.0, (float) ($stored['minimum'] ?? self::DEFAULT_MINIMUM)),
        ];
    }
}
