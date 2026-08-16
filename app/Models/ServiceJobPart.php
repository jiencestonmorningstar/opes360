<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something fitted on a visit.
 *
 * Priced on the line rather than looked up when the invoice is drawn, for the
 * same reason a time entry carries its own rate: a price list that changes in
 * March must not restate what January's job was worth.
 */
class ServiceJobPart extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'is_billable' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** What to print on the invoice line. */
    public function label(): string
    {
        return $this->description ?: ($this->item?->name ?? 'Part');
    }

    public function total(): float
    {
        return (float) $this->quantity * (float) $this->unit_price;
    }
}
