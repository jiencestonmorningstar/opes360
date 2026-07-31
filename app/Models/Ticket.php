<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One admission to one event, held by one person, verified by one QR.
 */
class Ticket extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'checked_in_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** Short enough to read out at a door with a dead scanner. */
    public static function newSerial(): string
    {
        return 'TKT-'.Str::upper(Str::random(8));
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function verificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class);
    }

    public function isCheckedIn(): bool
    {
        return $this->status === 'checked_in';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'checked_in' => ['label' => 'Checked in', 'tone' => 'positive'],
            'void' => ['label' => 'Void', 'tone' => 'muted'],
            default => ['label' => 'Issued', 'tone' => 'neutral'],
        };
    }
}
