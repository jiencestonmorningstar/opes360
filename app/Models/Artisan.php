<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Artisan extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'certifications' => 'array',
            'languages' => 'array',
            'coverage_area' => 'array',
            'phones' => 'array',
            'address' => 'array',
            'working_hours' => 'array',
            'socials' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_verified' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class);
    }

    public function testimonials(): HasMany
    {
        return $this->hasMany(ArtisanTestimonial::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->full_name)) ?: [];

        return strtoupper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }

    public function photoUrl(): ?string
    {
        return blank($this->photo_path) ? null : Storage::disk('public')->url($this->photo_path);
    }

    /** Deterministic accent so the same artisan keeps one colour everywhere. */
    public function accent(): string
    {
        $palette = ['blue', 'green', 'purple', 'orange', 'teal', 'pink'];

        return $palette[crc32((string) $this->id) % count($palette)];
    }

    /**
     * Averages the already-loaded testimonials rather than re-querying, so the
     * public profile does not fire an extra query per render — and so the value
     * is correct even where the relation was loaded under a different scope.
     */
    public function averageRating(): ?float
    {
        $ratings = $this->relationLoaded('testimonials')
            ? $this->testimonials->whereNotNull('rating')->pluck('rating')
            : $this->testimonials()->whereNotNull('rating')->pluck('rating');

        return $ratings->isEmpty() ? null : round($ratings->avg(), 1);
    }
}
