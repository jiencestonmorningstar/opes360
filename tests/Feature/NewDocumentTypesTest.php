<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Work orders, goods received notes and waybills ride the same sales engine
 * as every other document: leased numbers, QR verification, immutability.
 * What needs pinning is what makes each distinct.
 */
class NewDocumentTypesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($company, $this->user);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);
    }

    protected function issued(DocumentType $type, float $total = 300): Document
    {
        $document = Document::create([
            'type' => $type,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => $total,
            'total' => $total,
            'balance' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Site works',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->user);
    }

    public function test_each_new_type_issues_with_its_own_prefix(): void
    {
        $this->assertStringStartsWith('WO-', $this->issued(DocumentType::WorkOrder)->number);
        $this->assertStringStartsWith('GRN-', $this->issued(DocumentType::GoodsReceivedNote)->number);
        $this->assertStringStartsWith('WB-', $this->issued(DocumentType::Waybill)->number);
    }

    public function test_none_of_them_touch_the_customer_balance(): void
    {
        $this->issued(DocumentType::WorkOrder);
        $this->issued(DocumentType::GoodsReceivedNote);
        $this->issued(DocumentType::Waybill);

        $this->assertSame(0.0, (float) $this->contact->fresh()->balance);
    }

    public function test_a_finished_work_order_converts_into_an_invoice(): void
    {
        $workOrder = $this->issued(DocumentType::WorkOrder, 750);

        $converter = app(DocumentConverter::class);
        $this->assertSame(DocumentType::Invoice, $converter->targetType($workOrder));

        $invoice = $converter->convert($workOrder, $this->user);

        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame(750.0, (float) $invoice->total);

        // Once billed, it must not bill twice.
        $this->assertFalse($converter->canConvert($workOrder->fresh()));
    }

    public function test_delivery_paperwork_does_not_convert(): void
    {
        $converter = app(DocumentConverter::class);

        $this->assertFalse($converter->canConvert($this->issued(DocumentType::GoodsReceivedNote)));
        $this->assertFalse($converter->canConvert($this->issued(DocumentType::Waybill)));
    }

    public function test_the_create_page_offers_every_type(): void
    {
        foreach (['work_order', 'goods_received_note', 'waybill'] as $type) {
            $this->actingAs($this->user)
                ->get('/documents/create?type='.$type)
                ->assertOk();
        }
    }
}
