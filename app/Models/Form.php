<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\FormFields;
use App\Support\UniqueId;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A form a business builds and shares — Opes Forms (Module 16).
 *
 * Lifecycle is draft → open → closed and back to open again: unlike issued
 * documents there is no immutability here, because a form is a container for
 * incoming data rather than a record handed to anyone. Old responses keep
 * their answers keyed by field id, so editing a live form never rewrites what
 * people already submitted.
 */
class Form extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public static function newShareToken(): string
    {
        return UniqueId::make(
            fn () => Str::random(22),
            fn (string $token) => static::query()
                ->withoutGlobalScopes()
                ->where('share_token', $token)
                ->exists(),
        );
    }

    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function publicUrl(): string
    {
        return url('/f/'.$this->share_token);
    }

    /** Fields with their definitions normalised — see FormFields. */
    public function fieldDefinitions(): array
    {
        return collect($this->fields ?? [])
            ->map(fn (array $field) => FormFields::normalise($field))
            ->all();
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'open' => ['label' => 'Open', 'tone' => 'positive'],
            'closed' => ['label' => 'Closed', 'tone' => 'muted'],
            default => ['label' => 'Draft', 'tone' => 'neutral'],
        };
    }
}
