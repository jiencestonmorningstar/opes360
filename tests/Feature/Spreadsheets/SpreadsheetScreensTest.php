<?php

namespace Tests\Feature\Spreadsheets;

use App\Enums\DocumentType;
use App\Livewire\Spreadsheets\Edit;
use App\Livewire\Spreadsheets\Index;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Role;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SpreadsheetScreensTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

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
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_creating_a_spreadsheet_redirects_to_its_editor(): void
    {
        $this->actingAs($this->owner);

        $sheet = Spreadsheet::create(['title' => 'placeholder', 'cells' => [], 'created_by' => $this->owner->id]);
        $sheet->delete(); // just to occupy the id space, not asserted on

        Livewire::test(Index::class)
            ->call('create')
            ->assertRedirect();

        $this->assertSame(1, Spreadsheet::count());
    }

    public function test_a_role_without_accounting_view_cannot_open_the_index(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        $this->actingAs($clerk);

        Livewire::test(Index::class)->assertStatus(403);
    }

    public function test_editing_and_saving_persists_cells_and_title(): void
    {
        $this->actingAs($this->owner);
        $sheet = Spreadsheet::create(['title' => 'Old title', 'cells' => [], 'created_by' => $this->owner->id]);

        Livewire::test(Edit::class, ['sheet' => $sheet])
            ->set('title', 'Q3 numbers')
            ->set('cells.A1', '10')
            ->set('cells.B1', '=A1*2')
            ->call('save');

        $fresh = $sheet->fresh();
        $this->assertSame('Q3 numbers', $fresh->title);
        $this->assertSame('10', $fresh->cells['A1']);
        $this->assertSame('=A1*2', $fresh->cells['B1']);
    }

    public function test_the_editor_shows_a_formulas_computed_value(): void
    {
        $this->actingAs($this->owner);
        Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => Contact::create(['name' => 'Client'])->id,
            'status' => 'issued',
            'number' => 'INV-'.Str::upper(Str::random(5)),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 200000, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 200000, 'amount_paid' => 0, 'balance' => 200000,
            'created_by' => $this->owner->id,
        ]);
        $sheet = Spreadsheet::create([
            'title' => 'Revenue',
            'cells' => ['A1' => '=OPES_SUM("invoices","total")'],
            'created_by' => $this->owner->id,
        ]);

        Livewire::test(Edit::class, ['sheet' => $sheet])
            ->assertSee('200000');
    }

    public function test_a_second_companys_spreadsheet_cannot_be_opened(): void
    {
        $this->actingAs($this->owner);
        $sheet = Spreadsheet::create(['title' => 'Mine', 'cells' => [], 'created_by' => $this->owner->id]);

        $otherOwner = User::factory()->create();
        $otherCompany = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Ltd',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($otherCompany, $otherOwner, Role::OWNER);
        $otherOwner->forceFill(['current_company_id' => $otherCompany->id])->save();
        $this->actingAs($otherOwner);
        app(CurrentCompany::class)->set($otherCompany);

        Livewire::test(Edit::class, ['sheet' => $sheet])->assertStatus(403);
    }

    protected function memberAt(string $role): User
    {
        $user = User::factory()->create();
        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }
}
