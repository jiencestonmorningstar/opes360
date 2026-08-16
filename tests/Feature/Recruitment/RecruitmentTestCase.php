<?php

namespace Tests\Feature\Recruitment;

use App\Events\DomainEvent;
use App\Http\Controllers\VacancyPublicController;
use App\Listeners\MarkApprovedJobOffers;
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\JobOffer;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\Workflow;
use App\Services\Recruitment\JobOffers;
use App\Services\Recruitment\RecruitmentPipeline;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One company, one position, one owner — everything a vacancy needs to exist.
 */
abstract class RecruitmentTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        // Recruitment ships off — most businesses here hire by word of mouth
        // once a year. Off means every recruitment.* gate denies, so the
        // module under test has to be switched on for the business under test.
        $this->company->forceFill(['modules' => ['recruitment' => true]])->save();
        Modules::flush();

        app(CurrentCompany::class)->set($this->company);

        /*
         * Registered here because AppServiceProvider is the orchestrator's to
         * edit, not this feature's. Discovery may register it a second time;
         * the listener only acts on a `pending` offer, so a double run writes
         * once — the same idempotence bargain ActivateApprovedContracts makes.
         */
        Event::listen(DomainEvent::class, MarkApprovedJobOffers::class);

        /*
         * The recruitment abilities are not in App\Support\Permissions yet —
         * that file belongs to the orchestrator. These definitions are exactly
         * what AuthServiceProvider's loop will produce once the slugs land in
         * the catalogue: the ability delegates to the user's role in the
         * current company, so the owner passes and an outsider does not.
         */
        foreach (['recruitment.view', 'recruitment.manage', 'recruitment.interview', 'recruitment.offer'] as $ability) {
            Gate::define($ability, function (User $user) use ($ability) {
                $company = app(CurrentCompany::class)->get();

                return $company !== null && $user->hasPermissionIn($company, $ability);
            });
        }

        /*
         * The public routes the orchestrator will register in routes/web.php.
         * Declared here so the feature can be proven end-to-end over HTTP —
         * the exact same three lines appear in the handoff document.
         */
        Route::middleware('web')->group(function () {
            Route::get('/jobs/{token}', [VacancyPublicController::class, 'show'])->name('vacancy.public');
            Route::post('/jobs/{token}', [VacancyPublicController::class, 'submit'])->name('vacancy.public.submit');
            Route::get('/jobs/{token}/thanks', [VacancyPublicController::class, 'thanks'])->name('vacancy.public.thanks');
        });

        $this->position = Position::create([
            'company_id' => $this->company->id,
            'title' => 'Delivery Driver',
            'is_active' => true,
        ]);
    }

    protected function pipeline(): RecruitmentPipeline
    {
        return app(RecruitmentPipeline::class);
    }

    protected function offers(): JobOffers
    {
        return app(JobOffers::class);
    }

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function vacancy(array $overrides = []): Vacancy
    {
        return Vacancy::create(array_merge([
            'company_id' => $this->company->id,
            'position_id' => $this->position->id,
            'openings' => 1,
            'status' => 'open',
            'share_token' => Vacancy::newShareToken(),
            'created_by' => $this->owner->id,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    protected function application(?Vacancy $vacancy = null, array $overrides = []): JobApplication
    {
        return $this->pipeline()->apply($vacancy ?? $this->vacancy(), array_merge([
            'first_name' => 'Jean',
            'last_name' => 'Mballa',
            'email' => 'jean@example.test',
            'phone' => '+237 670 00 00 00',
        ], $overrides));
    }

    /** The single-step owner-approves path a business would be seeded with. */
    protected function offerWorkflow(): Workflow
    {
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Job offers',
            'subject_type' => JobOffer::class,
            'is_active' => true,
            'is_default' => true,
        ]);

        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Owner approves',
            'type' => 'approval',
            'approver_mode' => 'owner',
            'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }

    /** An offer that has been made, submitted and approved by the owner. */
    protected function approvedOffer(JobApplication $application, float $amount = 250_000): JobOffer
    {
        $this->offerWorkflow();

        $offer = $this->offers()->make($application, $amount, now()->addWeeks(2)->toDateString(), $this->owner);

        $instance = $this->offers()->submit($offer, $this->owner);

        $this->engine()->act($instance, $this->owner, 'approved');

        return $offer->fresh();
    }
}
