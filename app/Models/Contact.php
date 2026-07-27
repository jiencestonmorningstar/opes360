<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'phones' => 'array',
            'address' => 'array',
            'tags' => 'array',
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'tax_id' => 'encrypted',
            'synced_at' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ContactNote::class);
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('type', 'customer');
    }

    public function displayName(): string
    {
        return $this->company_name ?: $this->name;
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->displayName())) ?: [];

        return strtoupper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }

    /**
     * Deterministic accent so a given customer keeps the same avatar colour
     * across sessions and devices — matches the coloured initials in the design.
     */
    public function accent(): string
    {
        $palette = ['blue', 'green', 'purple', 'orange', 'teal', 'pink'];

        return $palette[crc32((string) $this->id) % count($palette)];
    }
}
