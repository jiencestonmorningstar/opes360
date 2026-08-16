<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of a shipment's status, in words the public page may repeat.
 *
 * This is the ONLY thing the tracking page renders, so nothing that must not
 * leave the tenant may ever be written into `note` — no other customer's
 * cargo, no phone numbers, no money. The status history is the whole story.
 */
class ShipmentEvent extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function statusLabel(): string
    {
        return Shipment::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }
}
