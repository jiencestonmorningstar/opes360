<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Reads one person's settings and answers three questions about them.
 *
 * Rows go from broad to narrow — blanket, then category, then category and
 * channel — and the narrowest row that has an opinion wins. A row that says
 * nothing about a setting does not overrule a broader row that does, which is
 * why `enabled` and `mode` are nullable: somebody setting quiet hours on the
 * "money" category must not thereby switch money notifications on.
 *
 * Defaults are permissive: with no rows at all, everything is on, immediate,
 * and there are no quiet hours. A business that has never opened the screen
 * should behave the way it did before the screen existed.
 */
class NotificationPreferences
{
    /** Rows for one person in one company, resolved once per request. */
    protected array $cache = [];

    public function wants(User $user, string $companyId, string $category, string $channel): bool
    {
        return (bool) ($this->setting($user, $companyId, $category, $channel, 'enabled') ?? true);
    }

    public function mode(User $user, string $companyId, string $category, string $channel): string
    {
        $mode = $this->setting($user, $companyId, $category, $channel, 'mode');

        return in_array($mode, ['immediate', 'digest'], true) ? $mode : 'immediate';
    }

    /**
     * Is now inside the hours this person asked not to be interrupted?
     *
     * A window that wraps midnight — 22:00 to 07:00, the usual one — is two
     * ranges, not one. Comparing it as a single range would make it always
     * false, and quiet hours that never trigger are the kind of bug nobody
     * reports because it looks like the feature working.
     */
    public function isQuiet(User $user, string $companyId, string $category, string $channel, ?Carbon $at = null): bool
    {
        $from = $this->setting($user, $companyId, $category, $channel, 'quiet_from');
        $to = $this->setting($user, $companyId, $category, $channel, 'quiet_to');

        if (! is_string($from) || ! is_string($to) || $from === $to) {
            return false;
        }

        $now = ($at ?? now())->format('H:i:s');
        $from = substr($from, 0, 8);
        $to = substr($to, 0, 8);

        return $from < $to
            ? ($now >= $from && $now < $to)
            : ($now >= $from || $now < $to);
    }

    /** When a message held by quiet hours becomes due. */
    public function quietEndsAt(User $user, string $companyId, string $category, string $channel, ?Carbon $at = null): ?Carbon
    {
        $to = $this->setting($user, $companyId, $category, $channel, 'quiet_to');

        if (! is_string($to)) {
            return null;
        }

        [$hour, $minute] = array_pad(explode(':', $to), 2, '0');

        $now = ($at ?? now())->copy();
        $end = $now->copy()->setTime((int) $hour, (int) $minute, 0);

        return $end->lessThanOrEqualTo($now) ? $end->addDay() : $end;
    }

    /** The narrowest row that has an opinion about this setting. */
    protected function setting(User $user, string $companyId, string $category, string $channel, string $key): mixed
    {
        $rows = $this->rowsFor($user, $companyId);

        $scopes = [
            [$category, $channel],
            [$category, ''],
            ['', $channel],
            ['', ''],
        ];

        foreach ($scopes as [$scopeCategory, $scopeChannel]) {
            $row = $rows[$scopeCategory.'|'.$scopeChannel] ?? null;

            if ($row !== null && $row->getAttribute($key) !== null) {
                return $row->getAttribute($key);
            }
        }

        return null;
    }

    /** @return array<string, NotificationPreference> */
    protected function rowsFor(User $user, string $companyId): array
    {
        $cacheKey = $companyId.'|'.$user->getKey();

        if (! isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = NotificationPreference::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('user_id', $user->getKey())
                ->get()
                ->keyBy(fn (NotificationPreference $p) => $p->category.'|'.$p->channel)
                ->all();
        }

        return $this->cache[$cacheKey];
    }

    /** Preferences change mid-request when somebody edits their own screen. */
    public function forget(User $user, string $companyId): void
    {
        unset($this->cache[$companyId.'|'.$user->getKey()]);
    }
}
