<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named group of documents — "Annual Audit Package 2026".
 *
 * Holds references, never copies. Removing a document from a package leaves
 * the document exactly where it was, and deleting a package deletes none of
 * what it grouped: a package is an arrangement, the same way a folder and a
 * milestone already are in this product.
 */
class BusinessDocumentPackage extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(
            BusinessDocument::class,
            'business_document_package_items',
            'business_document_package_id',
            'business_document_id',
        )->withPivot('sort_order')->withTimestamps()->orderByPivot('sort_order');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
