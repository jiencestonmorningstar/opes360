<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Livewire\Reports\Aging;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AgingScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Contact $customer;

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

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    protected function overdueInvoice(float $amount = 250000): Document
    {
        return Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => 'issued',
            'number' => 'INV-'.Str::upper(Str::random(5)),
            'issue_date' => now()->subDays(200)->toDateString(),
            'due_date' => now()->subDays(170)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $amount, 'discount_total' => 0, 'tax_total' => 0,
            'total' => $amount, 'amount_paid' => 0, 'balance' => $amount,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_the_screen_renders(): void
    {
        $this->actingAs($this->owner)
            ->get(route('reports.aging'))
            ->assertOk()
            ->assertSee('Aging')
            ->assertSee('Over 90 days');
    }

    public function test_an_empty_report_says_so_rather_than_showing_a_blank_grid(): void
    {
        $this->actingAs($this->owner)
            ->get(route('reports.aging'))
            ->assertOk()
            ->assertSee('Nobody owes you anything');
    }

    public function test_an_overdue_invoice_appears_with_its_customer(): void
    {
        $this->overdueInvoice();

        Livewire::actingAs($this->owner)
            ->test(Aging::class)
            ->assertSee('Un Client')
            ->assertSee('days late');
    }

    public function test_switching_sides_changes_the_report(): void
    {
        $this->overdueInvoice();

        Livewire::actingAs($this->owner)
            ->test(Aging::class)
            ->assertSee('Un Client')
            ->call('setSide', 'payable')
            ->assertSet('side', 'payable')
            ->assertSee('You owe nothing');
    }

    public function test_an_unknown_side_falls_back_rather_than_erroring(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Aging::class)
            ->call('setSide', 'nonsense')
            ->assertSet('side', 'receivable');
    }

    public function test_a_party_can_be_opened_to_see_its_documents(): void
    {
        $invoice = $this->overdueInvoice();

        Livewire::actingAs($this->owner)
            ->test(Aging::class)
            ->assertDontSee($invoice->number)
            ->call('toggleParty', $this->customer->id)
            ->assertSee($invoice->number)
            ->call('toggleParty', $this->customer->id)
            ->assertDontSee($invoice->number);
    }

    /** Switching sides must not leave a stale row expanded. */
    public function test_switching_sides_closes_an_open_party(): void
    {
        $this->overdueInvoice();

        Livewire::actingAs($this->owner)
            ->test(Aging::class)
            ->call('toggleParty', $this->customer->id)
            ->call('setSide', 'payable')
            ->assertSet('openParty', null);
    }

    public function test_the_export_streams_a_csv_of_the_detail(): void
    {
        $invoice = $this->overdueInvoice();

        $this->actingAs($this->owner);

        ob_start();
        Livewire::actingAs($this->owner)->test(Aging::class)->instance()->export()->sendContent();
        $csv = ob_get_clean();

        // fputcsv quotes any field containing a space, hence "Days overdue".
        $this->assertStringContainsString('Party,Document,Issued,Due,"Days overdue",Bucket,Amount', $csv);
        $this->assertStringContainsString('Un Client', $csv);
        $this->assertStringContainsString($invoice->number, $csv);
        $this->assertStringContainsString('Over 90 days', $csv);
    }

    /**
     * There must be no as-of control until balances are historised.
     *
     * Rewinding the age without rewinding the balances yields a report that
     * looks authoritative and is wrong twice: it shows invoices raised after
     * the chosen date, and hides ones that were outstanding then but have since
     * been paid. This test exists so nobody adds the control back without
     * solving that first.
     */
    public function test_the_screen_offers_no_as_of_date(): void
    {
        $this->assertFalse(
            property_exists(Aging::class, 'asOf'),
            'an as-of control needs historical balances, which are not stored',
        );
    }

    public function test_a_cashier_cannot_reach_the_report(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($cashier)->get(route('reports.aging'))->assertForbidden();
    }
}
