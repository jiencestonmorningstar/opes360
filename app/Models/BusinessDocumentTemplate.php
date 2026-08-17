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

    /**
     * Per-language body variants (§44). Empty for every template that has
     * never been given a translation — which is the ordinary case, and
     * leaves DocumentComposer reading `body` exactly as before.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(BusinessDocumentTemplateTranslation::class, 'business_document_template_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** @return array<int, string> Language codes this template has a variant for — the default body is not one of these. */
    public function translatedLanguages(): array
    {
        return $this->translations()->pluck('language')->all();
    }

    /**
     * The body to compose with: the matching variant if $language names one,
     * otherwise the template's own default body. Never null, never blank —
     * a template always has a body of its own.
     */
    public function bodyFor(?string $language): string
    {
        if ($language === null) {
            return $this->body;
        }

        $variant = $this->translations()->where('language', $language)->first();

        return $variant?->body ?? $this->body;
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
            // §44 — additive: a reader that only knows the built-in shape
            // never looks at this key, so a single-body template is
            // indistinguishable from before.
            'available_languages' => $this->translatedLanguages(),
        ];
    }
}
