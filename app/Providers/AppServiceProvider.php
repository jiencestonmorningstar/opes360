<?php

namespace App\Providers;

use App\Models\AccountTransfer;
use App\Models\Artisan;
use App\Models\AssetTransfer;
use App\Models\BankAccount;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentShare;
use App\Models\Company;
use App\Models\CompanyUserPermission;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DeliveryNote;
use App\Models\Department;
use App\Models\Device;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\Expense;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimReimbursement;
use App\Models\ExpensePayment;
use App\Models\FiscalPeriod;
use App\Models\FixedAsset;
use App\Models\InsuranceClaim;
use App\Models\InsuranceEndorsement;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyRenewal;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentRun;
use App\Models\PaymentRunItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PerformanceReview;
use App\Models\PolicyCommission;
use App\Models\Position;
use App\Models\Project;
use App\Models\Property;
use App\Models\PurchaseRequisition;
use App\Models\Receipt;
use App\Models\Refund;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\TaxRate;
use App\Models\Tenancy;
use App\Models\TripManifest;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workflow;
use App\Models\WorkflowDecision;
use App\Models\WorkflowStep;
use App\Observers\AuditObserver;
use App\Search\GlobalSearch;
use App\Services\Documents\DocumentFieldRegistry;
use App\Support\Csp;
use App\Support\CurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
        // One nonce per request, shared by the header and every inline script.
        $this->app->singleton(Csp::class);
        // Singleton so providers registered in boot() below are the same
        // instance DocumentComposer resolves — a fresh instance per
        // app()->make() call would lose every registration between them.
        $this->app->singleton(DocumentFieldRegistry::class);
    }

    public function boot(): void
    {
        /*
         * Every listener of DomainEvent is registered by Laravel's event
         * DISCOVERY, which finds them from the `handle(DomainEvent $event)`
         * signature in app/Listeners. There are deliberately no
         * `Event::listen(DomainEvent::class, ...)` calls here.
         *
         * There were, and they were a real bug rather than harmless
         * belt-and-braces: discovery had already registered each one, so
         * every listener ran TWICE on every domain event. Automation rules
         * fired twice, which for a rule that sends a notification means two
         * notifications, and for a rule that writes a record means two
         * records. Nothing errored, which is why it survived.
         *
         * Adding a listener means putting the class in app/Listeners with
         * that signature. It does not mean adding a line here.
         * `DomainEventListenersAreNotDoubleRegisteredTest` pins this.
         */

        // Search entries follow their records; without this only the reindex
        // command populates the index and every edit goes quietly stale.
        GlobalSearch::observe();

        $this->registerDocumentFieldProviders();

        // Fail loudly in development on lazy loads and bad attribute assignment,
        // rather than shipping N+1 queries to a phone on a slow connection.
        /*
         * The API's rate limit, keyed on the token rather than the user or the
         * IP. Per user, one runaway integration would throttle that person's
         * own app session; per IP, every business behind one office NAT would
         * share a bucket. The token is the thing whose behaviour is being
         * limited, so it is the thing counted.
         *
         * The instanceof check is load-bearing: a session-authenticated request
         * carries a TransientToken, which has no id at all, and reaching for one
         * throws rather than returning null. Those fall back to the user.
         */
        RateLimiter::for('api', function ($request) {
            $token = $request->user()?->currentAccessToken();

            $key = match (true) {
                $token instanceof PersonalAccessToken => 'token:'.$token->getKey(),
                $request->user() !== null => 'user:'.$request->user()->getAuthIdentifier(),
                default => 'ip:'.$request->ip(),
            };

            return Limit::perMinute(120)->by($key);
        });

        /*
         * Lazy-load prevention is on everywhere — but production swaps the
         * exception for a log line. Throwing in production would turn every
         * N+1 that slipped past dev into a user-facing 500; staying silent
         * (the old behaviour) meant those regressions shipped invisibly.
         * Logging is the middle path: the page still renders, and the log
         * names the model and relation so the missing eager-load is a
         * one-line fix instead of a profiling session.
         */
        Model::preventLazyLoading();

        if ($this->app->isProduction()) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
                Log::warning('Lazy-loading violation: add an eager load.', [
                    'model' => get_class($model),
                    'relation' => $relation,
                ]);
            });
        }

        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Vite::prefetch(concurrency: 3);

        /*
         * Content Security Policy plumbing. `@cspNonce` marks the handful of
         * author-written inline scripts as trusted; Livewire and Vite are told
         * the same nonce so their injected tags carry it too. Anything injected
         * by an attacker has no nonce and therefore does not run.
         */
        Blade::directive('cspNonce', fn () => "<?php echo app(\App\Support\Csp::class)->attribute(); ?>");

        // Resolved here rather than lazily because both APIs take a value, not
        // a callback. boot() runs once per request under PHP-FPM, so the nonce
        // is still per-request; a long-lived worker (Octane) would need this
        // moved into a request-scoped listener.
        $nonce = app(Csp::class)->nonce();

        Livewire::useScriptTagAttributes(['nonce' => $nonce]);
        Vite::useCspNonce($nonce);

        Date::use(Carbon::class);

        // Token-styled pagination; the framework default hardcodes grays that
        // break in dark mode.
        Paginator::defaultView('pagination.opes');
        Paginator::defaultSimpleView('pagination.opes');

        /*
         * Audit trail. Deliberately a fixed list rather than every model: these
         * are the records where "who changed this, and from what" has to be
         * answerable. Logging line items or stock movements as well would bury
         * that signal in churn, and both are already immutable or append-only.
         */
        foreach ([
            Company::class, User::class, Contact::class, Item::class,
            Document::class, Payment::class, Receipt::class, Artisan::class,
            BusinessDocument::class,

            // Money that moves, and the decisions that release it.
            Payslip::class, PayrollRun::class,
            PaymentRun::class, PaymentRunItem::class,
            Expense::class, ExpensePayment::class,
            ExpenseClaim::class, ExpenseClaimReimbursement::class,
            Refund::class,
            BankAccount::class, AccountTransfer::class,

            // The books, where a restatement leaves no trace of its own.
            // JournalLine is left out: an entry is balanced and immutable
            // once posted, so the header carries the story and the lines
            // would treble the rows for nothing.
            JournalEntry::class, LedgerAccount::class, TaxRate::class, FiscalPeriod::class,

            // Permissions and the controls over them. CompanyUserPermission
            // was the blind spot that mattered most — a granted ability left
            // no trace at all. WorkflowStep belongs here because editing an
            // approval rule is the same act as authorising the spend.
            Role::class, CompanyUserPermission::class,
            Workflow::class, WorkflowStep::class, WorkflowDecision::class,
            WebhookEndpoint::class,

            // A person's record: somebody's livelihood or reputation.
            Employee::class, EmploymentContract::class, SalaryComponent::class,
            LeaveRequest::class, PerformanceReview::class, Position::class, Department::class,

            // The verticals' commitments: cover promised, stock promised to a
            // customer, cargo entrusted, somebody's home and their deposit.
            InsurancePolicy::class, InsuranceClaim::class,
            PolicyCommission::class, InsurancePolicyRenewal::class, InsuranceEndorsement::class,
            SalesOrder::class, DeliveryNote::class,
            Shipment::class, TripManifest::class,
            Property::class, Tenancy::class,

            // Commitments made, and custody of things.
            FixedAsset::class, AssetTransfer::class,
            PurchaseRequisition::class, Rfq::class, RfqSupplier::class,
            Contract::class,
            Deal::class, Lead::class, Project::class,
            BusinessDocumentShare::class, Device::class,
        ] as $model) {
            $model::observe(AuditObserver::class);
        }
    }

    /**
     * The default document field providers — §7 of the master spec.
     *
     * `company` is every field that used to be hard-coded inside
     * DocumentComposer::automaticValues(); moving it here changed where the
     * values come from, not what they are, so no existing template broke.
     * `customer`, `employee` and `project` are new: they read from whatever
     * the caller already has in hand when composing (§67 — a document
     * started from a customer already knows its customer), and contribute
     * nothing when nothing was supplied. Any future module registers its own
     * provider the same way, here or in its own service provider.
     */
    protected function registerDocumentFieldProviders(): void
    {
        $registry = $this->app->make(DocumentFieldRegistry::class);

        $registry->register('company', function (Company $company): array {
            $addressLine = collect([
                $company->address_line1,
                $company->address_line2,
                $company->city,
                $company->region,
                $company->country,
            ])->filter()->implode(', ');

            return [
                'company.name' => (string) $company->name,
                'company.address' => $addressLine,
                'company.email' => (string) ($company->email ?? ''),
                'company.phone' => (string) data_get($company->phones, 0, ''),
                'today' => now()->format('j F Y'),
            ];
        });

        $registry->register('customer', function (Company $company, array $context): array {
            $customer = $context['customer'] ?? null;

            if (! $customer instanceof Contact) {
                return [];
            }

            return [
                'customer.name' => (string) $customer->name,
                'customer.email' => (string) ($customer->email ?? ''),
            ];
        });

        $registry->register('employee', function (Company $company, array $context): array {
            $employee = $context['employee'] ?? null;

            if (! $employee instanceof Employee) {
                return [];
            }

            return [
                'employee.name' => $employee->name(),
                'employee.job_title' => (string) ($employee->job_title ?? ''),
                'employee.department' => (string) ($employee->department ?? ''),
            ];
        });

        $registry->register('project', function (Company $company, array $context): array {
            $project = $context['project'] ?? null;

            if (! $project instanceof Project) {
                return [];
            }

            return [
                'project.name' => (string) $project->name,
                'project.code' => (string) ($project->code ?? ''),
            ];
        });
    }
}
