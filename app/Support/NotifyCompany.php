<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
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
        $company->users()
            ->wherePivot('status', 'active')
            ->get()
            ->filter(fn (User $user) => $user->hasPermissionIn($company, $permission))
            ->each->notify($notification);
    }
}
