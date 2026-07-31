<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;

/**
 * Fans an event notification out to every active member of a company who
 * holds a given permission — the "who in this business should hear about
 * this" question, answered in one place instead of at every call site.
 */
class NotifyCompany
{
    public static function about(Company $company, string $permission, Notification $notification): void
    {
        static::recipients($company, $permission)->each->notify($notification);
    }

    /**
     * The active members of a company who hold a given permission.
     *
     * Callers that need to notify the same audience about several events in
     * one request (e.g. selling several tickets at once) should resolve this
     * once and reuse it, rather than calling about() in a loop — each call
     * re-runs the company-membership query and permission check from scratch.
     *
     * @return Collection<int, User>
     */
    public static function recipients(Company $company, string $permission): Collection
    {
        return $company->users()
            ->wherePivot('status', 'active')
            ->get()
            ->filter(fn (User $user) => $user->hasPermissionIn($company, $permission))
            ->values();
    }
}
