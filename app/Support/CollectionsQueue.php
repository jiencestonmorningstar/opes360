<?php

namespace App\Support;

use App\Enums\DocumentType;
use App\Models\CollectionActivity;
use App\Models\Document;
use App\Models\DunningReminder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Overdue accounts, in the order somebody should work through them.
 *
 * The aging report already says who is late. What it cannot say is who to ring
 * first, who was rung yesterday, and who promised to pay on Friday and did not
 * — with the result that the same three names get chased twice a week and the
 * rest are never chased at all. This is the working queue that sits on top of
 * the aging report.
 *
 * ── The ordering, which is the whole feature ───────────────────────────────
 *
 * Three bands, then a score inside each.
 *
 *  1. Broken promises. Somebody gave a date and it passed. This is the single
 *     strongest signal in collections and the cheapest call to make, because
 *     the conversation is already open.
 *  2. Everyone nobody has an arrangement with.
 *  3. Open promises — a date that has not arrived yet. Still owed, still
 *     visible, but ringing somebody on Thursday about a Friday promise is how
 *     a business loses a customer it was about to be paid by.
 *
 * Inside a band the score is the overdue amount weighted by age. Old money
 * outranks merely large money: a million a week late will very likely arrive;
 * two hundred thousand at 150 days is the one about to become a bad debt.
 *
 * Nothing is stored. Like the aging report this is a photograph of a moment;
 * the only thing worth persisting is what a human did about it, which is
 * `collection_activities`.
 */
class CollectionsQueue
{
    /**
     * Age multipliers applied to the overdue amount, by aging bucket.
     *
     * Steep on purpose. A linear weighting would let any large recent debt sit
     * above every ancient one, which is exactly the behaviour the queue exists
     * to prevent.
     */
    public const AGE_WEIGHTS = [
        '1_30' => 1.0,
        '31_60' => 2.0,
        '61_90' => 4.0,
        'over_90' => 8.0,
    ];

    public const FLAG_BROKEN_PROMISE = 'broken_promise';

    public const FLAG_PROMISED = 'promised';

    public const FLAG_NONE = null;

    public function __construct(protected ?CarbonInterface $asOf = null) {}

    public function asOf(): CarbonInterface
    {
        return Carbon::parse($this->asOf ?? Carbon::now())->startOfDay();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function accounts(): Collection
    {
        $asOf = $this->asOf();
        $aging = new Aging($asOf);

        $open = Document::query()
            ->outstanding()
            ->whereIn('type', [
                DocumentType::Invoice->value,
                DocumentType::DebitNote->value,
                // Credit notes are pulled in only so they can be netted off
                // below. `outstanding()` does not distinguish by type, and a
                // credit note has an open balance by construction — counting
                // one as a receivable would have the business chasing a
                // customer for money it had itself written off.
                DocumentType::CreditNote->value,
            ])
            ->whereNotNull('contact_id')
            ->with('contact')
            ->get();

        if ($open->isEmpty()) {
            return collect();
        }

        $reminders = $this->latestReminders($open->pluck('contact_id')->unique()->all());
        $activities = $this->latestActivities($open->pluck('contact_id')->unique()->all());
        $promises = $this->openPromises($open->pluck('contact_id')->unique()->all());

        $rows = $open
            ->groupBy('contact_id')
            ->map(function (Collection $documents, string $contactId) use ($aging, $asOf, $reminders, $activities, $promises) {
                $overdue = 0.0;
                $notYetDue = 0.0;
                $credit = 0.0;
                $score = 0.0;
                $oldest = 0;
                $buckets = array_fill_keys(array_keys(Aging::BUCKETS), 0.0);
                $items = [];

                foreach ($documents as $document) {
                    $balance = round((float) $document->balance, 2);

                    if ($document->type->isCreditNote()) {
                        $credit += $balance;

                        continue;
                    }

                    $days = $aging->daysOverdue($document->due_date ?? $document->issue_date);
                    $bucket = $aging->bucketFor($days);

                    $buckets[$bucket] += $balance;

                    if ($days > 0) {
                        $overdue += $balance;
                        $score += $balance * (self::AGE_WEIGHTS[$bucket] ?? 1.0);
                        $oldest = max($oldest, $days);
                    } else {
                        $notYetDue += $balance;
                    }

                    $items[] = [
                        'id' => $document->id,
                        'number' => $document->number,
                        'type' => $document->type,
                        'due_date' => $document->due_date ?? $document->issue_date,
                        'days_overdue' => $days,
                        'bucket' => $bucket,
                        'balance' => $balance,
                    ];
                }

                if ($overdue <= 0) {
                    // Not a collections problem. It may still be an aging one,
                    // and the aging report is where it belongs.
                    return null;
                }

                usort($items, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

                $promise = $promises[$contactId] ?? null;
                $reminder = $reminders[$contactId] ?? null;
                $activity = $activities[$contactId] ?? null;

                $flag = match (true) {
                    $promise === null => self::FLAG_NONE,
                    $promise->promised_at->startOfDay()->lt($asOf) => self::FLAG_BROKEN_PROMISE,
                    default => self::FLAG_PROMISED,
                };

                $contact = $documents->first()->contact;

                return [
                    'party_id' => $contactId,
                    'party' => $contact?->displayName() ?? 'Unknown customer',
                    'contact' => $contact,
                    'email' => $contact?->email,
                    'phone' => $contact?->phone,
                    'overdue_total' => round($overdue, 2),
                    'not_yet_due_total' => round($notYetDue, 2),
                    'open_total' => round($overdue + $notYetDue, 2),
                    'credit_available' => round($credit, 2),
                    // What is genuinely worth chasing: the overdue debt after
                    // any credit the customer already holds is set against it.
                    'net_exposure' => round(max(0, $overdue - $credit), 2),
                    'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
                    'oldest_days' => $oldest,
                    'documents' => $items,
                    'flag' => $flag,
                    'promise' => $promise,
                    'promised_at' => $promise?->promised_at,
                    'promised_amount' => $promise === null ? null : (float) $promise->promised_amount,
                    'last_reminder_at' => $reminder?->sent_at,
                    'last_reminder_step' => $reminder?->step,
                    'last_activity' => $activity,
                    'days_since_contact' => $activity === null
                        ? null
                        : (int) $activity->happened_at->copy()->startOfDay()->diffInDays($asOf, false),
                    'priority' => round($score, 2),
                    'band' => $this->band($flag),
                ];
            })
            ->filter()
            ->values();

        return $rows
            ->sortBy([
                fn (array $a, array $b) => $b['band'] <=> $a['band'],
                fn (array $a, array $b) => $b['priority'] <=> $a['priority'],
            ])
            ->values();
    }

    /** Totals across the whole queue, for the header. */
    public function summary(?Collection $rows = null): array
    {
        $rows ??= $this->accounts();

        return [
            'accounts' => $rows->count(),
            'overdue_total' => round((float) $rows->sum('overdue_total'), 2),
            'net_exposure' => round((float) $rows->sum('net_exposure'), 2),
            'broken_promises' => $rows->where('flag', self::FLAG_BROKEN_PROMISE)->count(),
            'never_contacted' => $rows->whereNull('last_activity')->count(),
        ];
    }

    protected function band(?string $flag): int
    {
        return match ($flag) {
            self::FLAG_BROKEN_PROMISE => 2,
            self::FLAG_PROMISED => 0,
            default => 1,
        };
    }

    /**
     * The most recent promise per customer that has not been superseded.
     *
     * Latest wins: a customer who breaks a promise and then gives a new date
     * has an open promise again, and chasing them on the old one is a
     * conversation that has already happened.
     *
     * @param  array<int, string>  $contactIds
     * @return array<string, CollectionActivity>
     */
    protected function openPromises(array $contactIds): array
    {
        return CollectionActivity::query()
            ->promises()
            ->whereIn('contact_id', $contactIds)
            ->orderBy('happened_at')
            ->get()
            ->keyBy('contact_id')
            ->all();
    }

    /**
     * @param  array<int, string>  $contactIds
     * @return array<string, DunningReminder>
     */
    protected function latestReminders(array $contactIds): array
    {
        return DunningReminder::query()
            ->whereIn('contact_id', $contactIds)
            ->orderBy('sent_at')
            ->get()
            ->keyBy('contact_id')
            ->all();
    }

    /**
     * @param  array<int, string>  $contactIds
     * @return array<string, CollectionActivity>
     */
    protected function latestActivities(array $contactIds): array
    {
        return CollectionActivity::query()
            ->whereIn('contact_id', $contactIds)
            ->with('user')
            ->orderBy('happened_at')
            ->get()
            ->keyBy('contact_id')
            ->all();
    }
}
