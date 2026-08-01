<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\UniqueId;
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

    /**
     * Short enough to read out at a door with a dead scanner — which is also
     * why it needs the retry. Upper-casing folds base62 down to 36 usable
     * symbols, so eight characters is nearer 2.8e12 combinations than the
     * 2.2e14 the length suggests. A busy organiser can reach the range where
     * a collision stops being hypothetical, and the serial is unique per
     * company, so the clash would abort an entire order mid-transaction.
     */
    public static function newSerial(string $companyId): string
    {
        return UniqueId::make(
            fn () => 'TKT-'.Str::upper(Str::random(8)),
            fn (string $serial) => static::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('serial', $serial)
                ->exists(),
        );
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
