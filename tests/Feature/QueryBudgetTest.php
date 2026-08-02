<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A budget of queries per screen, and the rule that makes it a budget: the
 * count must not grow with the number of rows on the page.
 *
 * Both halves matter, and the second more than the first. A page that costs
 * fifteen queries with three products and fifteen with sixty is fine forever;
 * one that costs twelve and then ninety has an N+1 in it, and nobody notices
 * until a real business with real data opens it on a shared host over a phone
 * connection.
 *
 * Two of those were live when this file was written. Every ability check —
 * every `@can` in a template, every navigation entry — re-read the user's
 * membership, their role and their permission overrides, so the dashboard ran
 * a hundred and thirty-seven queries to draw itself. And every product in a
 * list ran its own SUM over the stock ledger. The first is fixed by memoising
 * the permission lookup for the request; the second by loading the sum as an
 * aggregate. This file is what stops either coming back.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

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
    }

    /**
     * The screens a business opens every day, with a ceiling each.
     *
     * The numbers are generous — roughly double what each costs today — because
     * this is a guard against a regression of an order of magnitude, not a
     * ratchet that fails whenever somebody adds a legitimate query.
     */
    public function test_the_everyday_screens_stay_within_their_budget(): void
    {
        $this->products(12);
        $this->customers(12);

        $budgets = [
            '/' => 45,
            '/sales' => 25,
            '/customers' => 25,
            '/products' => 25,
            '/products/stock' => 30,
            '/accounting' => 25,
            '/accounting/declarations' => 35,
            '/settings' => 30,
        ];

        foreach ($budgets as $path => $budget) {
            $count = $this->queriesFor($path);

            $this->assertLessThanOrEqual($budget, $count, sprintf(
                "%s ran %d queries, over its budget of %d.\n".
                'Something on it is asking the database once per row, or once per permission check.',
                $path,
                $count,
                $budget
            ));
        }
    }

    /**
     * The real test: ten times the data, the same number of queries.
     *
     * A budget alone can be met by a page that is merely small today. This
     * cannot be met by anything with an N+1 in it.
     */
    public function test_the_query_count_does_not_grow_with_the_rows_on_the_page(): void
    {
        $this->products(3);
        $this->customers(3);

        $small = [
            '/' => $this->queriesFor('/'),
            '/products' => $this->queriesFor('/products'),
            '/customers' => $this->queriesFor('/customers'),
            '/products/stock' => $this->queriesFor('/products/stock'),
        ];

        $this->products(30);
        $this->customers(30);

        foreach ($small as $path => $before) {
            $after = $this->queriesFor($path);

            $this->assertLessThanOrEqual($before + 2, $after, sprintf(
                '%s went from %d queries on a handful of rows to %d on ten times as many. '.
                'That is a query per row.',
                $path,
                $before,
                $after
            ));
        }
    }

    /**
     * A page asks the gate dozens of times — the sidebar alone is twenty
     * entries. Resolving the same answer from the database each time was the
     * single largest cost in the application.
     */
    public function test_a_permission_is_resolved_once_however_often_it_is_asked(): void
    {
        $this->actingAs($this->owner);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        // First call pays for the membership, the role and the override.
        $this->owner->hasPermissionIn($this->company, 'sales.view');
        $firstCall = $queries;

        $queries = 0;
        for ($i = 0; $i < 50; $i++) {
            $this->owner->hasPermissionIn($this->company, 'sales.view');
        }

        $this->assertSame(0, $queries, 'Fifty repeats of one question cost nothing after the first.');
        $this->assertGreaterThan(0, $firstCall, 'The first one does still ask.');
    }

    /**
     * And the memo must never outlive the fact it caches. A membership
     * suspended is a permission withdrawn, immediately.
     */
    public function test_the_memo_is_dropped_when_the_membership_changes(): void
    {
        $member = User::factory()->create();
        $this->joinCompany($this->company, $member, Role::SALES_OFFICER);

        $this->assertTrue($member->hasPermissionIn($this->company, 'sales.view'));

        $this->company->users()->updateExistingPivot($member->id, ['status' => 'suspended']);
        $member->refresh();

        $this->assertFalse($member->hasPermissionIn($this->company, 'sales.view'));
        $this->assertNull($member->roleIn($this->company));
    }

    /** A list of products must not run a stock sum per product. */
    public function test_stock_on_hand_is_one_aggregate_for_a_whole_list(): void
    {
        $this->products(20);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $items = Item::query()->withStock()->get();
        $loaded = $queries;

        foreach ($items as $item) {
            $item->stockOnHand();
        }

        $this->assertCount(20, $items);
        $this->assertSame($loaded, $queries, 'Reading the loaded aggregate must not go back to the database.');
    }

    // ───────────────────────────────────────────────────────── helpers ──

    protected function queriesFor(string $path): int
    {
        $this->actingAs($this->owner);

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->get($path)->assertOk();

        return $count;
    }

    protected function products(int $count): void
    {
        $existing = Item::query()->count();

        for ($i = $existing + 1; $i <= $existing + $count; $i++) {
            $item = Item::create([
                'company_id' => $this->company->id,
                'name' => 'Product '.$i,
                'sku' => 'SKU-'.$i,
                'type' => 'product',
                'price' => 1000 * $i,
                'cost' => 600 * $i,
                'track_stock' => true,
                'reorder_level' => 5,
                'is_active' => true,
            ]);

            StockMovement::create([
                'company_id' => $this->company->id,
                'item_id' => $item->id,
                'quantity' => 40,
                'unit_cost' => 600 * $i,
                'reason' => 'opening',
                'occurred_at' => now(),
            ]);
        }
    }

    protected function customers(int $count): void
    {
        $existing = Contact::query()->count();

        for ($i = $existing + 1; $i <= $existing + $count; $i++) {
            Contact::create(['name' => 'Customer '.$i, 'balance' => 0]);
        }
    }
}
