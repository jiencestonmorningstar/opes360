<?php

namespace Tests\Feature\Search;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Search\Palette;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SearchEntry;
use App\Models\User;
use App\Search\GlobalSearch;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Global search. The whole difficulty is what a result must NOT show:
 * another company's records, a screen the searcher may not open, or the
 * body of a paper marked confidential.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        GlobalSearch::observe();

        $this->owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->owner->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($this->company, $this->owner);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    /** A signed-in member of the company at the given role. */
    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** Every title in the palette's grouped results, flattened. */
    protected function titles(string $term): array
    {
        $groups = Livewire::test(Palette::class)
            ->set('query', $term)
            ->viewData('groups');

        return collect($groups)->flatMap(fn ($entries) => collect($entries)->pluck('title'))->all();
    }

    public function test_a_saved_contact_is_found_by_name(): void
    {
        Contact::create(['name' => 'Mireille Fotso', 'balance' => 0]);

        $this->actingAs($this->owner);

        $this->assertContains('Mireille Fotso', $this->titles('mireille'));
    }

    public function test_a_cashier_does_not_find_employees_contracts_or_papers(): void
    {
        Employee::create(['first_name' => 'Salaire', 'last_name' => 'Personne']);
        Contract::create(['title' => 'Salaire framework agreement', 'starts_on' => now()->toDateString()]);
        BusinessDocument::create([
            'template' => 'memo', 'title' => 'Salaire memo', 'body' => 'Pay details', 'status' => 'issued',
        ]);
        // Something the cashier legitimately may see, so the test proves
        // filtering rather than an empty index.
        Contact::create(['name' => 'Salaire Client', 'balance' => 0]);

        $this->actingAsRole(Role::CASHIER);

        $titles = $this->titles('salaire');

        $this->assertContains('Salaire Client', $titles);
        $this->assertNotContains('Salaire Personne', $titles);
        $this->assertNotContains('Salaire framework agreement', $titles);
        $this->assertNotContains('Salaire memo', $titles);

        // The owner finds all four.
        $this->actingAs($this->owner);
        $this->assertCount(4, $this->titles('salaire'));
    }

    public function test_search_never_crosses_companies(): void
    {
        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'rival', 'name' => 'Rival SARL', 'owner_id' => $otherOwner->id, 'currency' => 'USD',
        ]);
        $this->joinCompany($other, $otherOwner);

        Contact::create(['name' => 'Unique Zebra Ltd', 'balance' => 0, 'company_id' => $other->id]);

        // The entry was indexed under the other company...
        $this->assertSame(1, SearchEntry::query()->withoutGlobalScopes()->where('title', 'Unique Zebra Ltd')->count());

        // ...and a searcher in Acme never sees it, owner or not.
        $this->actingAs($this->owner);
        $this->assertNotContains('Unique Zebra Ltd', $this->titles('zebra'));
    }

    public function test_a_voided_invoice_leaves_the_index(): void
    {
        $contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-XY-0042',
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Issued,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0, 'balance' => 100,
        ]);

        $this->actingAs($this->owner);
        $this->assertContains('INV-XY-0042', $this->titles('INV-XY'));

        $document->update(['status' => DocumentStatus::Void]);

        $this->assertNotContains('INV-XY-0042', $this->titles('INV-XY'));
    }

    public function test_a_deleted_record_leaves_the_index_trashed_or_forced(): void
    {
        $a = Contact::create(['name' => 'Soft Delete Target', 'balance' => 0]);
        $b = Contact::create(['name' => 'Force Delete Target', 'balance' => 0]);

        $a->delete();
        $b->forceDelete();

        $this->actingAs($this->owner);
        $this->assertNotContains('Soft Delete Target', $this->titles('delete target'));
        $this->assertNotContains('Force Delete Target', $this->titles('delete target'));
        $this->assertSame(0, SearchEntry::query()->count());
    }

    public function test_a_secured_paper_matches_by_title_never_by_body(): void
    {
        BusinessDocument::create([
            'template' => 'memo',
            'title' => 'Board minutes April',
            'body' => 'The okapi settlement figure is 9,400,000.',
            'security' => 'restricted',
            'status' => 'issued',
        ]);

        $this->actingAs($this->owner);

        $this->assertContains('Board minutes April', $this->titles('board minutes'));
        // The body is not in the index at all for secured papers.
        $this->assertNotContains('Board minutes April', $this->titles('okapi'));
        $this->assertSame(0, SearchEntry::query()->where('body', 'like', '%okapi%')->count());
    }

    public function test_an_unsecured_paper_matches_by_body(): void
    {
        BusinessDocument::create([
            'template' => 'memo',
            'title' => 'Office notice',
            'body' => 'The pangolin parking rota changes on Monday.',
            'status' => 'issued',
        ]);

        $this->actingAs($this->owner);
        $this->assertContains('Office notice', $this->titles('pangolin'));
    }

    public function test_reindex_rebuilds_a_truncated_index(): void
    {
        Contact::create(['name' => 'Rebuild Me', 'balance' => 0]);
        Contract::create(['title' => 'Rebuild agreement', 'starts_on' => now()->toDateString()]);

        SearchEntry::query()->withoutGlobalScopes()->delete();
        $this->assertSame(0, SearchEntry::query()->count());

        $this->artisan('opes:search-reindex')->assertSuccessful();

        $this->actingAs($this->owner);
        $titles = $this->titles('rebuild');
        $this->assertContains('Rebuild Me', $titles);
        $this->assertContains('Rebuild agreement', $titles);
    }

    public function test_short_queries_return_nothing(): void
    {
        Contact::create(['name' => 'Al', 'balance' => 0]);

        $this->actingAs($this->owner);
        $this->assertSame([], $this->titles('a'));
    }
}
