<?php

namespace Tests\Feature\Insurance;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\InsuranceEndorsement;
use App\Models\InsurancePolicyInvoice;
use App\Models\InsurancePolicyRenewal;
use App\Services\DocumentIssuer;
use App\Support\DomainEvents;
use RuntimeException;

/**
 * The acts a broker actually performs on a live book: renewing a term,
 * endorsing cover mid-term, cancelling with the unexpired premium returned,
 * and billing a premium by instalments. All of it through the ordinary
 * platform paths — history rows for the record, sales documents for the money.
 */
class PolicyLifecycleHardeningTest extends InsuranceTestCase
{
    // ── Renewal ─────────────────────────────────────────────────────────

    public function test_renewing_extends_cover_and_keeps_the_history(): void
    {
        $policy = $this->activePolicy([
            'covers_from' => now()->subMonths(11)->toDateString(),
            'covers_to' => now()->addDays(10)->toDateString(),
            'renewal_term_months' => 12,
            'premium' => 850_000,
        ]);

        $oldTo = $policy->covers_to;

        $renewal = $this->policies()->renew($policy, [
            'new_premium' => 900_000,
            'method' => 'negotiated',
        ], $this->owner);

        $policy->refresh();

        // The new term starts the day after the old one ends — no gap, no overlap.
        $this->assertSame($oldTo->copy()->addDay()->toDateString(), $policy->covers_from->toDateString());
        $this->assertSame($oldTo->copy()->addDay()->addMonths(12)->toDateString(), $policy->covers_to->toDateString());
        $this->assertSame('900000.00', (string) $policy->premium);
        $this->assertSame('active', $policy->status);

        // notice_by follows the new end date through the saving hook.
        $this->assertSame(
            $policy->covers_to->copy()->subDays($policy->notice_period_days ?? 0)->toDateString(),
            $policy->notice_by?->toDateString() ?? $policy->covers_to->toDateString(),
        );

        $this->assertInstanceOf(InsurancePolicyRenewal::class, $renewal);
        $this->assertSame($oldTo->toDateString(), $renewal->previous_covers_to->toDateString());
        $this->assertSame('850000.00', (string) $renewal->previous_premium);
        $this->assertSame('900000.00', (string) $renewal->new_premium);
        $this->assertSame(50_000.0, $renewal->premiumChange());
        $this->assertCount(1, $policy->renewals);
    }

    public function test_renewing_twice_keeps_both_rows(): void
    {
        $policy = $this->activePolicy(['renewal_term_months' => 12]);

        $this->policies()->renew($policy, [], $this->owner);
        $this->policies()->renew($policy->fresh(), [], $this->owner);

        $this->assertCount(2, $policy->fresh()->renewals);
    }

    public function test_an_explicit_new_end_date_wins_over_the_term(): void
    {
        $policy = $this->activePolicy(['renewal_term_months' => 12]);
        $wanted = $policy->covers_to->copy()->addMonths(6);

        $this->policies()->renew($policy, ['new_covers_to' => $wanted->toDateString()], $this->owner);

        $this->assertSame($wanted->toDateString(), $policy->fresh()->covers_to->toDateString());
    }

    public function test_only_bound_cover_can_be_renewed(): void
    {
        $policy = $this->policy();

        $this->expectException(RuntimeException::class);

        $this->policies()->renew($policy, [], $this->owner);
    }

    public function test_a_renewal_must_extend_the_cover(): void
    {
        $policy = $this->activePolicy(['renewal_term_months' => 12]);

        $this->expectException(RuntimeException::class);

        $this->policies()->renew($policy, [
            'new_covers_to' => $policy->covers_to->copy()->subDay()->toDateString(),
        ], $this->owner);
    }

    public function test_open_cover_has_nothing_to_renew(): void
    {
        $policy = $this->activePolicy(['covers_to' => null, 'renewal_type' => 'none']);

        $this->expectException(RuntimeException::class);

        $this->policies()->renew($policy, [], $this->owner);
    }

    // ── Endorsements ────────────────────────────────────────────────────

    public function test_an_additional_premium_endorsement_drafts_a_debit_note(): void
    {
        $policy = $this->activePolicy(['premium' => 850_000]);

        $endorsement = $this->policies()->endorse($policy, [
            'description' => 'Vehicle swapped for a newer model; sum insured raised.',
            'new_premium' => 910_000,
        ], $this->owner);

        $this->assertInstanceOf(InsuranceEndorsement::class, $endorsement);
        $this->assertSame(60_000.0, (float) $endorsement->premium_delta);
        $this->assertSame('910000.00', (string) $policy->fresh()->premium);

        $note = $endorsement->adjustmentDocument;
        $this->assertNotNull($note);
        $this->assertSame(DocumentType::DebitNote, $note->type);
        $this->assertSame(DocumentStatus::Draft, $note->status);
        $this->assertSame($policy->holder_contact_id, $note->contact_id);
        $this->assertEqualsWithDelta(60_000.0, (float) $note->total, 0.01);
    }

    public function test_a_return_premium_endorsement_drafts_a_credit_note(): void
    {
        $policy = $this->activePolicy(['premium' => 850_000]);

        $endorsement = $this->policies()->endorse($policy, [
            'description' => 'Cover reduced to third party only.',
            'new_premium' => 700_000,
        ], $this->owner);

        $this->assertSame(-150_000.0, (float) $endorsement->premium_delta);
        $this->assertSame(DocumentType::CreditNote, $endorsement->adjustmentDocument->type);
        $this->assertEqualsWithDelta(150_000.0, (float) $endorsement->adjustmentDocument->total, 0.01);
    }

    public function test_a_no_money_endorsement_is_paperwork_only(): void
    {
        $policy = $this->activePolicy(['premium' => 850_000]);

        $endorsement = $this->policies()->endorse($policy, [
            'description' => 'Registration plate corrected.',
        ], $this->owner);

        $this->assertFalse($endorsement->movesMoney());
        $this->assertNull($endorsement->document_id);
        $this->assertSame('850000.00', (string) $policy->fresh()->premium);
    }

    public function test_only_live_cover_can_be_endorsed(): void
    {
        $policy = $this->policy();

        $this->expectException(RuntimeException::class);

        $this->policies()->endorse($policy, ['description' => 'x'], $this->owner);
    }

    public function test_an_endorsement_needs_a_description(): void
    {
        $policy = $this->activePolicy();

        $this->expectException(RuntimeException::class);

        $this->policies()->endorse($policy, ['description' => '  '], $this->owner);
    }

    // ── Cancellation with return premium ────────────────────────────────

    public function test_cancelling_mid_term_computes_the_unexpired_pro_rata(): void
    {
        $policy = $this->activePolicy([
            'covers_from' => now()->subDays(100)->toDateString(),
            'covers_to' => now()->addDays(265)->toDateString(),
            'premium' => 365_000, // 1 000 a day over a 365-day term.
        ]);

        $this->assertEqualsWithDelta(265_000.0, $this->policies()->returnPremium($policy), 0.01);
    }

    public function test_cancelling_raises_a_credit_note_against_the_issued_premium_invoice(): void
    {
        $policy = $this->activePolicy([
            'covers_from' => now()->subDays(100)->toDateString(),
            'covers_to' => now()->addDays(265)->toDateString(),
            'premium' => 365_000,
        ]);

        $invoice = $this->policies()->invoicePremium($policy, $this->owner);
        app(DocumentIssuer::class)->issue($invoice, $this->owner);

        $this->policies()->cancel($policy, ['reason' => 'Vehicle sold.'], $this->owner);

        $policy->refresh();
        $this->assertSame('cancelled', $policy->status);

        $note = Document::query()
            ->ofType(DocumentType::CreditNote)
            ->where('contact_id', $policy->holder_contact_id)
            ->first();

        $this->assertNotNull($note, 'Cancelling mid-term should draft a return-premium credit note.');
        $this->assertSame(DocumentStatus::Draft, $note->status);
        $this->assertSame($invoice->id, $note->parent_document_id);
        $this->assertEqualsWithDelta(265_000.0, (float) $note->total, 0.01);

        // Linked back to the policy like every other premium document.
        $this->assertTrue(
            InsurancePolicyInvoice::query()
                ->where('insurance_policy_id', $policy->id)
                ->where('document_id', $note->id)
                ->exists()
        );
    }

    public function test_cancelling_with_no_premium_invoice_still_cancels_without_a_note(): void
    {
        $policy = $this->activePolicy();

        $this->policies()->cancel($policy, [], $this->owner);

        $this->assertSame('cancelled', $policy->fresh()->status);
        $this->assertSame(0, Document::query()->ofType(DocumentType::CreditNote)->count());
    }

    // ── Instalment schedules ────────────────────────────────────────────

    public function test_instalments_generate_all_invoices_up_front_with_stepped_due_dates(): void
    {
        $policy = $this->activePolicy(['premium' => 850_000]);

        $invoices = $this->policies()->invoicePremiumInstalments($policy, $this->owner, 4);

        $this->assertCount(4, $invoices);
        $this->assertEqualsWithDelta(
            850_000.0,
            collect($invoices)->sum(fn (Document $d) => (float) $d->total),
            0.01,
        );

        // All drafts to the holder, due dates one month apart, ascending.
        $dues = collect($invoices)->map(fn (Document $d) => $d->due_date->toDateString());
        $this->assertSame($dues->sort()->values()->all(), $dues->values()->all());
        $this->assertSame(4, $dues->unique()->count());

        foreach ($invoices as $invoice) {
            $this->assertSame(DocumentStatus::Draft, $invoice->status);
            $this->assertSame($policy->holder_contact_id, $invoice->contact_id);
        }

        $this->assertSame(4, InsurancePolicyInvoice::query()
            ->where('insurance_policy_id', $policy->id)->count());
    }

    public function test_instalment_rounding_lands_on_the_first_invoice(): void
    {
        $policy = $this->activePolicy(['premium' => 100_000]);

        $invoices = $this->policies()->invoicePremiumInstalments($policy, $this->owner, 3);

        $totals = collect($invoices)->map(fn (Document $d) => (float) $d->total)->all();

        // XAF has no minor unit, so the slices are whole francs and the
        // remainder — 100 000 − 2 × 33 333 — lands on the first instalment.
        $this->assertEqualsWithDelta(100_000.0, array_sum($totals), 0.001);
        $this->assertEqualsWithDelta(33_334.0, $totals[0], 0.01);
    }

    public function test_a_single_instalment_is_refused_use_the_ordinary_invoice(): void
    {
        $policy = $this->activePolicy();

        $this->expectException(RuntimeException::class);

        $this->policies()->invoicePremiumInstalments($policy, $this->owner, 1);
    }

    // ── Catalogue ───────────────────────────────────────────────────────

    public function test_the_insurance_events_are_in_the_catalogue(): void
    {
        foreach ([
            'insurance.policy.placed',
            'insurance.policy.bound',
            'insurance.policy.renewed',
            'insurance.policy.endorsed',
            'insurance.policy.cancelled',
            'insurance.premium.invoiced',
            'insurance.claim.opened',
            'insurance.claim.settled',
            'insurance.claim.rejected',
        ] as $event) {
            $this->assertTrue(
                DomainEvents::exists($event),
                "{$event} should be selectable by automation and notification rules.",
            );
        }
    }
}
