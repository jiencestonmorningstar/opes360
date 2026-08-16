<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\RuleNotification;
use App\Support\NotificationChannels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The one gate every configured notification passes through.
 *
 * It does not send anything itself. It decides whether to, on which channels,
 * and then hands the result to `$user->notify()` — the same call the rest of
 * the product has always used. That matters more than it looks: a second
 * sender would have its own retries, its own idea of an unsubscribe, and its
 * own failures, and "why did this arrive twice" would have two places to look.
 *
 * The decisions, in order, and why each one exists:
 *
 *   1. **Is the channel connected?** An unconnected channel logs and stops. It
 *      must never look like it sent — that is the one failure a delivery log
 *      cannot recover from.
 *   2. **Does this person want it?** A mute is honoured, and the fact that it
 *      was honoured is written down, so "I never got told" has an answer.
 *   3. **Have we just said this?** The same news about the same record inside
 *      the dedupe window is dropped. A status field touched five times in a
 *      minute is five events and one piece of news.
 *   4. **Is now a bad time?** Quiet hours and digest mode hold the message
 *      instead of dropping it, and the digest command releases it later.
 *   5. **Send.**
 *
 * `critical` skips 2, 3 and 4. Exactly one severity may, and keeping it to one
 * is what makes the other levers safe to offer: a mute nobody can trust gets
 * replaced by a mail filter on the whole sender, which hides the critical ones
 * too.
 */
class NotificationDispatcher
{
    public function __construct(
        protected NotificationPreferences $preferences,
    ) {}

    /**
     * @param  Collection<int, User>  $recipients
     * @return int how many were actually delivered
     */
    public function send(NotificationMessage $message, Collection $recipients): int
    {
        $sent = 0;

        foreach ($recipients as $recipient) {
            foreach ($message->channels as $channel) {
                $sent += $this->sendOne($message, $recipient, $channel) ? 1 : 0;
            }
        }

        return $sent;
    }

    protected function sendOne(NotificationMessage $message, User $recipient, string $channel): bool
    {
        if (! NotificationChannels::isAvailable($channel)) {
            $this->log($message, $recipient, $channel, 'suppressed', 'channel_unavailable');

            return false;
        }

        if (! $message->isCritical()) {
            if (! $this->preferences->wants($recipient, $message->companyId, $message->category, $channel)) {
                $this->log($message, $recipient, $channel, 'suppressed', 'muted');

                return false;
            }

            if ($this->isRepeat($message, $recipient, $channel)) {
                $this->log($message, $recipient, $channel, 'suppressed', 'duplicate');

                return false;
            }

            if ($this->preferences->isQuiet($recipient, $message->companyId, $message->category, $channel)) {
                $this->log($message, $recipient, $channel, 'deferred', 'quiet_hours', release: $this->preferences
                    ->quietEndsAt($recipient, $message->companyId, $message->category, $channel));

                return false;
            }

            if ($this->preferences->mode($recipient, $message->companyId, $message->category, $channel) === 'digest') {
                /*
                 * No release time. The digest command decides when a summary
                 * goes out; pinning an hour here would mean the schedule was
                 * configured in two places and one of them would be wrong.
                 */
                $this->log($message, $recipient, $channel, 'deferred', 'digest');

                return false;
            }
        }

        return $this->deliver($message, $recipient, $channel);
    }

    /**
     * Hand it to Laravel, and write down what happened either way.
     *
     * A send that throws is logged as failed rather than allowed to escape.
     * The business event that caused this already happened — a notification
     * that stops an invoice being issued is worse than one that does not
     * arrive, and the caller must never learn that a mail server was down.
     */
    protected function deliver(NotificationMessage $message, User $recipient, string $channel): bool
    {
        try {
            $recipient->notify(new RuleNotification($message, $channel));
        } catch (Throwable $e) {
            report($e);
            $this->log($message, $recipient, $channel, 'failed', class_basename($e));

            return false;
        }

        $this->log($message, $recipient, $channel, 'sent');

        return true;
    }

    /** Have we said this to this person on this channel, recently enough? */
    protected function isRepeat(NotificationMessage $message, User $recipient, string $channel): bool
    {
        if ($message->dedupeMinutes <= 0) {
            return false;
        }

        return NotificationDelivery::query()
            ->withoutGlobalScopes()
            ->where('dedupe_key', $message->dedupeKey((int) $recipient->getKey(), $channel))
            ->whereIn('status', ['sent', 'deferred'])
            ->where('created_at', '>=', now()->subMinutes($message->dedupeMinutes))
            ->exists();
    }

    protected function log(
        NotificationMessage $message,
        User $recipient,
        string $channel,
        string $status,
        ?string $reason = null,
        ?Carbon $release = null,
    ): NotificationDelivery {
        return NotificationDelivery::query()->withoutGlobalScopes()->create([
            'company_id' => $message->companyId,
            'rule_id' => $message->ruleId,
            'user_id' => $recipient->getKey(),
            'event' => $message->event,
            'category' => $message->category,
            'severity' => $message->severity,
            'channel' => $channel,
            'status' => $status,
            'reason' => $reason,
            'title' => $message->title,
            'body' => $message->body,
            'url' => $message->url,
            'subject_type' => $message->subjectType,
            'subject_id' => $message->subjectId === null ? null : (string) $message->subjectId,
            'dedupe_key' => $message->dedupeKey((int) $recipient->getKey(), $channel),
            'release_at' => $release,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
