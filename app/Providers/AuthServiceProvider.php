<?php

namespace App\Providers;

use App\Models\Artisan;
use App\Models\BusinessDocument;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Event;
use App\Models\Form;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\ArtisanPolicy;
use App\Policies\BusinessDocumentPolicy;
use App\Policies\ContactPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EventPolicy;
use App\Policies\FormPolicy;
use App\Policies\ItemPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ReceiptPolicy;
use App\Policies\TicketPolicy;
use App\Support\CurrentCompany;
use App\Support\Permissions;
use App\Support\PlanEntitlements;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Document::class => DocumentPolicy::class,
        Contact::class => ContactPolicy::class,
        Item::class => ItemPolicy::class,
        Payment::class => PaymentPolicy::class,
        Receipt::class => ReceiptPolicy::class,
        Artisan::class => ArtisanPolicy::class,
        BusinessDocument::class => BusinessDocumentPolicy::class,
        Form::class => FormPolicy::class,
        Event::class => EventPolicy::class,
        Ticket::class => TicketPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        /*
         * A gate per catalogued permission, named after it. Model-backed checks
         * go through the policies above; these cover the page-level abilities
         * with no model behind them ("can this user open Reports at all") and
         * the verb-only ones like `sales.issue`.
         */
        foreach (Permissions::slugs() as $ability) {
            Gate::define($ability, function (User $user) use ($ability) {
                $company = app(CurrentCompany::class)->get();

                // No company means no company-scoped permission, ever.
                if ($company === null) {
                    return false;
                }

                /*
                 * The partner programme is a property of the account, not of
                 * the person: a plain business has no client book to manage and
                 * no balance to withdraw, so no role inside it can reach these.
                 * Checked here rather than route-by-route so the navigation,
                 * the routes and the components all inherit one answer.
                 */
                if (str_starts_with($ability, 'partners.') && ! $company->isSecretariat()) {
                    return false;
                }

                return $user->hasPermissionIn($company, $ability);
            });
        }

        /*
         * Can this BUSINESS use this module at all? Plan denial is absolute and
         * comes before every other question: no role escalates past a plan the
         * business isn't paying for. A Basic-plan Owner is still a Basic-plan
         * Owner.
         *
         * Nothing else is answered here. Whether this PERSON may act is left to
         * the gates and policies below, including for the Owner — a `true`
         * returned from here would satisfy the whole check and skip the policy,
         * taking the ownership and immutability guards down with it. The Owner's
         * blanket grant lives in User::hasPermissionIn() instead, where it
         * answers the permission question without also answering the ones the
         * policies exist to ask.
         */
        Gate::before(function (User $user, string $ability) {
            $company = app(CurrentCompany::class)->get();

            if ($company === null) {
                return null;
            }

            if (! PlanEntitlements::allowsAbility($company, $ability)) {
                $module = Str::headline(explode('.', $ability, 2)[0] ?? $ability);
                $minPlan = Str::headline(PlanEntitlements::minimumPlanFor(explode('.', $ability, 2)[0] ?? '') ?? 'a higher plan');

                return Response::deny("{$module} isn't included in the {$company->plan} plan. Upgrade to {$minPlan} to use it.");
            }

            return null;
        });
    }
}
