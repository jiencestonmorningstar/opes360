<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Services\Stock\StockLedger;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Race guards around issuing and voiding documents.
 *
 * The concurrent interleavings themselves (two connections in flight) cannot
 * run inside one sqlite test transaction, so each test replays the race
 * single-threaded with a stale in-memory model — the exact state a second
 * request holds when it loses the race — and asserts the locked re-read
 * inside the transaction refuses it.
 */
class DocumentRaceGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Contact $contact;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'XAF',
        ]);

        $this->joinCompany($company, $this->user);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);

        $this->item = Item::create([
            'name' => 'Ciment 50kg',
            'sku' => 'CIM',
            'type' => 'product',
            'price' => 6500,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }

    protected function draft(float $total = 400): Document
    {
        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $total,
            'total' => $total,
            'balance' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'item_id' => $this->item->id,
            'description' => $this->item->name,
            'quantity' => 2,
            'unit_price' => $total / 2,
            'line_total' => $total,
        ]);

        return $document;
    }

    protected function saleMovements(Document $document): int
    {
        return StockMovement::query()
            ->withoutGlobalScopes()
            ->where('reference_type', Document::class)
            ->where('reference_id', $document->id)
            ->whereIn('reason', ['sale', 'credit'])
            ->count();
    }

    public function test_issuing_with_a_stale_model_cannot_decrement_stock_twice(): void
    {
        $draft = $this->draft();

        // The stale copy a double-clicked request or retried job still holds.
        $stale = Document::query()->findOrFail($draft->id);

        app(DocumentIssuer::class)->issue($draft, $this->user);

        try {
            app(DocumentIssuer::class)->issue($stale, $this->user);
            $this->fail('Issuing an already-issued document must be refused.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(1, $this->saleMovements($draft), 'The shelf must be decremented exactly once.');
    }

    public function test_record_sale_is_idempotent_per_document(): void
    {
        $draft = $this->draft();
        $issued = app(DocumentIssuer::class)->issue($draft, $this->user);
        $company = app(CurrentCompany::class)->get();

        $written = app(StockLedger::class)->recordSale($issued->fresh()->load('lines'), $company, $this->user);

        $this->assertSame(0, $written, 'A replayed recordSale must write nothing.');
        $this->assertSame(1, $this->saleMovements($issued));
    }

    public function test_a_paid_document_cannot_be_voided_through_a_stale_model(): void
    {
        $invoice = app(DocumentIssuer::class)->issue($this->draft(), $this->user);

        // Stale copy read before the payment lands — amount_paid still 0.
        $stale = Document::query()->findOrFail($invoice->id);

        app(PaymentRecorder::class)->record($invoice, $this->user, 400, PaymentMethod::Cash);

        try {
            app(DocumentConverter::class)->void($stale, $this->user, 'Race');
            $this->fail('A document with payments against it must refuse to void.');
        } catch (RuntimeException) {
            // Expected.
        }

        $fresh = $invoice->fresh();
        $this->assertNotSame(DocumentStatus::Void, $fresh->status);
        $this->assertSame('400.00', $fresh->amount_paid);
    }
}
