<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** One snapshot of a template's content. Never edited, never deleted. */
class BusinessDocumentTemplateVersion extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentTemplate::class, 'business_document_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('A template version cannot be edited.'));
        static::deleting(fn () => throw new RuntimeException('A template version cannot be deleted.'));
    }
}
