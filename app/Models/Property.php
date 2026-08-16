<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A building (or plot) the business manages, made of lettable units.
 *
 * Deliberately thin. The landlord is a Contact, the leases are Contracts, the
 * rent is recurring invoices, the maintenance is service tickets and the
 * paper trail is managed documents — a property is the address they all hang
 * off, not a second copy of any of them.
 */
class Property extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    public const KINDS = [
        'residential' => 'Residential',
        'commercial' => 'Commercial',
        'mixed' => 'Mixed use',
        'land' => 'Land',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['commission_percent' => 'decimal:2'];
    }

    /** Expenses pinned to this building — repairs, and landlord payouts. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class)->orderBy('issue_date');
    }

    /**
     * Not `landlord()` alone by accident of taste: the precedent set by
     * `Contract::counterparty()` is that a relation must never share a name
     * with a column, and `landlord_contact_id` makes `landlord` safe — but the
     * explicit name says which of the two Contacts around a tenancy this is.
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'landlord_contact_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(PropertyUnit::class)->orderBy('label');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The paperwork, through the shared relations table — see Contract::papers(). */
    public function documentRelations(): MorphMany
    {
        return $this->morphMany(BusinessDocumentRelation::class, 'related', 'related_type', 'related_id');
    }

    /** @return Collection<int, BusinessDocument> */
    public function papers()
    {
        return $this->documentRelations()->with('document')->get()
            ->pluck('document')->filter()->values();
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }
}
