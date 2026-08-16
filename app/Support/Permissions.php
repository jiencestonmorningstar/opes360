<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The canonical permission catalogue (Module 15).
 *
 * The seeder writes these rows and the auth layer defines a gate for every one
 * of them. They must come from the same place: an ability that exists as a
 * seeded permission but not as a gate is silently *denied* to everyone except
 * the Owner, which is the worst kind of failure — it looks like it works.
 */
class Permissions
{
    /** group => [action, ...] */
    public const CATALOGUE = [
        'Business' => ['view', 'update', 'manage-branding', 'manage-stationery'],
        'Sales' => ['view', 'create', 'update', 'issue', 'void', 'approve'],
        'Receipts' => ['view', 'create', 'void'],
        'Payments' => ['view', 'record', 'refund'],
        // Money going out. Separate from Payments, which is money coming in:
        // a cashier who may take a customer's money has no business recording
        // what the company spends.
        /*
         * `claim-*` are staff expense claims: money an employee paid
         * personally and is owed back. Separate from the `pay` above, which
         * settles what the business owes a supplier — a clerk who may enter
         * their own taxi fare has no business paying the electricity bill.
         *
         * There is deliberately no `claim-approve`. Approving is the workflow
         * engine's business, and docs/workflows.md is explicit that being
         * asked IS the permission: a second ability here would let the engine
         * assign an approver the gate then refuses.
         */
        'Expenses' => ['view', 'create', 'update', 'pay', 'void', 'claim-view', 'claim-create', 'claim-reimburse'],
        /*
         * People and pay. Three groups rather than one, because they are three
         * different jobs: a manager keeps the staff file, an accountant runs
         * the payroll, and only an owner should be able to approve a month and
         * post it to the books. Salaries are also the one thing in this system
         * that everybody is curious about and almost nobody should see.
         */
        'Employees' => ['view', 'create', 'update', 'delete'],
        'Payroll' => ['view', 'run', 'approve', 'pay', 'void'],
        'Leave' => ['view', 'request', 'approve'],
        // The post, not the person in it. Grouped with Departments rather than
        // Employees for the same reason: a job outlives whoever holds it.
        'Positions' => ['view', 'manage'],
        /*
         * Hiring. `interview` stands alone so a panel member can score the
         * candidates in front of them without sight of the whole pipeline —
         * most interviewers should not know what the other applicants asked
         * for. `offer` is separate from `manage` because an offer is the
         * money-shaped act: moving somebody between stages costs nothing,
         * an offer letter commits a salary.
         */
        'Recruitment' => ['view', 'manage', 'interview', 'offer'],
        /*
         * `record` is deliberately not implied by `view`. Seeing that a team
         * turned up is a supervisor's business; writing what hours somebody
         * worked is the input to a wage, and the two are not the same trust.
         */
        'Attendance' => ['view', 'record'],
        /*
         * No `acknowledge` here. An employee acknowledges their own review by
         * being its subject, exactly as an approver approves by being asked —
         * an ability would let an administrator grant somebody the right to
         * sign off a review that is not about them.
         */
        'Reviews' => ['view', 'manage'],
        'Customers' => ['view', 'create', 'update', 'delete'],
        // The sales pipeline. Separate from Sales, which is the paperwork:
        // a junior can chase a deal without being able to issue the invoice
        // it turns into, and that is the common arrangement rather than an
        // exotic one.
        'Deals' => ['view', 'create', 'update', 'delete'],
        // `manage-locations` sits in this group because it is about stock, but
        // belongs to its own module: a business can sell things from one shelf
        // without ever needing a warehouse. See config/modules.php.
        /*
         * `track-*` are lots, serial numbers and expiry — opt-in per product,
         * so a business selling t-shirts never meets them. Separate from
         * `adjust-stock` because recording which lot arrived is a different
         * job from correcting a count, and a pharmacy typically wants the
         * first devolved further than the second.
         */
        'Products' => ['view', 'create', 'update', 'delete', 'adjust-stock', 'manage-locations', 'track-view', 'track-manage', 'reserve'],
        // What the business owns and what it banks with. Both are the
        // accountant's ground rather than the shopkeeper's, which is why they
        // are separate groups instead of actions on Accounting.
        /*
         * `transfer` and `maintain` are separate from `update` because they are
         * a different job done by different people. A storeman signs equipment
         * out to a driver and books its next service; neither act should carry
         * the ability to restate what the thing cost or how fast it is being
         * written off, which is what `update` means here.
         */
        'Assets' => ['view', 'create', 'update', 'depreciate', 'dispose', 'transfer', 'maintain'],
        'Banking' => ['view', 'manage', 'import', 'reconcile'],
        /*
         * Deciding which bills to pay, and releasing the money.
         *
         * `approve` and `execute` are kept apart from `manage` on purpose.
         * PaymentScheduler::execute() refuses an unapproved run, and that
         * refusal is the only thing between a misclick and an emptied bank
         * account. One person building the run and another releasing it is
         * what makes that a control rather than a speed bump — so the seeder
         * must not grant them together by default.
         */
        'Payables' => ['view', 'manage', 'approve', 'execute', 'statement-view', 'reconcile'],
        /*
         * The steps before a purchase order: asking, quoting, choosing.
         *
         * Note there is no `approve` — a requisition goes through the workflow
         * engine, where being asked is the permission. `award` is separate
         * because choosing a supplier is where the money is actually committed;
         * everything before it is enquiry.
         */
        'Procurement' => ['requisition-view', 'requisition-manage', 'rfq-view', 'rfq-manage', 'rfq-award'],
        /*
         * Agreements with other people. `renew` and `terminate` are split out
         * from `manage` because each one either commits the business to
         * another term or ends something it is being paid under — writing a
         * draft is not the same act as signing the business up for a year.
         *
         * No `approve`, as everywhere else: being asked is the permission.
         */
        'Contracts' => ['view', 'manage', 'renew', 'terminate'],
        /*
         * Statutory duties and the evidence they were met. `file` is separate
         * from `manage` because keeping the calendar and swearing that a
         * return went in are different acts, and the second is the one
         * somebody may later have to stand behind.
         */
        'Compliance' => ['view', 'manage', 'file'],
        /*
         * `review` is deliberately not `manage`. Marking a risk down is the
         * one act the register exists to make somebody else do — a risk owner
         * who can quietly reassess their own risk turns the whole thing into
         * a list of things that used to worry people.
         */
        'Risks' => ['view', 'manage', 'review'],
        /*
         * The service desk. `bill` is split from `complete` because finishing
         * the work and charging for it are decided by different people —
         * a technician says the machine runs again; whether that visit is
         * chargeable under the customer's contract is not their call.
         *
         * No `approve`: being asked by the workflow is the permission.
         */
        'Service' => ['view', 'create', 'update', 'assign', 'schedule', 'complete', 'bill', 'manage-sla'],
        // Making things. `complete` splits out because completing an order
        // moves real stock and freezes its cost — the same trust as
        // `products.adjust-stock`, and not implied by writing a recipe.
        'Manufacturing' => ['view', 'manage', 'complete'],
        /*
         * Broking cover. `settle` is the money act — paying a claim commits
         * real funds and stays above the people who assess it, the same
         * split as payables' approve/execute.
         */
        'Insurance' => ['view', 'manage', 'settle'],
        /*
         * Outbound fulfilment. `confirm` commits stock (reservations),
         * `deliver` moves it off the shelf — two different trusts, and
         * neither is implied by writing the order up.
         */
        'Orders' => ['view', 'manage', 'confirm', 'deliver'],
        /*
         * Property management. `end-tenancy` settles the deposit — money
         * leaves the liability account — so it stays above the person who
         * keeps the day-to-day register.
         */
        'Estate' => ['view', 'manage', 'end-tenancy'],
        /*
         * Transport. `dispatch` commits the vehicle and every promise
         * aboard it; loading a manifest is paperwork, sending it is not.
         */
        'Logistics' => ['view', 'manage', 'dispatch'],
        /*
         * Who may read the trail, and who may read the report on who can do
         * what. Both are Owner and Administrator only.
         *
         * The audit log holds every change anyone has made, and the
         * governance report names the people whose permissions conflict.
         * Handing either to a wider audience turns a control into a
         * surveillance tool, and the second one tells whoever reads it
         * exactly which combination of abilities goes unwatched.
         */
        'Audit' => ['view', 'govern'],
        /*
         * Writing the rules that decide who gets told what. Not granted with
         * `settings.update`, because a rule is the difference between a
         * breach being noticed and not — muting one is a quiet act with loud
         * consequences, and the audit trail should show who did it.
         */
        'Notifications' => ['manage'],
        /*
         * Business documents. `share` and `manage` are separate from the rest
         * for two different reasons.
         *
         * `share` sends a document outside the business, which is a different
         * act from writing one: whoever holds it can put a signed contract in
         * front of somebody who was never meant to see it, and no amount of
         * `view` implies that.
         *
         * `manage` is the confidentiality key. A document marked `restricted`
         * is readable only by the person who owns it and by holders of this
         * ability, so granting it is granting sight of everything the business
         * has decided to keep close — which is why it stops at the Owner and
         * the Administrator in the seeder.
         */
        /*
         * Chargeable and internal work. `manage` covers creating a project and
         * changing its budget or client — the commercial terms. `log-time` is
         * separate because anybody doing the work logs hours against it
         * without being able to touch what it is worth.
         */
        'Projects' => ['view', 'manage', 'log-time'],
        'Papers' => ['view', 'create', 'issue', 'void', 'share', 'manage'],
        'Forms' => ['view', 'create', 'update', 'delete', 'responses'],
        'Events' => ['view', 'create', 'update', 'void', 'check-in'],
        'Loyalty' => ['view', 'manage', 'redeem'],
        // VIP membership. `sell` is separate from `manage` for the same
        // reason `sales.issue` is separate from `sales.create`: a
        // receptionist may sign somebody up for Gold without being able to
        // redesign the programme — change what Gold costs or what it gives.
        'Vip' => ['view', 'manage', 'sell'],
        'Reports' => ['view', 'export'],
        'Accounting' => ['view', 'export', 'manage'],
        // The secretariat programme. Not plan-gated — it is how a partner pays
        // the platform, so putting it behind a tier would be self-defeating —
        // but it is only reachable at all on a secretariat account. See
        // AuthServiceProvider, which adds that second condition to these gates.
        'Partners' => ['view', 'manage', 'issue', 'withdraw'],
        /*
         * Outbound webhooks. Granted to nobody but the Owner and the
         * Administrator, and that is the whole reason it is a group of its own
         * rather than an action on Settings.
         *
         * Subscribing an endpoint is not a settings change, it is a standing
         * export: whoever can add one can point `payment.recorded` at a server
         * they control and read every sale the business makes, for as long as
         * nobody notices. A manager who may see today's takings should not
         * thereby be able to arrange a copy of them forever, and a sales
         * officer certainly should not. `view` is separated from `manage` so
         * an auditor role could be given sight of the delivery log later
         * without being able to add a destination.
         */
        'Webhooks' => ['view', 'manage'],
        /*
         * Who may define an approval path — not who may approve. Being asked
         * to approve is itself the permission; requiring a second one would
         * mean an approver the engine had just assigned could not act.
         *
         * `manage` stops at the Owner and the Administrator because whoever
         * can edit a workflow can write themselves a path with no approver in
         * it, which is the same thing as being able to spend the money.
         */
        'Workflows' => ['view', 'manage'],
        /*
         * The org chart. Core rather than a module, and separate from
         * Employees because a department outlives the staff file: Documents
         * files by department and approval routing reads it, so a business
         * that switches HR off must not lose its filing along with it.
         */
        'Departments' => ['view', 'manage'],
        'Users' => ['view', 'invite', 'update-role', 'remove'],
        'Devices' => ['view', 'revoke'],
        'Settings' => ['view', 'update'],
    ];

    /**
     * Every permission slug, flat: `sales.issue`, `products.adjust-stock`, …
     *
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        $slugs = [];

        foreach (self::CATALOGUE as $group => $actions) {
            foreach ($actions as $action) {
                $slugs[] = self::slug($group, $action);
            }
        }

        return $slugs;
    }

    public static function slug(string $group, string $action): string
    {
        return Str::slug($group).'.'.$action;
    }
}
