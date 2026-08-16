<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One extension of a contract, kept for good.
 *
 * The contract carries the term it is on now. This carries how it got there —
 * which is the record that turns "we have always used them" into "this rolled
 * over four times and the price rose twice while nobody looked".
 */
class ContractRenewal extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const METHODS = [
        'auto' => 'Rolled over automatically',
        'negotiated' => 'Renewed by agreement',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'previous_ends_on' => 'date',
            'new_ends_on' => 'date',
            'renewed_on' => 'date',
            'previous_value' => 'decimal:2',
            'new_value' => 'decimal:2',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? 'Renewed';
    }

    /** What the extension did to the price, if anything. */
    public function valueChange(): ?float
    {
        if ($this->previous_value === null || $this->new_value === null) {
            return null;
        }

        return round((float) $this->new_value - (float) $this->previous_value, 2);
    }
}
