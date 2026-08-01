<?php

namespace Tests\Feature;

use App\Livewire\Accounting\Index as AccountingIndex;
use App\Models\Company;
use App\Models\CompanyUserPermission;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChartManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $accountant;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Sarl',
            'owner_id' => $owner->id,
            'currency' => 'XAF',
        ]);

        $this->accountant = User::factory()->create();
        $this->joinCompany($this->company, $this->accountant, Role::ACCOUNTANT);
        $this->accountant->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);
    }

    public function test_an_accountant_adds_an_account_and_the_side_is_derived_from_the_parent(): void
    {
        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->set('newNumber', '4111')
            ->set('newName', 'Clients — boutique')
            ->call('addAccount')
            ->assertHasNoErrors();

        $account = LedgerAccount::where('number', '4111')->firstOrFail();

        // A subdivision of 411 Clients inherits its parent's side.
        $this->assertSame('debit', $account->normal_balance);
        $this->assertSame(4, (int) $account->class);
    }

    public function test_avances_recues_derives_credit_despite_sitting_next_to_clients(): void
    {
        // 4191 starts 419, not 411 — money a customer paid in advance is owed
        // BY the business, and the derivation must not drag it to debit.
        $this->assertSame('credit', ChartOfAccounts::suggestSide($this->company, '4191'));

        // And the accountant can always say so explicitly.
        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->set('newNumber', '4191')
            ->set('newName', 'Clients, avances et acomptes reçus')
            ->set('newSide', 'credit')
            ->call('addAccount')
            ->assertHasNoErrors();

        $this->assertSame('credit', LedgerAccount::where('number', '4191')->firstOrFail()->normal_balance);
    }

    public function test_a_duplicate_number_is_refused(): void
    {
        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->set('newNumber', '411')
            ->set('newName', 'Clients again')
            ->call('addAccount')
            ->assertHasErrors('newNumber');

        $this->assertSame(1, LedgerAccount::where('number', '411')->count());
    }

    public function test_a_malformed_number_is_refused(): void
    {
        foreach (['9411', '4', 'ABC', '041'] as $bad) {
            Livewire::actingAs($this->accountant)
                ->test(AccountingIndex::class)
                ->set('newNumber', $bad)
                ->set('newName', 'Nope')
                ->call('addAccount')
                ->assertHasErrors('newNumber');
        }
    }

    public function test_a_suggested_subdivision_is_added_with_one_click(): void
    {
        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->call('addSuggested', '4431');

        $account = LedgerAccount::where('number', '4431')->firstOrFail();

        $this->assertSame('TVA facturée sur ventes', $account->name);
        // Inherits 443's credit side.
        $this->assertSame('credit', $account->normal_balance);
    }

    public function test_renaming_keeps_the_number_and_the_history(): void
    {
        $account = LedgerAccount::where('number', '411')->firstOrFail();

        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->call('startRename', $account->id)
            ->set('editingName', 'Clients divers')
            ->call('saveRename')
            ->assertHasNoErrors();

        $this->assertSame('Clients divers', $account->fresh()->name);
        $this->assertSame('411', $account->fresh()->number);
    }

    public function test_an_account_with_entries_cannot_be_deleted(): void
    {
        app(Ledger::class)->post($this->company, 'OD', now()->toDateString(), [
            ['account' => 'cash', 'debit' => 1000],
            ['account' => 'sales_goods', 'credit' => 1000],
        ]);

        $cash = LedgerAccount::where('number', '571')->firstOrFail();

        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->call('deleteAccount', $cash->id);

        // Still there — the books reference it.
        $this->assertNotNull($cash->fresh());
    }

    public function test_an_untouched_account_can_be_deleted_and_deactivation_covers_the_rest(): void
    {
        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->set('newNumber', '4112')
            ->set('newName', 'Typo')
            ->call('addAccount');

        $typo = LedgerAccount::where('number', '4112')->firstOrFail();

        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->call('deleteAccount', $typo->id);

        $this->assertNull($typo->fresh());

        $cash = LedgerAccount::where('number', '571')->firstOrFail();

        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->call('toggleActive', $cash->id);

        $this->assertFalse($cash->fresh()->is_active);
    }

    public function test_view_without_manage_reads_the_chart_but_cannot_touch_it(): void
    {
        // A cashier granted accounting.view by per-user override — they can
        // open the books, and the manage actions must still refuse them.
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        CompanyUserPermission::create([
            'company_id' => $this->company->id,
            'user_id' => $cashier->id,
            'permission_id' => Permission::where('slug', 'accounting.view')->firstOrFail()->id,
            'granted' => true,
        ]);

        $this->actingAs($cashier)->get(route('accounting'))->assertOk();

        Livewire::actingAs($cashier)
            ->test(AccountingIndex::class)
            ->set('newNumber', '4113')
            ->set('newName', 'Should not exist')
            ->call('addAccount')
            ->assertForbidden();

        $this->assertNull(LedgerAccount::where('number', '4113')->first());
    }

    public function test_the_chart_tab_renders(): void
    {
        JournalEntry::query()->delete();

        Livewire::actingAs($this->accountant)
            ->test(AccountingIndex::class)
            ->set('tab', 'chart')
            ->assertOk()
            ->assertSee('Plan comptable')
            ->assertSee('Banques locales')
            // The suggested chips must carry the real account numbers. These
            // are numeric array keys, and a merge-style flatten renumbers
            // them from zero — which once turned every chip into a silent
            // no-op that only a browser test caught.
            ->assertSee('4431')
            ->assertDontSee("addSuggested('0')");
    }
}
