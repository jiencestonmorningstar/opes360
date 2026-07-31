<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketType extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Null when unlimited. */
    public function remaining(): ?int
    {
        return $this->quantity === null ? null : max(0, $this->quantity - $this->sold);
    }

    public function isSoldOut(): bool
    {
        return $this->remaining() === 0;
    }
}
