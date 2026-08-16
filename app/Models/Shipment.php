<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use App\Support\UniqueId;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One consignment, from the day it was booked to the day somebody signed.
 *
 * Sender and receiver are Contacts, the freight invoice is an ordinary
 * Document reached through `invoice()`, and the proof of delivery is a
 * BusinessDocument put through the existing signature flow. This model owns
 * only what nothing else in the product already owns: the cargo, the route,
 * and the status it has reached.
 */
class Shipment extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;

    public const STATUSES = [
        'booked' => 'Booked',
        'loaded' => 'Loaded',
        'in_transit' => 'In transit',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:2',
            'declared_value' => 'decimal:2',
            'freight_amount' => 'decimal:2',
            'delivered_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'receiver_id');
    }

    /** The freight invoice — a link into accounts receivable, never a copy. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** The signed proof-of-delivery paper, when one was requested. */
    public function podDocument(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'pod_document_id');
    }

    public function manifests(): BelongsToMany
    {
        return $this->belongsToMany(TripManifest::class, 'trip_manifest_shipments')
            ->withTimestamps();
    }

    /** The one open manifest this shipment is aboard, if any. */
    public function openManifest(): ?TripManifest
    {
        return $this->manifests()->where('trip_manifests.status', 'open')->first();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('happened_at')->orderBy('id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['delivered', 'cancelled'], true);
    }

    public function isInvoiced(): bool
    {
        return $this->document_id !== null;
    }

    /** 32 chars of base62 — a link for one shipment's own parties, not a password. */
    public static function newTrackingToken(): string
    {
        return UniqueId::make(
            fn () => Str::random(32),
            fn (string $token) => static::query()->withoutGlobalScopes()->where('tracking_token', $token)->exists(),
        );
    }
}
