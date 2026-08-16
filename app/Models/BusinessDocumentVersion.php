<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One snapshot of a document's content.
 *
 * Never destroyed, never edited — §12: historical versions survive unless a
 * retention policy explicitly permits their disposal, and no such policy
 * exists yet.
 */
class BusinessDocumentVersion extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('A document version cannot be edited.'));
        static::deleting(fn () => throw new RuntimeException('A document version cannot be deleted.'));
    }
}
