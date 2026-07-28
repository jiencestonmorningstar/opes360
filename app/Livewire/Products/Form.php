<?php

namespace App\Livewire\Products;

use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/** One form for create and edit; edit is entered with an Item route binding. */
class Form extends Component
{
    public ?Item $item = null;

    #[Url]
    public string $type = 'product';

    /** @var array<string, mixed> */
    public array $form = [
        'name' => '',
        'sku' => '',
        'barcode' => '',
        'unit' => 'unit',
        'price' => '',
        'cost' => '',
        'description' => '',
        'track_stock' => false,
        'reorder_level' => '',
        'is_active' => true,
    ];

    /** Create-only: opening quantity, recorded as the first stock movement. */
    public string $openingStock = '';

    public function mount(?Item $item = null): void
    {
        // An empty binding container means "create"; a persisted one means edit.
        $this->item = $item?->exists ? $item : null;

        if ($this->item) {
            $this->type = $this->item->type;
            $this->form = [
                'name' => $this->item->name,
                'sku' => $this->item->sku,
                'barcode' => $this->item->barcode,
                'unit' => $this->item->unit,
                'price' => (string) $this->item->price,
                'cost' => $this->item->cost !== null ? (string) $this->item->cost : '',
                'description' => $this->item->description,
                'track_stock' => $this->item->track_stock,
                'reorder_level' => $this->item->reorder_level !== null ? (string) $this->item->reorder_level : '',
                'is_active' => $this->item->is_active,
            ];
        } elseif (! in_array($this->type, ['product', 'service'], true)) {
            $this->type = 'product';
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => ['required', 'string', 'max:160'],
            'form.sku' => ['nullable', 'string', 'max:60'],
            'form.price' => ['required', 'numeric', 'gte:0'],
            'form.cost' => ['nullable', 'numeric', 'gte:0'],
            'form.reorder_level' => ['nullable', 'numeric', 'gte:0'],
            'openingStock' => ['nullable', 'numeric', 'gte:0'],
        ], [
            'form.price.required' => 'Give it a selling price — zero is fine.',
        ]);

        $attributes = [
            'type' => $this->type,
            'name' => trim($this->form['name']),
            'sku' => $this->form['sku'] ?: null,
            'barcode' => $this->form['barcode'] ?: null,
            'unit' => $this->form['unit'] ?: 'unit',
            'price' => (float) $this->form['price'],
            'cost' => $this->form['cost'] !== '' ? (float) $this->form['cost'] : null,
            'description' => $this->form['description'] ?: null,
            'track_stock' => $this->type === 'product' && $this->form['track_stock'],
            'reorder_level' => $this->form['reorder_level'] !== '' ? (float) $this->form['reorder_level'] : null,
            'is_active' => (bool) $this->form['is_active'],
        ];

        DB::transaction(function () use ($attributes) {
            if ($this->item) {
                $this->item->update($attributes);

                return;
            }

            $this->item = Item::create($attributes + ['created_by' => auth()->id()]);

            // Opening stock is a movement, not a column — the ledger starts here.
            if ($this->item->track_stock && (float) $this->openingStock > 0) {
                StockMovement::create([
                    'item_id' => $this->item->id,
                    'quantity' => (float) $this->openingStock,
                    'reason' => 'opening',
                    'user_id' => auth()->id(),
                    'occurred_at' => now(),
                ]);
            }
        });

        $this->redirectRoute('products', ['type' => $this->type]);
    }

    public function render(): View
    {
        return view('livewire.products.form')
            ->layout('components.layouts.app', [
                'title' => $this->item ? 'Edit '.$this->item->name : 'New '.ucfirst($this->type),
                'active' => 'products',
                'bottomNav' => false,
            ]);
    }
}
