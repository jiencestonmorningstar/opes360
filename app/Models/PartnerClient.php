<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A business a secretariat prints for.
 *
 * Not a Contact: a contact is someone the company sells to and bills, with a
 * balance and a document history. A partner client is a business the partner
 * produces stationery for and hopes to eventually enrol — it carries an invite
 * token and, once they take a plan, a link to the account they became.
 */
class PartnerClient extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $client) {
            $client->invite_token ??= self::freshInviteToken();
        });
    }

    /**
     * Long enough that guessing one is not a way to attribute yourself
     * somebody else's client, and retried rather than trusted to be unique.
     */
    public static function freshInviteToken(): string
    {
        do {
            $token = Str::lower(Str::random(24));
        } while (self::query()->acrossAllCompanies()->where('invite_token', $token)->exists());

        return $token;
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(CardIssuance::class);
    }

    public function convertedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'converted_company_id');
    }

    public function hasConverted(): bool
    {
        return $this->converted_company_id !== null;
    }

    /** The link the partner sends a client so the signup is attributed to them. */
    public function inviteUrl(): string
    {
        return route('register', ['ref' => $this->invite_token]);
    }
}
