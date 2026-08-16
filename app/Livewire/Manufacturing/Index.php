<?php

namespace App\Livewire\Manufacturing;

use App\Models\BillOfMaterial;
use App\Models\BillOfMaterialLine;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Services\Manufacturing\Production;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Making things: the recipes, and the orders that run them.
 *
 * Two tabs on one screen because for a small workshop they are one activity:
 * write down what a table is made of once, then say "make three" whenever the
 * shelf runs low. Everything that moves stock goes through the Production
 * service, and every refusal it makes surfaces here as a message rather than
 * a crash.
 */
class Index extends Component
{
    #[Url]
    public string $tab = 'orders'; // orders|boms

    // ── Recipe form ─────────────────────────────────────────────────────
    public bool $addingBom = false;

    public ?string $bomItemId = null;

    public string $bomName = '';

    public string $bomOutput = '1';

    /** @var array<int, array{item_id: string, quantity: string, scrap: string}> */
    public array $bomLines = [];

    // ── Order form ──────────────────────────────────────────────────────
    public ?string $orderBomId = null;

    public string $orderQuantity = '';

    public string $orderNote = '';

    // ── Completing ──────────────────────────────────────────────────────
    /** The order awaiting a lot number, when its finished product is lot-tracked. */
    public ?string $lotFor = null;

    public string $lotCode = '';

    public function mount(): void
    {
        Gate::authorize('manufacturing.view');
    }

    // ─────────────────────────────────────────────────────────── recipes ──

    public function startBom(): void
    {
        Gate::authorize('manufacturing.manage');

        $this->addingBom = true;
        $this->bomItemId = null;
        $this->bomName = '';
        $this->bomOutput = '1';
        $this->bomLines = [['item_id' => '', 'quantity' => '', 'scrap' => '']];
    }

    public function addBomLine(): void
    {
        $this->bomLines[] = ['item_id' => '', 'quantity' => '', 'scrap' => ''];
    }

    public function removeBomLine(int $index): void
    {
        unset($this->bomLines[$index]);
        $this->bomLines = array_values($this->bomLines);
    }

    public function saveBom(): void
    {
        Gate::authorize('manufacturing.manage');

        $this->validate([
            'bomItemId' => ['required', 'string'],
            'bomName' => ['nullable', 'string', 'max:160'],
            'bomOutput' => ['required', 'numeric', 'gt:0'],
            'bomLines' => ['required', 'array', 'min:1'],
            'bomLines.*.item_id' => ['required', 'string'],
            'bomLines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'bomLines.*.scrap' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'bomLines.*.item_id.required' => 'Choose a component.',
            'bomLines.*.quantity.required' => 'How much of it?',
        ]);

        // A product made of itself is a loop, not a recipe.
        foreach ($this->bomLines as $line) {
            if ($line['item_id'] === $this->bomItemId) {
                $this->addError('bomLines', 'A product cannot be a component of itself.');

                return;
            }
        }

        if (count(array_unique(array_column($this->bomLines, 'item_id'))) !== count($this->bomLines)) {
            $this->addError('bomLines', 'The same component appears twice — put the whole quantity on one line.');

            return;
        }

        $bom = BillOfMaterial::create([
            'item_id' => $this->bomItemId,
            'name' => $this->bomName ?: null,
            'output_quantity' => round((float) $this->bomOutput, 3),
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        foreach ($this->bomLines as $line) {
            BillOfMaterialLine::create([
                'bill_of_material_id' => $bom->id,
                'item_id' => $line['item_id'],
                'quantity' => round((float) $line['quantity'], 3),
                'scrap_percent' => round((float) ($line['scrap'] ?: 0), 2),
            ]);
        }

        $this->addingBom = false;
        $this->dispatch('toast', message: 'Recipe saved.');
    }

    public function toggleBom(string $bomId): void
    {
        Gate::authorize('manufacturing.manage');

        $bom = BillOfMaterial::findOrFail($bomId);
        $bom->forceFill(['is_active' => ! $bom->is_active])->save();
    }

    // ──────────────────────────────────────────────────────────── orders ──

    public function startOrder(string $bomId): void
    {
        Gate::authorize('manufacturing.manage');

        $this->orderBomId = BillOfMaterial::findOrFail($bomId)->id;
        $this->orderQuantity = '';
        $this->orderNote = '';
        $this->tab = 'orders';
    }

    public function createOrder(): void
    {
        Gate::authorize('manufacturing.manage');

        $this->validate([
            'orderQuantity' => ['required', 'numeric', 'gt:0'],
            'orderNote' => ['nullable', 'string', 'max:500'],
        ]);

        $bom = BillOfMaterial::findOrFail($this->orderBomId);

        try {
            app(Production::class)->create(
                app(CurrentCompany::class)->get(),
                $bom,
                (float) $this->orderQuantity,
                actor: auth()->user(),
                note: $this->orderNote ?: null,
            );
        } catch (RuntimeException $e) {
            $this->addError('orderQuantity', $e->getMessage());

            return;
        }

        $this->orderBomId = null;
        $this->dispatch('toast', message: 'Order raised.');
    }

    public function start(string $orderId): void
    {
        Gate::authorize('manufacturing.manage');

        try {
            app(Production::class)->start(ProductionOrder::findOrFail($orderId), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('orders', $e->getMessage());
        }
    }

    public function complete(string $orderId): void
    {
        Gate::authorize('manufacturing.complete');

        $order = ProductionOrder::findOrFail($orderId);

        // A lot-tracked finished product needs its lot named first — show the
        // box and wait, rather than completing and failing.
        if ($order->item->isTraceable() && $this->lotFor !== $order->id) {
            $this->lotFor = $order->id;
            $this->lotCode = '';

            return;
        }

        try {
            app(Production::class)->complete($order, auth()->user(), $this->lotCode ?: null);
        } catch (RuntimeException $e) {
            $this->addError('orders', $e->getMessage());

            return;
        }

        $this->lotFor = null;
        $this->lotCode = '';
        $this->dispatch('toast', message: "{$order->reference} completed — stock has moved.");
    }

    public function cancel(string $orderId): void
    {
        Gate::authorize('manufacturing.manage');

        try {
            app(Production::class)->cancel(ProductionOrder::findOrFail($orderId), auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('orders', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Order cancelled. Nothing was consumed.');
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();
        $production = app(Production::class);

        $boms = BillOfMaterial::query()
            ->with(['item', 'lines.item'])
            ->orderBy('created_at')
            ->get();

        // "Can we make one now" next to each active recipe — the question a
        // workshop opens this screen to ask.
        $makeable = $boms->mapWithKeys(fn (BillOfMaterial $bom) => [
            $bom->id => $bom->is_active ? $production->makeable($company, $bom) : 0.0,
        ]);

        return view('livewire.manufacturing.index', [
            'orders' => ProductionOrder::query()
                ->with(['item', 'lines.item', 'billOfMaterial'])
                ->orderByRaw("CASE WHEN status IN ('planned', 'in-progress') THEN 0 ELSE 1 END")
                ->latest()
                ->limit(100)
                ->get(),
            'boms' => $boms,
            'makeable' => $makeable,
            'products' => Item::query()->products()->active()->orderBy('name')->get(['id', 'name', 'sku']),
            'currency' => $company?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Manufacturing', 'active' => 'manufacturing']);
    }
}
