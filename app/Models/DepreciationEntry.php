<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month's depreciation on one asset.
 *
 * Stored rather than computed from the acquisition date on demand, for the
 * same reason a payslip stores its figures: the charge posted to March has to
 * stay what it was after somebody corrects the useful life in June, and a
 * business arriving mid-life has to be able to record what was already written
 * off elsewhere.
 */
class DepreciationEntry extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'amount' => 'decimal:2',
            'accumulated_after' => 'decimal:2',
            'book_value_after' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function periodLabel(): string
    {
        return $this->period->translatedFormat('F Y');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
