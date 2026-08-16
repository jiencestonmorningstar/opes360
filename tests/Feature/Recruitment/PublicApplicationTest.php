<?php

namespace Tests\Feature\Recruitment;

use App\Models\Candidate;
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The public application page: the token is the tenant, and nothing else is.
 */
class PublicApplicationTest extends RecruitmentTestCase
{
    public function test_the_share_link_shows_the_vacancy_to_a_visitor_who_is_nobody(): void
    {
        $vacancy = $this->vacancy(['description' => 'Deliver bread across Douala.']);

        app(CurrentCompany::class)->set(null);
        auth()->logout();

        $this->get('/jobs/'.$vacancy->share_token)
            ->assertOk()
            ->assertSee('Delivery Driver')
            ->assertSee('Acme Sarl');
    }

    public function test_a_visitor_can_apply_with_a_cv_and_the_right_company_gets_the_application(): void
    {
        Storage::fake('documents');

        $vacancy = $this->vacancy();

        app(CurrentCompany::class)->set(null);
        auth()->logout();

        $this->post('/jobs/'.$vacancy->share_token, [
            'first_name' => 'Ama',
            'last_name' => 'Ngo',
            'email' => 'ama@example.test',
            'cover_note' => 'Five years driving in town.',
            'cv' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ])->assertRedirect('/jobs/'.$vacancy->share_token.'/thanks');

        $application = JobApplication::query()->withoutGlobalScopes()->first();

        $this->assertNotNull($application);
        // The company came from the token's vacancy, never from the request.
        $this->assertSame($this->company->id, $application->company_id);
        $this->assertSame('applied', $application->stage);
        $this->assertNotNull($application->cv_path);
        Storage::disk('documents')->assertExists($application->cv_path);
    }

    public function test_the_token_never_leaks_across_tenants(): void
    {
        $vacancy = $this->vacancy();

        // A second business with its own vacancy.
        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);

        $otherPosition = Position::create([
            'company_id' => $other->id,
            'title' => 'Baker',
            'is_active' => true,
        ]);
        $otherVacancy = Vacancy::create([
            'company_id' => $other->id,
            'position_id' => $otherPosition->id,
            'openings' => 1,
            'status' => 'open',
            'share_token' => Vacancy::newShareToken(),
        ]);

        app(CurrentCompany::class)->set(null);
        auth()->logout();

        // Each token resolves its own company's advert, nobody else's.
        $this->get('/jobs/'.$otherVacancy->share_token)
            ->assertOk()
            ->assertSee('Baker')
            ->assertSee('Other Sarl')
            ->assertDontSee('Acme Sarl');

        $this->post('/jobs/'.$otherVacancy->share_token, [
            'first_name' => 'Paul',
            'last_name' => 'Eto',
        ])->assertRedirect();

        $application = JobApplication::query()->withoutGlobalScopes()->first();
        $this->assertSame($other->id, $application->company_id);
        $this->assertSame($other->id, Candidate::query()->withoutGlobalScopes()->first()->company_id);

        // And Acme's own vacancy took nothing from it.
        $this->assertSame(
            0,
            JobApplication::query()->withoutGlobalScopes()->where('vacancy_id', $vacancy->id)->count(),
        );
    }

    public function test_a_wrong_token_is_a_404_not_a_list(): void
    {
        $this->vacancy();

        app(CurrentCompany::class)->set(null);
        auth()->logout();

        $this->get('/jobs/definitely-not-a-token')->assertNotFound();
    }

    public function test_a_closed_vacancy_refuses_the_application(): void
    {
        $vacancy = $this->vacancy(['status' => 'closed']);

        app(CurrentCompany::class)->set(null);
        auth()->logout();

        $this->get('/jobs/'.$vacancy->share_token)
            ->assertOk()
            ->assertSee('not accepting applications');

        $this->post('/jobs/'.$vacancy->share_token, [
            'first_name' => 'Late',
            'last_name' => 'Comer',
        ])->assertRedirect('/jobs/'.$vacancy->share_token);

        $this->assertSame(0, JobApplication::query()->withoutGlobalScopes()->count());
    }

    public function test_a_file_pretending_to_be_a_cv_is_refused(): void
    {
        Storage::fake('documents');

        $vacancy = $this->vacancy();

        app(CurrentCompany::class)->set(null);
        auth()->logout();

        // An executable is not on the allow-list at all.
        $this->post('/jobs/'.$vacancy->share_token, [
            'first_name' => 'Mal',
            'last_name' => 'Ware',
            'cv' => UploadedFile::fake()->create('cv.exe', 10),
        ])->assertSessionHasErrors('cv');

        $this->assertSame(0, JobApplication::query()->withoutGlobalScopes()->count());
    }

    public function test_a_script_wearing_a_pdf_name_is_caught_by_the_sniffed_mime(): void
    {
        Storage::fake('documents');

        $vacancy = $this->vacancy();

        /*
         * A real temp file, not UploadedFile::fake() — the fake reports the
         * mime its NAME suggests, which is precisely the lie the pipeline
         * exists to catch, so the fake cannot exercise this check.
         */
        $path = tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($path, '<?php echo "boo";');
        $file = new UploadedFile($path, 'cv.pdf', null, null, true);

        try {
            $this->pipeline()->apply($vacancy, ['first_name' => 'Mal', 'last_name' => 'Ware'], $file);
            $this->fail('A script wearing a .pdf name was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('contents do not match', $e->getMessage());
        }

        $this->assertSame(0, JobApplication::query()->withoutGlobalScopes()->count());
    }
}
