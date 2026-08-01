<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Documents\Create;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The mentions a Cameroonian invoice has to carry, checked on the rendered
 * sheet rather than on the model — a figure that is right in the database and
 * missing from the paper is not compliance.
 */
class FiscalComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Sarl',
            'owner_id' => $this->user->id,
            'currency' => 'XAF',
            'registration_number' => 'RC/DLA/2019/B/1234',
            'tax_id' => 'M071912345678A',
            'tax_regime' => 'reel',
            'tax_centre' => 'CDI Douala 1er',
            'capital_social' => 5000000,
            'vat_registered' => true,
            'vat_rate' => 19.25,
        ]);

        $this->joinCompany($this->company, $this->user, Role::OWNER);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        $this->contact = Contact::create(['name' => 'Ndongo Ltd', 'tax_id' => 'M081987654321B']);
    }

    protected function issueInvoice(array $lines): Document
    {
        $this->returned(Livewire::actingAs($this->user)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('save', [
                'contact_id' => $this->contact->id,
                'issue_date' => now()->toDateString(),
                'due_date' => null,
                'notes' => '',
                'lines' => $lines,
            ], true));

        return Document::where('type', DocumentType::Invoice)->firstOrFail();
    }

    public function test_an_invoice_carries_the_supplier_and_customer_fiscal_identity(): void
    {
        $document = $this->issueInvoice([
            ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100000'],
        ]);

        $this->actingAs($this->user)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('NIU M071912345678A')
            ->assertSee('RCCM RC/DLA/2019/B/1234')
            ->assertSee('Régime du réel')
            ->assertSee('CDI Douala 1er')
            // The buyer's NIU, without which they cannot deduct the tax charged.
            ->assertSee('NIU M081987654321B');
    }

    public function test_an_invoice_shows_the_ht_tva_ttc_breakdown(): void
    {
        $document = $this->issueInvoice([
            ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100000'],
        ]);

        $this->assertSame('100000.00', $document->subtotal);
        $this->assertSame('19250.00', $document->tax_total);
        $this->assertSame('119250.00', $document->total);

        $this->actingAs($this->user)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('Total HT')
            ->assertSee('TVA 19.25%')
            ->assertSee('Total TTC');
    }

    public function test_an_invoice_states_its_total_in_words(): void
    {
        $document = $this->issueInvoice([
            ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100000'],
        ]);

        $this->actingAs($this->user)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('cent dix-neuf mille deux cent cinquante francs CFA');
    }

    public function test_a_business_that_does_not_charge_vat_says_so_on_the_sheet(): void
    {
        $this->company->forceFill([
            'vat_registered' => false,
            'tax_regime' => 'liberatoire',
        ])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        $document = $this->issueInvoice([
            ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100000'],
        ]);

        $this->assertSame('0.00', $document->tax_total);
        $this->assertSame('100000.00', $document->total);

        $this->actingAs($this->user)
            ->get(route('documents.print', $document))
            ->assertOk()
            // Silence would read like a forgotten tax line.
            ->assertSee('TVA non applicable')
            ->assertSee('Impôt libératoire')
            ->assertDontSee('Total TTC');
    }

    public function test_prices_keyed_inclusive_have_the_tax_taken_out_not_added_on(): void
    {
        $this->company->forceFill(['prices_include_tax' => true])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        $document = $this->issueInvoice([
            ['description' => 'Shelf item', 'quantity' => '1', 'unit_price' => '119250'],
        ]);

        // The customer pays the shelf price; the tax comes out of it.
        $this->assertSame('119250.00', $document->total);
        $this->assertSame('100000.00', $document->subtotal);
        $this->assertSame('19250.00', $document->tax_total);
    }

    public function test_a_receipt_carries_the_niu_and_names_the_tax_inside_the_amount_paid(): void
    {
        $document = $this->issueInvoice([
            ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100000'],
        ]);

        $payment = app(PaymentRecorder::class)->record(
            $document,
            $this->user,
            (float) $document->total,
            PaymentMethod::Cash,
        );

        $this->actingAs($this->user)
            ->get(route('receipts.print', $payment->receipt))
            ->assertOk()
            ->assertSee('NIU M071912345678A')
            // The amount paid is TTC, so the tax is shown inside it rather than
            // added to it — a customer cannot reclaim tax the receipt never names.
            ->assertSee('dont TVA 19.25%')
            ->assertSee('Total HT');
    }
}
