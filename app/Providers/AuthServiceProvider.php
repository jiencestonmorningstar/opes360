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
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

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
                return $company !== null && $user->hasPermissionIn($company, $ability);
            });
        }

        /*
         * The Owner is the account's ultimate authority and is never locked out
         * of their own business — including from the screens that would let them
         * fix a permission mistake.
         */
        Gate::before(function (User $user, string $ability) {
            $company = app(CurrentCompany::class)->get();

            if ($company === null) {
                return null;
            }

            return $user->roleIn($company)?->slug === 'owner' ? true : null;
        });
    }
}
