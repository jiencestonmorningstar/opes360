<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One view of a shared link. No company scope of its own — reached only through its share. */
class BusinessDocumentShareAccess extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentShare::class, 'business_document_share_id');
    }
}
