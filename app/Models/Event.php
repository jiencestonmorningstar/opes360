<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\UniqueId;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A ticketed event — Opes Events (Module 17).
 */
class Event extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
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

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->orderBy('sort');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publicUrl(): string
    {
        return url('/e/'.$this->share_token);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Sales stop themselves once the event has started. */
    public function isSelling(): bool
    {
        return $this->isPublished() && $this->starts_at->isFuture();
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match (true) {
            $this->status === 'cancelled' => ['label' => 'Cancelled', 'tone' => 'muted'],
            $this->status === 'published' && $this->starts_at->isPast() => ['label' => 'Started', 'tone' => 'neutral'],
            $this->status === 'published' => ['label' => 'On sale', 'tone' => 'positive'],
            default => ['label' => 'Draft', 'tone' => 'neutral'],
        };
    }
}
