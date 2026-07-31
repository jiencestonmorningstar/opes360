<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanyNote;
use App\Models\CompanyUserPermission;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Contact;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCompany(string $name): Company
    {
        $owner = User::factory()->create();

        return Company::create([
            'slug' => str($name)->slug()->value(),
            'name' => $name,
            'owner_id' => $owner->id,
        ]);
    }

    public function test_queries_are_scoped_to_the_current_company(): void
    {
        $alpha = $this->makeCompany('Alpha Ltd');
        $beta = $this->makeCompany('Beta Ltd');

        $current = app(CurrentCompany::class);

        $current->set($alpha);
        Contact::create(['name' => 'Alpha Customer']);

        $current->set($beta);
        Contact::create(['name' => 'Beta Customer']);

        $this->assertSame(['Beta Customer'], Contact::pluck('name')->all());

        $current->set($alpha);
        $this->assertSame(['Alpha Customer'], Contact::pluck('name')->all());
    }

    public function test_a_record_cannot_be_read_by_id_from_another_company(): void
    {
        $alpha = $this->makeCompany('Alpha Ltd');
        $beta = $this->makeCompany('Beta Ltd');
        $current = app(CurrentCompany::class);

        $current->set($alpha);
        $contact = Contact::create(['name' => 'Alpha Customer']);

        $current->set($beta);

        $this->assertNull(Contact::find($contact->id));
    }

    public function test_no_current_company_means_no_tenant_data_is_visible(): void
    {
        $alpha = $this->makeCompany('Alpha Ltd');
        $current = app(CurrentCompany::class);

        $current->set($alpha);
        Contact::create(['name' => 'Alpha Customer']);

        // Failing closed matters: a missing company must never widen the query to
        // every tenant in the database.
        $current->set(null);

        $this->assertCount(0, Contact::all());
    }

    public function test_creating_without_a_current_company_throws(): void
    {
        app(CurrentCompany::class)->set(null);

        $this->expectException(RuntimeException::class);

        Contact::create(['name' => 'Orphan']);
    }

    public function test_company_id_is_stamped_automatically_on_create(): void
    {
        $alpha = $this->makeCompany('Alpha Ltd');
        app(CurrentCompany::class)->set($alpha);

        $this->assertSame($alpha->id, Contact::create(['name' => 'Alpha Customer'])->company_id);
    }

    /**
     * Guards the pairing the whole tenancy model rests on: if a table carries
     * company_id, its model must apply the scope. This is what stops a new model
     * from silently shipping unscoped.
     */
    public function test_every_model_with_a_company_id_column_uses_the_tenant_trait(): void
    {
        // Exemptions are deliberate and must stay justified. Anything not listed
        // here is required to be scoped.
        $exempt = [
            // Written for system-level events where no company exists yet (failed
            // logins, company creation), and read only via explicit admin queries.
            ActivityLog::class,

            // Permission checks are parameterised by company — a user may be asked
            // about a company that is not the current one, which the global scope
            // would silently filter out. Every query against it passes company_id.
            CompanyUserPermission::class,

            // Platform-admin-only annotations about a business, not tenant data —
            // no business user or company-scoped query ever touches this table.
            // Same rationale as ActivityLog above.
            CompanyNote::class,
        ];

        $files = glob(app_path('Models/*.php'));
        $checked = 0;

        foreach ($files as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || in_array($class, $exempt, true)) {
                continue;
            }

            $model = new $class;

            if (! Schema::hasColumn($model->getTable(), 'company_id')) {
                continue;
            }

            $checked++;

            $this->assertContains(
                BelongsToCompany::class,
                class_uses_recursive($class),
                "{$class} has a company_id column but does not use BelongsToCompany, so its queries are unscoped."
            );
        }

        $this->assertGreaterThan(5, $checked, 'Expected to check several tenant models.');
    }
}
