<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How long a business must keep a kind of document. A null `kind` is the
 * catch-all for anything no more specific policy names.
 *
 * Deliberately data, not a constant. §31 of the master spec: legal retention
 * periods differ by jurisdiction and by document type, and a number compiled
 * into the product would be wrong for someone the moment it shipped.
 */
class BusinessDocumentRetentionPolicy extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
