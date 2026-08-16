<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    /**
     * Audit access is itself a sensitive permission.
     *
     * `audit.view` is sight of who touched what, which includes every salary
     * change, every customer that was deleted and every restricted document
     * somebody opened. It is a strictly more powerful view than any single
     * module's `view`: a person denied `payroll.view` can reconstruct a good
     * deal of the payroll from the audit trail's before/after diffs. So it is
     * not implied by anything and is granted by hand.
     *
     * `audit.govern` is separate because the governance report is a map of
     * where the business's controls are weakest — the single most useful
     * document to anyone planning to abuse them. Reading the history and
     * reading the attack surface are different trusts.
     *
     * Neither is ever granted to the subject of the trail by virtue of being an
     * administrator of something else: the point of an audit log is that the
     * people it constrains cannot curate it.
     */
    public const ABILITIES = ['audit.view', 'audit.govern'];

    public function boot(): void
    {
        foreach (self::ABILITIES as $ability) {
            /*
             * Defined here only while these live outside Permissions::CATALOGUE.
             * AuthServiceProvider defines a gate for every catalogued slug, and
             * `Gate::has` lets that definition win the moment the ability is
             * added to the catalogue — so this file never has to be deleted in
             * the same commit, and the two can never both be authoritative.
             */
            if (Gate::has($ability)) {
                continue;
            }

            Gate::define($ability, function (User $user) use ($ability) {
                $company = app(CurrentCompany::class)->get();

                if ($company === null) {
                    return false;
                }

                return $user->hasPermissionIn($company, $ability);
            });
        }
    }
}
