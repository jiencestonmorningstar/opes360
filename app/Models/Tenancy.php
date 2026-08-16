<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Somebody living in (or trading from) a unit, on a lease, paying rent.
 *
 * The tenancy row itself holds only what nothing else can: which unit, which
 * tenant, and the deposit's ledger refs. The lease terms live on the linked
 * Contract, the rent billing on the linked RecurringInvoice, and the deposit
 * money in journal_lines — this row is the knot that ties them together.
 */
class Tenancy extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rent' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'deposit_retained' => 'decimal:2',
            'moved_in_on' => 'date',
            'moved_out_on' => 'date',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class, 'property_unit_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'tenant_contact_id');
    }

    /**
     * The lease. Named `lease()` rather than `contract()` for the reader —
     * and there is no `lease` column on this table, so the attribute-shadowing
     * trap `Contract::counterparty()` documents cannot bite. There must never
     * be one.
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** The standing order that bills the rent. */
    public function rentSchedule(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_invoice_id');
    }

    public function depositEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'deposit_entry_id');
    }

    public function depositSettlementEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'deposit_settlement_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The rent's history, oldest first — written only by Tenancies::reviewRent(). */
    public function rentChanges(): HasMany
    {
        return $this->hasMany(TenancyRentChange::class)->orderBy('effective_on');
    }

    /**
     * The paperwork — move-in and move-out inspection checklists above all —
     * through the same shared relations table Property and Contract use.
     * An inspection IS a managed paper (Documents 2.14 checklists), attached
     * here rather than run by an inspection engine of our own.
     */
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return $this->status === 'active'
            ? ['label' => 'Active', 'tone' => 'positive']
            : ['label' => 'Ended', 'tone' => 'muted'];
    }
}
