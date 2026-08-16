<?php

namespace App\Services\Estate;

use App\Models\Company;
use App\Models\Contract;
use App\Models\LedgerAccount;
use App\Models\PropertyUnit;
use App\Models\RecurringInvoice;
use App\Models\ServiceTicket;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Contracts\ContractLifecycle;
use App\Services\Service\TicketDesk;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\Aging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moving a tenant in, and moving them out with the money answered for.
 *
 * ── What this deliberately does NOT contain ────────────────────────────────
 *
 * No lease machinery: the lease is an ordinary Contract raised through
 * ContractLifecycle, so renewal, the notice deadline and the watchlist come
 * from the contracts module. No billing: rent is a RecurringInvoice the
 * nightly generator and dunning already run. No deposit column pretending to
 * be money: the caution is posted through Ledger::post() as a liability the
 * moment it is taken, and cleared through the same door at move-out. A
 * vertical that grows its own copy of any of these has failed before it
 * ships.
 *
 * ── The deposit account, argued ────────────────────────────────────────────
 *
 * 165 « Dépôts et cautionnements reçus » rather than the 4191 family. 4191 is
 * a customer's *advance against future invoices* — money that will become
 * revenue when the invoice lands. A caution never becomes revenue in the
 * ordinary course: it is held for the term of the lease and given back, which
 * is exactly what class 1's "ressources durables" describes and why the plan
 * gives deposits received their own account there. Only the retained part, at
 * settlement and for a stated reason, becomes income — through 7078 « Autres
 * produits accessoires », because keeping part of a caution is income
 * accessory to the letting activity, not a sale of anything.
 */
class Tenancies
{
    /** SYSCOHADA: deposits received, held to be returned. */
    public const DEPOSIT_ACCOUNT = ['165', 'Dépôts et cautionnements reçus'];

    /** Where a retained deposit becomes income, at settlement only. */
    public const RETENTION_ACCOUNT = ['7078', 'Autres produits accessoires'];

    public function __construct(
        protected ContractLifecycle $contracts,
        protected Ledger $ledger,
    ) {}

    /**
     * Move a tenant in: the lease, the deposit, the rent, the unit — together
     * or not at all.
     *
     * @param  array{tenant_contact_id: string, rent: float, deposit_amount?: float,
     *               moved_in_on?: ?string, ends_on?: ?string, renewal_type?: string,
     *               renewal_term_months?: ?int, notice_period_days?: ?int,
     *               payment_terms_days?: int, notes?: ?string}  $data
     */
    public function start(PropertyUnit $unit, array $data, ?User $actor = null): Tenancy
    {
        $company = Company::query()->findOrFail($unit->company_id);

        if ($unit->status === 'unavailable') {
            throw new RuntimeException(
                "{$unit->label} is marked unavailable. Make it lettable before moving anyone in."
            );
        }

        /*
         * One open tenancy per door. Checked in words, not left to an index:
         * "somebody already lives there" is a sentence an agent can act on,
         * and the refusal has to name who.
         */
        $sitting = $unit->tenancies()->where('status', 'active')->with('tenant')->first();

        if ($sitting !== null) {
            throw new RuntimeException(
                "{$unit->label} is already let to ".($sitting->tenant?->displayName() ?? 'a tenant').
                '. End that tenancy before starting another.'
            );
        }

        $rent = round((float) $data['rent'], 2);

        if ($rent <= 0) {
            throw new RuntimeException('A tenancy needs a rent. Zero is not a letting.');
        }

        $deposit = round((float) ($data['deposit_amount'] ?? 0), 2);

        if ($deposit < 0) {
            throw new RuntimeException('A deposit cannot be negative.');
        }

        $movedIn = isset($data['moved_in_on']) && $data['moved_in_on'] !== null
            ? Carbon::parse($data['moved_in_on'])->startOfDay()
            : Carbon::today();

        return DB::transaction(function () use ($unit, $company, $data, $rent, $deposit, $movedIn, $actor) {
            $property = $unit->property()->withTrashed()->first();

            // The lease is a real Contract: the watch, renewal and notice
            // machinery all come from it. Activated immediately — the parties
            // signed at the door; the approval workflow is for agreements
            // still being negotiated, and a tenant holding keys is not one.
            $lease = $this->contracts->raise([
                'title' => 'Lease — '.($property?->name ? $property->name.', ' : '').$unit->label,
                'contact_id' => $data['tenant_contact_id'],
                'direction' => 'outbound',
                'type' => 'lease',
                'value' => $rent,
                'starts_on' => $movedIn->toDateString(),
                'ends_on' => $data['ends_on'] ?? null,
                'renewal_type' => $data['renewal_type'] ?? 'none',
                'renewal_term_months' => $data['renewal_term_months'] ?? null,
                'notice_period_days' => $data['notice_period_days'] ?? null,
                'description' => $data['notes'] ?? null,
            ], $actor);

            $this->contracts->activate($lease, $actor);

            // Rent rides the existing recurring path — the nightly generator
            // dates each invoice on its period, issues it, and dunning chases
            // it, without knowing the estate module exists.
            $schedule = RecurringInvoice::create([
                'company_id' => $company->id,
                'contact_id' => $data['tenant_contact_id'],
                'name' => 'Rent — '.$unit->label.($property?->name ? ', '.$property->name : ''),
                'frequency' => 'monthly',
                'interval' => 1,
                'lines' => [[
                    'description' => 'Rent — '.$unit->label.($property?->name ? ', '.$property->name : ''),
                    'quantity' => 1,
                    'unit' => 'month',
                    'unit_price' => $rent,
                ]],
                'discount_percent' => 0,
                'payment_terms_days' => $data['payment_terms_days'] ?? 7,
                'auto_issue' => true,
                'starts_on' => $movedIn->toDateString(),
                'next_run_on' => $movedIn->toDateString(),
                'occurrences' => 0,
                'status' => RecurringInvoice::ACTIVE,
                'created_by' => $actor?->id,
            ]);

            $tenancy = Tenancy::create([
                'company_id' => $company->id,
                'property_unit_id' => $unit->id,
                'tenant_contact_id' => $data['tenant_contact_id'],
                'contract_id' => $lease->id,
                'rent' => $rent,
                'deposit_amount' => $deposit,
                'recurring_invoice_id' => $schedule->id,
                'moved_in_on' => $movedIn->toDateString(),
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            /*
             * The caution, into the books the day it is taken. Debit the bank
             * — the money arrived — and credit 165, because it is the
             * tenant's money in the business's keeping, not income. Posted
             * with the tenancy as source, so a retried move-in cannot record
             * the same caution twice.
             */
            if ($deposit > 0) {
                $entry = $this->ledger->post(
                    $company,
                    'BQ',
                    $movedIn->toDateString(),
                    [
                        ['account' => 'bank', 'debit' => $deposit, 'narration' => 'Caution reçue — '.$unit->label],
                        ['account' => $this->account($company, self::DEPOSIT_ACCOUNT), 'credit' => $deposit],
                    ],
                    source: $tenancy,
                    narration: 'Dépôt de garantie — '.$unit->label,
                    actor: $actor,
                );

                $tenancy->forceFill(['deposit_entry_id' => $entry->id])->save();
            }

            $unit->forceFill(['status' => 'occupied'])->save();

            $tenancy->emitDomainEvent('estate.tenancy.started', [
                'tenancy_id' => $tenancy->id,
                'unit_id' => $unit->id,
                'tenant_id' => $tenancy->tenant_contact_id,
                'contract_id' => $lease->id,
                'rent' => $rent,
                'deposit' => $deposit,
            ]);

            return $tenancy->refresh();
        });
    }

    /**
     * Move the tenant out: settle the caution, free the door, end the lease.
     *
     * @param  array{retained?: float, retention_reason?: ?string, on?: ?string,
     *               force?: bool, force_reason?: ?string}  $data
     */
    public function endTenancy(Tenancy $tenancy, array $data = [], ?User $actor = null): Tenancy
    {
        if (! $tenancy->isActive()) {
            throw new RuntimeException('This tenancy has already ended.');
        }

        $tenancy->loadMissing(['unit', 'tenant', 'lease', 'rentSchedule']);

        $company = Company::query()->findOrFail($tenancy->company_id);

        /*
         * Unpaid rent blocks the move-out, by name and by amount. Somebody
         * can still force it — tenants do leave owing money — but only by
         * saying why, because "the balance was written off in a hurry" is the
         * finding this refusal exists to prevent.
         */
        $arrears = (new Aging)->forParty($tenancy->tenant)['total'];

        if ($arrears > 0 && ! ($data['force'] ?? false)) {
            throw new RuntimeException(
                ($tenancy->tenant?->displayName() ?? 'This tenant').' still owes '.
                number_format($arrears, 2).'. Collect it, or force the move-out with a reason.'
            );
        }

        if (($data['force'] ?? false) && $arrears > 0 && blank($data['force_reason'] ?? null)) {
            throw new RuntimeException(
                'Forcing a move-out over unpaid rent needs a reason — it is the only record of why the money was let go.'
            );
        }

        $retained = round((float) ($data['retained'] ?? 0), 2);
        $deposit = round((float) $tenancy->deposit_amount, 2);

        if ($retained < 0 || $retained > $deposit) {
            throw new RuntimeException(
                "The retained amount must be between 0 and the deposit held (".number_format($deposit, 2).').'
            );
        }

        if ($retained > 0 && blank($data['retention_reason'] ?? null)) {
            throw new RuntimeException(
                'Keeping part of a deposit needs a reason. It is the tenant\'s money until a reason says otherwise.'
            );
        }

        $movedOut = isset($data['on']) && $data['on'] !== null
            ? Carbon::parse($data['on'])->startOfDay()
            : Carbon::today();

        if ($movedOut->lt($tenancy->moved_in_on->copy()->startOfDay())) {
            throw new RuntimeException('A tenancy cannot end before it began.');
        }

        return DB::transaction(function () use ($tenancy, $company, $data, $retained, $deposit, $movedOut, $actor) {
            /*
             * Settle the caution in one entry: the liability is emptied, the
             * refund leaves the bank, and the retained part becomes income —
             * all three legs together, so the trial balance cannot catch the
             * settlement half-done.
             */
            if ($deposit > 0 && $tenancy->deposit_settlement_entry_id === null) {
                $refund = round($deposit - $retained, 2);

                $lines = [[
                    'account' => $this->account($company, self::DEPOSIT_ACCOUNT),
                    'debit' => $deposit,
                    'narration' => 'Caution soldée — '.($tenancy->unit?->label ?? ''),
                ]];

                if ($refund > 0) {
                    $lines[] = ['account' => 'bank', 'credit' => $refund, 'narration' => 'Caution remboursée'];
                }

                if ($retained > 0) {
                    $lines[] = [
                        'account' => $this->account($company, self::RETENTION_ACCOUNT),
                        'credit' => $retained,
                        'narration' => 'Caution retenue — '.$data['retention_reason'],
                    ];
                }

                $entry = $this->ledger->post(
                    $company,
                    'BQ',
                    $movedOut->toDateString(),
                    $lines,
                    narration: 'Règlement du dépôt de garantie — '.($tenancy->unit?->label ?? ''),
                    actor: $actor,
                );

                $tenancy->forceFill(['deposit_settlement_entry_id' => $entry->id]);
            }

            // Stop the rent. FINISHED rather than deleted — the schedule is
            // the record of what was billed and on what rhythm.
            $tenancy->rentSchedule?->forceFill(['status' => RecurringInvoice::FINISHED])->save();

            // End the lease through the one lifecycle, so the register and
            // the watch agree the agreement is over. An already-expired or
            // terminated lease is left as it stands.
            if ($tenancy->lease !== null && $tenancy->lease->isActive()) {
                $this->contracts->terminate($tenancy->lease, [
                    'on' => $movedOut->toDateString(),
                    'reason' => 'Tenancy ended'.(filled($data['force_reason'] ?? null) ? ' — '.$data['force_reason'] : ''),
                ], $actor);
            }

            $tenancy->unit?->forceFill(['status' => 'vacant'])->save();

            $tenancy->forceFill([
                'status' => 'ended',
                'moved_out_on' => $movedOut->toDateString(),
                'deposit_retained' => $deposit > 0 ? $retained : null,
                'deposit_retention_reason' => $retained > 0 ? $data['retention_reason'] : null,
                'notes' => filled($data['force_reason'] ?? null)
                    ? trim(($tenancy->notes ? $tenancy->notes."\n" : '').'Forced move-out: '.$data['force_reason'])
                    : $tenancy->notes,
                'ended_by' => $actor?->id,
            ])->save();

            $tenancy->emitDomainEvent('estate.tenancy.ended', [
                'tenancy_id' => $tenancy->id,
                'unit_id' => $tenancy->property_unit_id,
                'retained' => $retained,
                'moved_out_on' => $movedOut->toDateString(),
            ]);

            return $tenancy->refresh();
        });
    }

    /**
     * A maintenance request IS a service ticket — same desk, same SLA clock,
     * same board. This only fills in what the estate side knows: which door,
     * and that the tenant is the customer.
     *
     * @param  array{subject: string, description?: ?string, priority?: string}  $data
     */
    public function reportMaintenance(Tenancy $tenancy, array $data, User $by): ServiceTicket
    {
        $tenancy->loadMissing('unit.property');

        $ticket = app(TicketDesk::class)->open([
            'contact_id' => $tenancy->tenant_contact_id,
            'subject' => '['.($tenancy->unit?->label ?? 'Unit').'] '.$data['subject'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'channel' => 'internal',
            'category' => 'maintenance',
        ], $by);

        // The desk's open() takes only its own columns; the pin on the map is
        // ours to write.
        $ticket->forceFill(['property_unit_id' => $tenancy->property_unit_id])->save();

        return $ticket->refresh();
    }

    /**
     * The estate accounts, created on first use.
     *
     * firstOrCreate rather than a seeder edit: the module ships off, and a
     * business that never lets a room should never grow accounts for it. The
     * side comes from the same rules the rest of the chart obeys.
     *
     * @param  array{0: string, 1: string}  $definition
     */
    protected function account(Company $company, array $definition): LedgerAccount
    {
        [$number, $name] = $definition;

        $class = LedgerAccount::classOf($number);

        return LedgerAccount::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(
                ['company_id' => $company->id, 'number' => $number],
                [
                    'name' => $name,
                    'class' => $class,
                    'normal_balance' => ChartOfAccounts::normalBalanceFor($number, $class),
                ],
            );
    }
}
