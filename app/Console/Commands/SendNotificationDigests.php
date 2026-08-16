<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\NotificationDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Releases everything that was held back.
 *
 * One command for both reasons a message gets held — a digest preference and
 * quiet hours — because they are the same thing seen twice: a message that was
 * worth sending at a moment that was not worth interrupting. Two commands
 * would need two schedules, and the pair would drift.
 *
 * A quiet-hours row carries a release time and waits for it. A digest row
 * carries none and goes out on whatever schedule this command runs on, so the
 * frequency of a summary is configured in one place — the scheduler — rather
 * than baked into every row at the moment it was written.
 *
 * Rows are marked sent before the notification is queued, deliberately. A
 * second run of the command while the first is still working must not send the
 * same summary twice; a duplicated digest is exactly the noise the digest
 * exists to prevent.
 */
class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:digest';

    protected $description = 'Send held-back notifications as one summary per person';

    public function handle(): int
    {
        $due = NotificationDelivery::query()
            ->withoutGlobalScopes()
            ->due()
            ->orderBy('created_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing held back.');

            return self::SUCCESS;
        }

        $sent = 0;

        /*
         * Grouped by person and channel, not by company. Somebody who works
         * across two businesses gets one summary, not two — the count is what
         * makes a digest worth having, and splitting it by tenant would put
         * the count back up.
         */
        foreach ($due->groupBy(fn (NotificationDelivery $d) => $d->user_id.'|'.$d->channel) as $group) {
            $sent += $this->release($group) ? 1 : 0;
        }

        $this->info("Sent {$sent} summaries covering {$due->count()} notifications.");

        return self::SUCCESS;
    }

    /** @param  Collection<int, NotificationDelivery>  $group */
    protected function release(Collection $group): bool
    {
        $first = $group->first();
        $user = User::find($first->user_id);

        if ($user === null) {
            // The person is gone. Close the rows so they stop being retried.
            $this->close($group, 'failed', 'recipient_gone');

            return false;
        }

        $this->close($group, 'sent', 'digest_released');

        try {
            $user->notify(new NotificationDigest($group->values(), (string) $first->channel));
        } catch (Throwable $e) {
            report($e);
            $this->close($group, 'failed', 'digest_failed');

            return false;
        }

        return true;
    }

    /** @param  Collection<int, NotificationDelivery>  $group */
    protected function close(Collection $group, string $status, string $reason): void
    {
        NotificationDelivery::query()
            ->withoutGlobalScopes()
            ->whereKey($group->pluck('id')->all())
            ->update([
                'status' => $status,
                'reason' => $reason,
                'sent_at' => $status === 'sent' ? now() : null,
                'updated_at' => now(),
            ]);
    }
}
