<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;
    use Syncable;

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
            'loyalty_card_issued_at' => 'datetime',
        ];
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function loyaltyVerificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class, 'loyalty_verification_token_id');
    }

    public function hasLoyaltyCard(): bool
    {
        return $this->loyalty_card_number !== null;
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Sales orders this customer has placed. */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /**
     * What this customer has asked the service desk for.
     *
     * The desk keeps no customer list of its own — this is the customer, and
     * a ticket points at it.
     */
    public function serviceTickets(): HasMany
    {
        return $this->hasMany(ServiceTicket::class)->latest('opened_at');
    }

    /**
     * Buildings this contact owns and the business manages for them.
     *
     * A landlord is an ordinary contact — the estate module keeps no owner
     * list of its own, for the same reason the service desk keeps no customer
     * list.
     */
    public function propertiesOwned(): HasMany
    {
        return $this->hasMany(Property::class, 'landlord_contact_id');
    }

    /**
     * Cargo this contact has sent and cargo addressed to them. The transport
     * module keeps no party list of its own — sender and receiver are rows in
     * the same customer book everything else bills against.
     */
    public function shipmentsSent(): HasMany
    {
        return $this->hasMany(Shipment::class, 'sender_id')->latest();
    }

    public function shipmentsReceived(): HasMany
    {
        return $this->hasMany(Shipment::class, 'receiver_id')->latest();
    }

    /** Units this contact holds or has held as a tenant. */
    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class, 'tenant_contact_id')->latest('moved_in_on');
    }

    /**
     * Recompute what this customer owes, from the documents themselves.
     *
     * `balance` is a cached rollup — the customers list sorts and pages on it,
     * which a computed column cannot do — but it is derived here rather than
     * nudged up and down at each event. Incremental maintenance was already
     * wrong in a way nobody could miss for long: payments and voids decremented
     * it and *nothing* incremented it, so a customer invoiced 1 000 and paying
     * 400 was recorded as owing minus 400, the "owing" badge (which shows only
     * above zero) never appeared, and the list sorted by amount owed put the
     * worst debtors last.
     *
     * Recomputing from truth is also self-healing: a missed call leaves the
     * figure stale for a moment rather than permanently skewed, and any later
     * event on that customer puts it right.
     *
     * Credit notes subtract. An issued credit note is money the business has
     * said in writing it is no longer owed.
     */
    public function recomputeBalance(): void
    {
        $documents = Document::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company_id)
            ->where('contact_id', $this->id)
            ->issued()
            ->get(['type', 'balance']);

        $owed = $documents->sum(
            fn (Document $document) => $document->type->customerAccountSign() * (float) $document->balance
        );

        $this->forceFill(['balance' => round($owed, 2)])->save();
    }

    /**
     * Cover this contact holds as a client of the brokerage.
     *
     * The insurance vertical keeps no client list of its own — the
     * policyholder IS this contact, and a policy points at it.
     */
    public function policiesHeld(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class, 'holder_contact_id');
    }

    /** Policies this contact underwrites, where it is the insurer. */
    public function policiesUnderwritten(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class, 'insurer_contact_id');
    }

    /** Commission this contact owes the brokerage, where it is the insurer. */
    public function policyCommissions(): HasMany
    {
        return $this->hasMany(PolicyCommission::class, 'insurer_contact_id');
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
