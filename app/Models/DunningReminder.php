<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reminder, already sent.
 *
 * The unique key on (document_id, step) is the mechanism rather than a
 * constraint added for tidiness: it is what stops a nightly sweep re-sending
 * the same reminder every night until the invoice is paid.
 */
class DunningReminder extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'days_overdue' => 'integer',
            'balance' => 'decimal:2',
            'sent_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
