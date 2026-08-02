<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Stock\Count as CountScreen;
use App\Livewire\Stock\Valuation as ValuationScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\ExpenseRecorder;
use App\Services\Stock\StockLedger;
use App\Services\Stock\Stocktaker;
use App\Services\Stock\StockValuation;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Stock, and the two places it has to show up.
 *
 * On the shelf: selling something takes it off, and voiding the sale puts it
 * back. In the books: what is left is an asset in 31, and the change in that
 * figure is the variation in 6031 that turns "achats" into "the cost of what
 * was sold". Before this, neither happened — stock on hand ignored every
 * invoice ever issued, and the income statement charged the business for
 * everything it had ever bought whether or not it had sold it.
 */
class StockInTheBooksTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $customer;

    protected Item $cement;

    protected Item $labour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);

        $this->cement = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Ciment 50kg',
            'sku' => 'CIM-50',
            'type' => 'product',
            'price' => 6500,
            'cost' => 5000,
            'track_stock' => true,
            'is_active' => true,
        ]);

        $this->labour = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Pose carrelage',
            'sku' => 'POSE',
            'type' => 'service',
            'price' => 20000,
            'track_stock' => false,
            'is_active' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────── the chart ──

    public function test_the_starter_chart_carries_a_stock_account_and_its_variation(): void
    {
        $this->assertNotNull(
            LedgerAccount::query()->where('number', '31')->first(),
            'A business that holds stock needs somewhere to put it on the balance sheet.'
        );

        $variation = LedgerAccount::query()->where('number', '6031')->first();

        $this->assertNotNull($variation);
        $this->assertSame(3, LedgerAccount::query()->where('number', '31')->value('class'));
        $this->assertSame(6, $variation->class);
        $this->assertSame('debit', $variation->normal_balance, 'Variation is a charge account that happens to swing.');
    }

    public function test_the_stock_account_is_debit_normal_so_it_reads_as_an_asset(): void
    {
        $this->assertSame('debit', LedgerAccount::query()->where('number', '31')->value('normal_balance'));
    }

    // ───────────────────────────────────────────────── selling reduces ──

    public function test_issuing_an_invoice_takes_the_goods_off_the_shelf(): void
    {
        $this->receive(100, 5000);

        $this->issue([[$this->cement, 12, 6500]]);

        $this->assertSame(88.0, $this->cement->fresh()->stockOnHand());
    }

    public function test_a_service_line_takes_nothing_off_any_shelf(): void
    {
        $this->receive(100, 5000);

        $this->issue([[$this->labour, 3, 20000]]);

        $this->assertSame(100.0, $this->cement->fresh()->stockOnHand());
        $this->assertSame(0, StockMovement::query()->where('reason', 'sale')->count());
    }

    public function test_an_untracked_product_is_left_alone(): void
    {
        $this->cement->forceFill(['track_stock' => false])->save();
        $this->receive(100, 5000);

        $this->issue([[$this->cement, 12, 6500]]);

        $this->assertSame(0, StockMovement::query()->where('reason', 'sale')->count());
    }

    /**
     * A quotation is an offer. Emptying a shelf because somebody asked for a
     * price would be the most surprising bug in the system.
     */
    public function test_a_quotation_does_not_move_stock(): void
    {
        $this->receive(100, 5000);

        $this->issue([[$this->cement, 12, 6500]], DocumentType::Quotation);

        $this->assertSame(100.0, $this->cement->fresh()->stockOnHand());
    }

    public function test_a_line_typed_by_hand_moves_nothing_because_it_names_nothing(): void
    {
        $this->receive(100, 5000);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 30000, 'total' => 30000, 'balance' => 30000,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Deux sacs de ciment',
            'quantity' => 2,
            'unit_price' => 15000,
            'line_total' => 30000,
        ]);

        app(DocumentIssuer::class)->issue($document, $this->owner);

        $this->assertSame(100.0, $this->cement->fresh()->stockOnHand());
    }

    public function test_voiding_an_invoice_puts_the_goods_back(): void
    {
        $this->receive(100, 5000);
        $document = $this->issue([[$this->cement, 12, 6500]]);

        $this->assertSame(88.0, $this->cement->fresh()->stockOnHand());

        app(DocumentConverter::class)->void($document, $this->owner, 'Erreur de saisie');

        $this->assertSame(100.0, $this->cement->fresh()->stockOnHand());
    }

    public function test_the_return_movement_is_appended_rather_than_the_sale_deleted(): void
    {
        $this->receive(100, 5000);
        $document = $this->issue([[$this->cement, 12, 6500]]);

        app(DocumentConverter::class)->void($document, $this->owner);

        $this->assertSame(1, StockMovement::query()->where('reason', 'sale')->count());
        $this->assertSame(1, StockMovement::query()->where('reason', 'return')->count());
    }

    public function test_stock_cannot_be_returned_twice_by_voiding_twice(): void
    {
        $this->receive(100, 5000);
        $document = $this->issue([[$this->cement, 12, 6500]]);

        app(DocumentConverter::class)->void($document, $this->owner);
        app(StockLedger::class)->reverseSale($document->fresh(), $this->company, $this->owner);

        $this->assertSame(100.0, $this->cement->fresh()->stockOnHand());
    }

    /**
     * Voiding used to leave the sale posted: the books claimed revenue and a
     * receivable for something the business had publicly cancelled.
     */
    public function test_voiding_an_invoice_reverses_the_sale_in_the_books(): void
    {
        $document = $this->issue([[$this->labour, 1, 20000]]);

        $revenue = LedgerAccount::query()->where('number', '706')->first();
        $this->assertSame(20000.0, $this->balanceOf($revenue));

        app(DocumentConverter::class)->void($document, $this->owner);

        $this->assertSame(0.0, $this->balanceOf($revenue), 'A cancelled sale is not income.');
        $this->assertSame(0.0, $this->balanceOf(LedgerAccount::query()->where('number', '411')->first()));
    }

    // ────────────────────────────────────────────────────── valuation ──

    public function test_stock_is_valued_at_the_weighted_average_of_what_was_paid(): void
    {
        // 20 at 400 and 30 at 550 is 8 000 + 16 500 over fifty units: 490 each.
        $this->receive(20, 400);
        $this->receive(30, 550);

        $this->assertSame(490.0, app(StockValuation::class)->unitCost($this->company, $this->cement));
        $this->assertSame(24500.0, app(StockValuation::class)->totalValue($this->company));
    }

    public function test_a_sale_does_not_drag_the_average_down(): void
    {
        $this->receive(20, 400);
        $this->receive(30, 550);
        $this->issue([[$this->cement, 10, 6500]]);

        $this->assertSame(490.0, app(StockValuation::class)->unitCost($this->company, $this->cement));
        $this->assertSame(19600.0, app(StockValuation::class)->totalValue($this->company), '40 left at 490.');
    }

    /**
     * The state every business starts in: quantities recorded before costs
     * existed. The catalogue's cost is the only figure available and is used
     * rather than reporting stock worth nothing.
     */
    public function test_stock_with_no_recorded_cost_falls_back_to_the_catalogue(): void
    {
        StockMovement::create([
            'company_id' => $this->company->id,
            'item_id' => $this->cement->id,
            'quantity' => 10,
            'reason' => 'opening',
            'occurred_at' => now(),
        ]);

        $this->assertSame(5000.0, app(StockValuation::class)->unitCost($this->company, $this->cement));
    }

    public function test_stock_nobody_has_ever_priced_is_named_rather_than_silently_valued_at_nothing(): void
    {
        $this->cement->forceFill(['cost' => null])->save();

        StockMovement::create([
            'company_id' => $this->company->id,
            'item_id' => $this->cement->id,
            'quantity' => 10,
            'reason' => 'opening',
            'occurred_at' => now(),
        ]);

        $unpriced = app(StockValuation::class)->itemsWithoutCost($this->company);

        $this->assertCount(1, $unpriced);
        $this->assertSame($this->cement->id, $unpriced->first()->id);
        $this->assertSame(0.0, app(StockValuation::class)->totalValue($this->company));
    }

    public function test_a_service_is_never_part_of_a_stock_valuation(): void
    {
        $this->receive(10, 5000);

        $holdings = app(StockValuation::class)->holdings($this->company);

        $this->assertCount(1, $holdings);
        $this->assertSame($this->cement->id, $holdings->first()['item']->id);
    }

    // ─────────────────────────────────────────────────── the stocktake ──

    public function test_a_count_sheet_opens_with_what_the_ledger_claims(): void
    {
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);

        $this->assertCount(1, $stocktake->lines);
        $this->assertSame(100.0, (float) $stocktake->lines->first()->book_quantity);
        $this->assertNull($stocktake->lines->first()->counted_quantity, 'Nobody has looked yet.');
        $this->assertSame(5000.0, (float) $stocktake->lines->first()->unit_cost);
    }

    public function test_posting_a_count_corrects_the_shelf(): void
    {
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 94], $this->owner);
        app(Stocktaker::class)->post($stocktake, $this->owner);

        $this->assertSame(94.0, $this->cement->fresh()->stockOnHand(), 'The count is now the truth.');
        $this->assertSame(-6.0, (float) StockMovement::query()->where('reason', 'stocktake')->value('quantity'));
    }

    /**
     * The entry the whole feature exists for. 100 sacks at 5 000 is 500 000 of
     * stock the balance sheet never carried, and 500 000 of cost the income
     * statement was charging against a period that had not consumed it.
     */
    public function test_posting_a_count_carries_the_stock_onto_the_balance_sheet(): void
    {
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 100], $this->owner);
        app(Stocktaker::class)->post($stocktake, $this->owner);

        $this->assertSame(500000.0, $this->balanceOf(LedgerAccount::query()->where('number', '31')->first()));
        $this->assertSame(-500000.0, $this->balanceOf(LedgerAccount::query()->where('number', '6031')->first()),
            'Stock arriving credits the variation, which reduces the period’s cost.');
    }

    public function test_a_second_count_posts_only_the_movement_since_the_first(): void
    {
        $this->receive(100, 5000);

        $first = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($first, [$this->cement->id => 100], $this->owner);
        app(Stocktaker::class)->post($first, $this->owner);

        // Sixty sold over the month, so the closing stock is forty.
        $this->issue([[$this->cement, 60, 6500]]);

        $second = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($second, [$this->cement->id => 40], $this->owner);
        $second = app(Stocktaker::class)->post($second, $this->owner);

        $this->assertSame(200000.0, $this->balanceOf(LedgerAccount::query()->where('number', '31')->first()));
        $this->assertSame(-300000.0, (float) $second->variance_value, 'The stock fell by 300 000, which is the cost of the sale.');
        $this->assertSame(-200000.0, $this->balanceOf(LedgerAccount::query()->where('number', '6031')->first()));
    }

    /**
     * Achats less variation is the cost of what was sold — the reason the
     * variation account exists at all. 500 000 bought, 300 000 consumed.
     */
    public function test_the_income_statement_shows_a_real_cost_of_goods(): void
    {
        $this->purchaseGoods(500000);
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 40], $this->owner);
        app(Stocktaker::class)->post($stocktake, $this->owner);

        $statement = app(Books::class)->incomeStatement($this->company);

        $this->assertSame(500000.0, $this->balanceOf(LedgerAccount::query()->where('number', '601')->first()));
        $this->assertSame(-200000.0, $this->balanceOf(LedgerAccount::query()->where('number', '6031')->first()));
        $this->assertSame(300000.0, round($statement['total_charges'], 2), 'Achats less the variation.');
    }

    public function test_the_balance_sheet_now_carries_inventory(): void
    {
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 100], $this->owner);
        app(Stocktaker::class)->post($stocktake, $this->owner);

        $sheet = app(Books::class)->balanceSheet($this->company);

        $this->assertTrue($sheet['actif']->contains(fn ($row) => $row['account']->number === '31'));
        $this->assertTrue($sheet['balanced'], 'Both sides still meet.');
    }

    /**
     * A blank box is not a zero. Treating it as one would write off every item
     * whoever was counting did not reach before closing time — the single most
     * expensive way this feature could be wrong.
     */
    public function test_an_uncounted_line_is_left_exactly_as_it_was(): void
    {
        $second = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Sable (m³)', 'sku' => 'SAB', 'type' => 'product',
            'price' => 12000, 'cost' => 8000, 'track_stock' => true, 'is_active' => true,
        ]);

        $this->receive(100, 5000);
        app(StockLedger::class)->receive($this->company, $second, 10, 8000, null, $this->owner);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 94], $this->owner);
        app(Stocktaker::class)->post($stocktake, $this->owner);

        $this->assertSame(10.0, $second->fresh()->stockOnHand(), 'Nobody counted the sand, so nobody wrote it off.');
        $this->assertSame(1, StockMovement::query()->where('reason', 'stocktake')->count());
        // 94 × 5 000 plus the sand still held at its book value.
        $this->assertSame(550000.0, $this->balanceOf(LedgerAccount::query()->where('number', '31')->first()));
    }

    public function test_a_count_that_agrees_with_the_books_posts_no_entry(): void
    {
        $this->receive(100, 5000);

        $first = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($first, [$this->cement->id => 100], $this->owner);
        app(Stocktaker::class)->post($first, $this->owner);

        $entries = JournalLine::query()->count();

        $second = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($second, [$this->cement->id => 100], $this->owner);
        app(Stocktaker::class)->post($second, $this->owner);

        $this->assertSame($entries, JournalLine::query()->count(), 'An entry for nothing is noise in a journal.');
    }

    public function test_a_count_cannot_be_posted_twice(): void
    {
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 100], $this->owner);
        app(Stocktaker::class)->post($stocktake, $this->owner);

        $this->expectException(RuntimeException::class);
        app(Stocktaker::class)->post($stocktake->fresh(), $this->owner);
    }

    public function test_a_posted_count_cannot_be_edited(): void
    {
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 100], $this->owner);
        app(Stocktaker::class)->post($stocktake, $this->owner);

        $this->expectException(RuntimeException::class);
        app(Stocktaker::class)->save($stocktake->fresh(), [$this->cement->id => 5], $this->owner);
    }

    public function test_a_count_with_nothing_counted_refuses_to_post(): void
    {
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);

        $this->expectException(RuntimeException::class);
        app(Stocktaker::class)->post($stocktake, $this->owner);
    }

    public function test_voiding_a_posted_count_reverses_both_the_shelf_and_the_books(): void
    {
        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 94], $this->owner);
        $stocktake = app(Stocktaker::class)->post($stocktake, $this->owner);

        $this->assertSame(94.0, $this->cement->fresh()->stockOnHand());

        app(Stocktaker::class)->void($stocktake, $this->owner);

        $this->assertSame(100.0, $this->cement->fresh()->stockOnHand());
        $this->assertSame(0.0, $this->balanceOf(LedgerAccount::query()->where('number', '31')->first()));
    }

    public function test_references_are_unique_per_business_and_per_year(): void
    {
        $first = app(Stocktaker::class)->start($this->company, null, '2026-03-04', $this->owner);
        $second = app(Stocktaker::class)->start($this->company, null, '2026-09-30', $this->owner);

        $this->assertSame('INV-2026-0001', $first->reference);
        $this->assertSame('INV-2026-0002', $second->reference);
    }

    /**
     * A business that has not seeded a chart of accounts is not keeping books,
     * which is allowed. Counting its shelves must still work.
     */
    public function test_a_business_with_no_chart_can_still_count_its_shelves(): void
    {
        LedgerAccount::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->delete();

        $this->receive(100, 5000);

        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);
        app(Stocktaker::class)->save($stocktake, [$this->cement->id => 94], $this->owner);
        $stocktake = app(Stocktaker::class)->post($stocktake, $this->owner);

        $this->assertTrue($stocktake->isPosted());
        $this->assertSame(94.0, $this->cement->fresh()->stockOnHand());
    }

    // ────────────────────────────────────────────────────── the screens ──

    public function test_the_valuation_screen_shows_the_gap_between_shelf_and_books(): void
    {
        $this->receive(100, 5000);

        Livewire::actingAs($this->owner)
            ->test(ValuationScreen::class)
            ->assertSet('countedOn', now()->toDateString())
            ->assertViewHas('onShelf', 500000.0)
            ->assertViewHas('inBooks', 0.0)
            ->assertViewHas('difference', 500000.0);
    }

    public function test_the_count_screen_totals_what_has_been_typed_before_it_is_saved(): void
    {
        $this->receive(100, 5000);
        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(CountScreen::class, ['stocktake' => $stocktake])
            ->set('counts.'.$this->cement->id, '96')
            ->assertViewHas('countedValue', 480000.0)
            ->assertViewHas('varianceValue', -20000.0);
    }

    public function test_the_count_screen_posts_and_the_shelf_follows(): void
    {
        $this->receive(100, 5000);
        $stocktake = app(Stocktaker::class)->start($this->company, null, null, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(CountScreen::class, ['stocktake' => $stocktake])
            ->set('counts.'.$this->cement->id, '96')
            ->call('post')
            ->assertHasNoErrors();

        $this->assertSame(96.0, $this->cement->fresh()->stockOnHand());
        $this->assertTrue($stocktake->fresh()->isPosted());
    }

    public function test_someone_who_may_not_adjust_stock_cannot_start_a_count(): void
    {
        $clerk = User::factory()->create();
        $this->joinCompany($this->company, $clerk, Role::READ_ONLY);

        Livewire::actingAs($clerk)
            ->test(ValuationScreen::class)
            ->call('startCount')
            ->assertForbidden();
    }

    // ───────────────────────────────────────────────────────── helpers ──

    protected function receive(float $quantity, float $unitCost): void
    {
        app(StockLedger::class)->receive(
            $this->company, $this->cement, $quantity, $unitCost, null, $this->owner
        );
    }

    /**
     * @param  array<int, array{0: Item, 1: float, 2: float}>  $lines  item, quantity, unit price
     */
    protected function issue(array $lines, DocumentType $type = DocumentType::Invoice): Document
    {
        $total = 0.0;

        foreach ($lines as [$item, $quantity, $price]) {
            $total += $quantity * $price;
        }

        $document = Document::create([
            'type' => $type,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $total, 'total' => $total, 'balance' => $total,
        ]);

        foreach ($lines as [$item, $quantity, $price]) {
            DocumentLine::create([
                'document_id' => $document->id,
                'item_id' => $item->id,
                'description' => $item->name,
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => $quantity * $price,
            ]);
        }

        return app(DocumentIssuer::class)->issue($document, $this->owner);
    }

    /** A delivery charged to 601, the way an intermittent inventory records one. */
    protected function purchaseGoods(float $amount): void
    {
        app(ExpenseRecorder::class)->record([
            'description' => 'Livraison ciment',
            'category' => 'goods',
            'issue_date' => now()->toDateString(),
            'amount' => $amount,
            'vat_rate' => 0,
            'payment_method' => 'cash',
        ], $this->owner);
    }

    /**
     * The account's balance, signed its own way round: positive means the
     * account has moved in the direction it normally moves. So revenue in 706
     * reads positive as income, and 6031 reads negative when stock grew —
     * which is exactly the sign that reduces the period's cost.
     */
    protected function balanceOf(?LedgerAccount $account): float
    {
        if ($account === null) {
            return 0.0;
        }

        $lines = JournalLine::query()->withoutGlobalScopes()->where('ledger_account_id', $account->id)->get();
        $signed = $lines->sum(fn (JournalLine $l) => (float) $l->debit - (float) $l->credit);

        return round($account->isDebitNormal() ? $signed : -$signed, 2);
    }
}
