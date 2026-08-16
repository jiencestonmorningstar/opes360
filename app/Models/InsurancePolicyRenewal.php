<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One renewal of a policy, kept for good — the ContractRenewal pattern.
 *
 * The policy carries the term it is on now. This carries how it got there:
 * which is the record that answers a client asking why the premium has risen
 * three years running, and the E&O defence when somebody claims a term was
 * never agreed.
 */
class InsurancePolicyRenewal extends Model
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
            'previous_covers_from' => 'date',
            'previous_covers_to' => 'date',
            'new_covers_from' => 'date',
            'new_covers_to' => 'date',
            'renewed_on' => 'date',
            'previous_premium' => 'decimal:2',
            'new_premium' => 'decimal:2',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? 'Renewed';
    }

    /** What the renewal did to the premium, if anything. */
    public function premiumChange(): ?float
    {
        if ($this->previous_premium === null || $this->new_premium === null) {
            return null;
        }

        return round((float) $this->new_premium - (float) $this->previous_premium, 2);
    }
}
