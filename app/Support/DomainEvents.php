<?php

namespace App\Support;

/**
 * Every event the product announces.
 *
 * A catalogue rather than scattered string literals, for the same reason
 * Permissions is a catalogue: an automation rule listening for
 * `document.pubished` would simply never fire, and nothing would say why.
 * A screen offering triggers reads this list, so a typo cannot be chosen.
 *
 * Names are `module.thing.happened`, past tense. Past tense because an event
 * reports what has already occurred — anything named as an instruction is a
 * command, and commands belong in a service, not on a bus.
 */
class DomainEvents
{
    /** module => [event, ...] */
    public const CATALOGUE = [
        /*
         * Documents. §59 of the master brief names these exactly; they are
         * reproduced verbatim so a rule written against the brief works.
         */
        'document' => [
            'document.created',
            'document.updated',
            'document.version.created',
            'document.submitted',
            'document.review.requested',
            'document.changes.requested',
            'document.approved',
            'document.rejected',
            'document.signature.requested',
            'document.signed',
            'document.published',
            'document.archived',
            'document.expired',
            'document.shared',

            /*
             * §2.15 reminders, raised by `opes:remind-actions` rather than by
             * anything a user did. They are still events, not states: the
             * command fires each one at most once per subject per day, so a
             * rule listening here hears "this is still waiting" as one daily
             * nudge instead of a stuck alarm.
             */
            'document.expiring',
            'document.signature.overdue',

            /*
             * Not named by §59 verbatim, but real lifecycle moments the brief's
             * list left implicit — the 2026-08-17 completion pass catalogues
             * them so a rule can be written against each:
             *
             * - `document.voided`: the one thing that can happen to an issued
             *   document besides expiring. A business wants to know when a
             *   signed contract stops being valid, same as it wants to know
             *   when one starts.
             * - `document.commented`: somebody left a note on a document. The
             *   mention notification already exists (DocumentCommentMention);
             *   this is the event a broader "notify the document's owner"
             *   rule would listen for, mentioned or not.
             * - `document.version.restored`: a draft was rolled back to an
             *   earlier version — distinct from `document.version.created`,
             *   which also fires for it, because "somebody undid recent work"
             *   is a different thing to react to than "somebody saved".
             * - `document.legal_hold.placed` / `.lifted`: the moment
             *   retention's one override engages or disengages — the event
             *   compliance or legal would want to be told about directly,
             *   rather than having to notice a document silently became
             *   undeletable.
             */
            'document.voided',
            'document.commented',
            'document.version.restored',
            'document.legal_hold.placed',
            'document.legal_hold.lifted',
        ],

        // The approval engine. What a rule listens to in order to advance
        // something else when an approval lands.
        'workflow' => [
            'workflow.started',
            'workflow.step.assigned',
            'workflow.approved',
            'workflow.rejected',
            'workflow.changes_requested',
            'workflow.stalled',
            'workflow.cancelled',

            // §2.15: raised by the daily `opes:remind-actions` sweep for a
            // pending assignment past its step's due date. Once per
            // assignment per day — see RemindOutstandingActions.
            'workflow.assignment.overdue',
        ],

        'expense' => [
            'expense.recorded',
            'expense.paid',
            'expense.voided',
        ],

        'sales' => [
            'sales.document.issued',
            'sales.document.voided',
            'sales.payment.recorded',
        ],

        /*
         * Customer orders and fulfilment. Each is the moment the services
         * emit it — confirm commits stock, deliver moves it, invoice bills
         * it, and a recorded return is goods physically back on the shelf.
         */
        'orders' => [
            'order.confirmed',
            'order.delivered',
            'order.invoiced',
            'order.return.recorded',
        ],

        /*
         * Compliance. The events a business wants a rule against: tell the
         * accountant when a return is filed, tell the director when one is
         * refused. Deliberately no `compliance.obligation.overdue` — overdue
         * is a state the calendar computes, not a moment something happened,
         * and an event for it would need a scheduler to fire it and would
         * then fire again every day it stayed true.
         */
        'compliance' => [
            'compliance.filing.completed',
            'compliance.filing.rejected',
        ],

        /*
         * The brokerage book. These are the moments a rule wants: tell the
         * account handler when cover binds or renews, tell the principal when
         * a claim opens or a settlement lands. `premium.invoiced` fires when
         * the draft is created — issuing it is Sales' moment and already has
         * `sales.document.issued`.
         */
        'insurance' => [
            'insurance.policy.placed',
            'insurance.policy.bound',
            'insurance.policy.renewed',
            'insurance.policy.endorsed',
            'insurance.policy.cancelled',
            'insurance.premium.invoiced',
            'insurance.claim.opened',
            'insurance.claim.settled',
            'insurance.claim.rejected',
        ],

        'risk' => [
            'risk.reviewed',
            'risk.closed',
        ],

        /*
         * Logistics. The booked and delivered events carry `tracking_url` in
         * their context, so a notification rule can hand the receiver their
         * tracking link without asking the module anything. `failed` is the
         * exception path — a delivery attempt that did not land, with the
         * reason in context — the event a "ring the customer" rule wants.
         * Dispatch is announced by the manifest, not shipment-by-shipment:
         * one van leaving is one fact.
         */
        'logistics' => [
            'logistics.shipment.booked',
            'logistics.shipment.delivered',
            'logistics.shipment.failed',
            'logistics.shipment.invoiced',
            'logistics.manifest.dispatched',
        ],

        // Estate. The three moments a rule would want: somebody moved in,
        // somebody moved out, the rent changed. All emitted by the Tenancies
        // service; vacancy and arrears are states the board computes, not
        // moments, so they are deliberately not here.
        'estate' => [
            'estate.tenancy.started',
            'estate.tenancy.ended',
            'estate.tenancy.rent_changed',
        ],

        /*
         * The service desk and the visits that resolve it. Each is the moment
         * TicketDesk / ServiceScheduling / ServiceBilling emit it; `sla.breached`
         * is also raised by the `service:sla-sweep` command for a promise that
         * quietly expired between page loads.
         */
        'service' => [
            'service.ticket.opened',
            'service.ticket.assigned',
            'service.ticket.reprioritised',
            'service.ticket.resolved',
            'service.ticket.closed',
            'service.ticket.reopened',
            'service.sla.breached',
            'service.job.scheduled',
            'service.job.completed',
            'service.job.billed',
        ],

        /*
         * Sourcing: asked, quoted, chosen. `quotation.awarded` is where the
         * spend is committed, which is the event an approval-adjacent rule
         * actually wants.
         */
        'procurement' => [
            'requisition.created',
            'rfq.opened',
            // Closed is the shutter coming down with quotes in hand;
            // cancelled is the enquiry withdrawn before any decision.
            'rfq.closed',
            'rfq.cancelled',
            'quotation.received',
            'quotation.awarded',
        ],

        // Agreements. Raised is the draft, activated is the signature,
        // renewed commits another term, terminated ends one early.
        'contract' => [
            'contract.raised',
            'contract.activated',
            'contract.renewed',
            'contract.terminated',
        ],

        // The register's two movements that are acts rather than book-keeping:
        // equipment handed to somebody, and a service actually done.
        'asset' => [
            'asset.transferred',
            'asset.serviced',
        ],

        'hr' => [
            'hr.employee.created',
            'hr.employee.ended',
            'hr.leave.requested',
            'hr.leave.approved',
        ],
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::CATALOGUE));
    }

    /** @return array<int, string> */
    public static function forModule(string $module): array
    {
        return self::CATALOGUE[$module] ?? [];
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    /** @return array<int, string> */
    public static function modules(): array
    {
        return array_keys(self::CATALOGUE);
    }
}
