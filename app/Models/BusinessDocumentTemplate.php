<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A template a business wrote for itself, alongside the built-in catalogue in
 * App\Support\DocumentTemplates.
 *
 * Shares that catalogue's array contract — name, summary, icon, accent,
 * binding, fields, body — via toTemplateArray(), so DocumentComposer and the
 * gallery can treat one exactly like the other without knowing which they
 * are holding.
 */
class BusinessDocumentTemplate extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'binding' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BusinessDocumentTemplateVersion::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** The same shape App\Support\DocumentTemplates::find() returns. */
    public function toTemplateArray(): array
    {
        return [
            'name' => $this->name,
            'summary' => $this->summary,
            'icon' => $this->icon,
            'accent' => $this->accent,
            'binding' => $this->binding,
            'fields' => $this->fields,
            'body' => $this->body,
            // Distinguishes it in the gallery without changing the contract
            // any existing reader of a template array expects.
            'custom' => true,
        ];
    }
}
