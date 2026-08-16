<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\UniqueId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * "We are hiring for this position."
 *
 * A vacancy carries no job title of its own — it points at a Position, the
 * entity Phase 3.9 built precisely so a job would be a kept thing rather than
 * a typed phrase. What the vacancy adds is the recruiting state: openings,
 * lifecycle, and the share token behind the public application page.
 */
class Vacancy extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft',
        'open' => 'Open',
        'closed' => 'Closed',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['openings' => 'integer'];
    }

    /** Same shape as Form::newShareToken(), for the same unauthenticated lookup. */
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

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function title(): string
    {
        return (string) $this->position?->title;
    }

    public function publicUrl(): string
    {
        return url('/jobs/'.$this->share_token);
    }

    /** People this vacancy has actually put on the payroll. */
    public function hiredCount(): int
    {
        return $this->applications()->where('stage', 'hired')->count();
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
