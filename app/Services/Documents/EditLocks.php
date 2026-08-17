<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\User;

/**
 * Soft edit-locking: one person writes, everyone else reads.
 *
 * Soft, because the lock is advisory and self-healing — it exists to stop two
 * people silently overwriting each other, not to imprison a document behind a
 * crashed browser tab. A holder proves liveness by heartbeat; a lock whose
 * heartbeat is older than STALE_SECONDS is free to take, no administrator
 * involved. The lock rides on two columns of the document row itself, updated
 * with a guarded UPDATE ... WHERE so two simultaneous claims cannot both win.
 *
 * All writes here are updateQuietly(): lock state is presence, not content —
 * it must not tick updated_at semantics through the versioner or trip the
 * issued-document guard (an issued document can legitimately hold a stale
 * lock left over from its drafting days).
 */
class EditLocks
{
    /**
     * Three missed 30-second heartbeats. Short enough that a crashed tab
     * frees the document within a coffee-pour; long enough that one dropped
     * poll doesn't bounce the writer out.
     */
    public const STALE_SECONDS = 90;

    /** Claim the lock if it is free, already ours, or stale. True on success. */
    public function claim(BusinessDocument $document, User $user): bool
    {
        if ($this->heldBy($document, $user)) {
            $this->heartbeat($document, $user);

            return true;
        }

        if ($this->heldByAnother($document, $user)) {
            return false;
        }

        // Free or stale. Guarded update: only wins if the row still shows the
        // state we just read, so two racing claims resolve to one winner.
        $won = BusinessDocument::query()
            ->whereKey($document->getKey())
            ->where(function ($q) {
                $q->whereNull('editing_user_id')
                    ->orWhereNull('editing_heartbeat_at')
                    ->orWhere('editing_heartbeat_at', '<', now()->subSeconds(self::STALE_SECONDS));
            })
            ->update(['editing_user_id' => $user->id, 'editing_heartbeat_at' => now()]);

        $document->refresh();

        return $won === 1 && (int) $document->editing_user_id === (int) $user->id;
    }

    /** Refresh the heartbeat. Only the holder's beats count. */
    public function heartbeat(BusinessDocument $document, User $user): void
    {
        BusinessDocument::query()
            ->whereKey($document->getKey())
            ->where('editing_user_id', $user->id)
            ->update(['editing_heartbeat_at' => now()]);

        $document->refresh();
    }

    /** Release, but only if we hold it — never yank someone else's lock. */
    public function release(BusinessDocument $document, User $user): void
    {
        BusinessDocument::query()
            ->whereKey($document->getKey())
            ->where('editing_user_id', $user->id)
            ->update(['editing_user_id' => null, 'editing_heartbeat_at' => null]);

        $document->refresh();
    }

    public function heldBy(BusinessDocument $document, User $user): bool
    {
        return $this->live($document) && (int) $document->editing_user_id === (int) $user->id;
    }

    public function heldByAnother(BusinessDocument $document, User $user): bool
    {
        return $this->live($document) && (int) $document->editing_user_id !== (int) $user->id;
    }

    /** Whoever holds a live lock, or null. */
    public function holder(BusinessDocument $document): ?User
    {
        return $this->live($document) ? User::find($document->editing_user_id) : null;
    }

    protected function live(BusinessDocument $document): bool
    {
        return $document->editing_user_id !== null
            && $document->editing_heartbeat_at !== null
            && $document->editing_heartbeat_at->gt(now()->subSeconds(self::STALE_SECONDS));
    }
}
