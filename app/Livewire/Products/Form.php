<?php

namespace App\Livewire\Products;

use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One form for create and edit; edit is entered with an Item route binding.
 *
 * State lives in Alpine and the record is submitted in one call, so a new item
 * can be added with no connection. See resources/js/forms/record.js.
 */
class Form extends Component
{
    use AuthorizesRequests;

    public ?Item $item = null;

    #[Url]
    public string $type = 'product';

    public function mount(?Item $item = null): void
    {
        // An empty binding container means "create"; a persisted one means edit.
        $this->item = $item?->exists ? $item : null;

        if ($this->item) {
            $this->type = $this->item->type;
        } elseif (! in_array($this->type, ['product', 'service'], true)) {
            $this->type = 'product';
        }
    }

    /** @return array<string, mixed> */
    public function initial(): array
    {
        $item = $this->item;

        return [
            'type' => $this->type,
            'name' => $item?->name ?? '',
            'sku' => $item?->sku ?? '',
            'barcode' => $item?->barcode ?? '',
            'unit' => $item?->unit ?? 'unit',
            'price' => $item !== null ? (string) $item->price : '',
            'cost' => $item?->cost !== null ? (string) $item->cost : '',
            'description' => $item?->description ?? '',
            'track_stock' => (bool) ($item?->track_stock ?? false),
            'reorder_level' => $item?->reorder_level !== null ? (string) $item->reorder_level : '',
            'is_active' => (bool) ($item?->is_active ?? true),
            // Create-only: recorded as the first movement in the stock ledger.
            'opening_stock' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function save(array $form): array
    {
        try {
            $this->item
                ? $this->authorize('update', $this->item)
                : $this->authorize('products.create');
        } catch (AuthorizationException) {
            return ['ok' => false, 'errors' => ['form' => ['You do not have permission to do this.']]];
        }

        $validator = validator($form, [
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'gte:0'],
            'cost' => ['nullable', 'numeric', 'gte:0'],
            'reorder_level' => ['nullable', 'numeric', 'gte:0'],
            'opening_stock' => ['nullable', 'numeric', 'gte:0'],
        ], [
            'price.required' => 'Give it a selling price — zero is fine.',
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => $validator->errors()->toArray()];
        }

        $type = in_array($form['type'] ?? '', ['product', 'service'], true) ? $form['type'] : $this->type;
        $attributes = self::attributesFrom($form) + ['type' => $type];

        DB::transaction(function () use ($attributes, $form) {
            if ($this->item) {
                $this->item->update($attributes);

                return;
            }

            $this->item = Item::create($attributes + ['created_by' => auth()->id()]);

            // Opening stock is a movement, not a column — the ledger starts here.
            if ($this->item->track_stock && (float) ($form['opening_stock'] ?? 0) > 0) {
                StockMovement::create([
                    'item_id' => $this->item->id,
                    'quantity' => (float) $form['opening_stock'],
                    // What the opening stock is worth, taken from the cost
                    // typed alongside it. Without it the valuation has nothing
                    // to average and the balance sheet carries no inventory —
                    // which is exactly the state every business started in
                    // before stock reached the books.
                    'unit_cost' => $this->item->cost !== null ? (float) $this->item->cost : null,
                    'reason' => 'opening',
                    'user_id' => auth()->id(),
                    'occurred_at' => now(),
                ]);
            }
        });

        return [
            'ok' => true,
            'id' => $this->item->id,
            'redirect' => route('products', ['type' => $type]),
        ];
    }

    /**
     * Form fields to database columns, shared with the offline path so a synced
     * item is indistinguishable from one created through the form.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function attributesFrom(array $form): array
    {
        $get = fn (string $key) => $form[$key] ?? null;
        $type = $get('type') ?: 'product';

        return [
            'name' => trim((string) $get('name')),
            'sku' => $get('sku') ?: null,
            'barcode' => $get('barcode') ?: null,
            'unit' => $get('unit') ?: 'unit',
            'price' => (float) $get('price'),
            'cost' => filled($get('cost')) ? (float) $get('cost') : null,
            'description' => $get('description') ?: null,
            // A service has nothing to count, so stock tracking is meaningless
            // on one regardless of what the form was left holding.
            'track_stock' => $type === 'product' && (bool) $get('track_stock'),
            'reorder_level' => filled($get('reorder_level')) ? (float) $get('reorder_level') : null,
            'is_active' => (bool) ($form['is_active'] ?? true),
        ];
    }

    public function render(): View
    {
        return view('livewire.products.form', ['initial' => $this->initial()])
            ->layout('components.layouts.app', [
                'title' => $this->item ? 'Edit '.$this->item->name : 'New '.ucfirst($this->type),
                'active' => 'products',
                'bottomNav' => false,
            ]);
    }
}
