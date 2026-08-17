<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §8.2's collaborative BI grid — a cell holds a raw formula/literal
 * (`SpreadsheetEngine` computes values from it on read), never a computed
 * value, so `=OPES_SUM("invoices","status","paid")` always reflects the
 * ledger as it stands right now rather than whatever it said when the
 * formula was typed.
 */
class Spreadsheet extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cells' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
