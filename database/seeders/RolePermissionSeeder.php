<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolePermissionSeeder extends Seeder
{
    /** group => [action, ...] — shared with the gate definitions, never forked. */
    protected array $permissions = Permissions::CATALOGUE;

    /**
     * The seven roles from Module 15. `*` grants everything in a group; an empty
     * array grants nothing.
     */
    protected array $roles = [
        'owner' => ['name' => 'Owner', 'level' => 1, 'grants' => '*'],
        'administrator' => ['name' => 'Administrator', 'level' => 2, 'grants' => '*'],
        'manager' => ['name' => 'Manager', 'level' => 3, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view', 'create', 'update', 'issue', 'approve'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record'],
            /*
             * A manager runs the business's operations. They do not keep its
             * books, and the two are separated here deliberately.
             *
             * No `pay`. A manager who can both enter a supplier bill and
             * settle it can invent a supplier and pay them, and nothing in
             * the system would show anything unusual — the bill would look
             * exactly like every other bill. Recording what was spent is
             * operations; releasing the money is the accountant's, and
             * approving it the owner's.
             */
            'Expenses' => ['view', 'create', 'update', 'claim-view', 'claim-create'],
            // Keeps the staff file and decides leave — the day-to-day of
            // managing people. Approving a month's payroll and posting it to
            // the books is not that job, so 'approve' and 'pay' stay above.
            'Employees' => ['view', 'create', 'update'],
            /*
             * No `run`, for the same reason as `expenses.pay` and it matters
             * more here: a manager already creates employees. Add the ability
             * to run the payroll and one person can put a person who does not
             * exist on the payroll and pay them every month. Running a month
             * is bookkeeping; the manager sees the result.
             */
            'Payroll' => ['view'],
            'Leave' => ['view', 'request', 'approve'],
            'Customers' => ['view', 'create', 'update'],
            'Deals' => ['view', 'create', 'update', 'delete'],
            'Products' => ['view', 'create', 'update', 'adjust-stock', 'manage-locations', 'track-view', 'track-manage', 'reserve'],
            // Signs equipment out to staff and books its servicing, without
            // being able to restate what it cost or how it depreciates.
            'Assets' => ['view', 'transfer', 'maintain'],
            'Banking' => ['view'],
            /*
             * Deciding which bills get paid this week is treasury, not
             * operations — it belongs with whoever keeps the books and knows
             * what cash is actually there. A manager sees that a bill is
             * outstanding through Expenses; they do not schedule its payment.
             */
            'Payables' => ['view'],
            // Runs the sourcing: raises requisitions, opens RFQs, invites
            // suppliers. Awarding commits the spend and stays above.
            'Procurement' => ['requisition-view', 'requisition-manage', 'rfq-view', 'rfq-manage'],
            // The org chart's other half — the posts, not the people in them.
            'Positions' => ['view', 'manage'],
            'Attendance' => ['view', 'record'],
            'Reviews' => ['view', 'manage'],
            // Runs the hiring day to day and sits on panels. Not `offer`:
            // an offer letter commits a salary, and committing the business
            // to a wage bill stays with the owner.
            'Recruitment' => ['view', 'manage', 'interview'],
            // Writes and renews agreements. Not `terminate`: ending a contract
            // early usually costs the business something, and that is a
            // decision the owner should be the one making.
            'Contracts' => ['view', 'manage', 'renew'],
            // Keeps the calendar — which licences and inspections are due is
            // an operational matter. Filing the return is not: it is the
            // accountant's signature on what the business has declared.
            'Compliance' => ['view', 'manage'],
            // Raises risks and records controls, but cannot mark a risk down —
            // see the note on `risks.review`.
            'Risks' => ['view', 'manage'],
            // Runs the desk day to day. Not `manage-sla`: what the business
            // has promised its customers is a commercial commitment, and a
            // manager under pressure must not be able to relax the target
            // they are being measured against.
            'Service' => ['view', 'create', 'update', 'assign', 'schedule', 'complete', 'bill'],
            // Runs the workshop: recipes, orders, and completing a run.
            'Manufacturing' => ['view', 'manage', 'complete'],
            // Runs projects day to day: creates them, sets the budget, adds
            // the team, and logs their own time on them.
            'Projects' => ['view', 'manage', 'log-time'],
            // Sends documents out on the company's behalf, which is the job.
            // Not 'manage': that is sight of every restricted document in the
            // business, and a manager who needs one can be given it by hand.
            'Papers' => ['view', 'create', 'issue', 'share'],
            'Forms' => ['view', 'create', 'update', 'delete', 'responses'],
            'Events' => ['view', 'create', 'update', 'void', 'check-in'],
            'Loyalty' => ['view', 'manage', 'redeem'],
            // Runs the programme: sets up tiers and can sign a customer up.
            'Vip' => ['view', 'manage', 'sell'],
            'Reports' => ['view', 'export'],
            // Reads the accounts and takes figures out of them. Not `manage`:
            // that is the chart itself and the journals behind it, and a
            // manager who can redraw where costs land can make a department's
            // overspend appear somewhere else.
            'Accounting' => ['view', 'export'],
            // Runs the counter in a secretariat: adds clients and prints their
            // stationery. Withdrawing the balance is not a counter job, so
            // 'withdraw' stays with the Owner and Administrator.
            'Partners' => ['view', 'manage', 'issue'],
            // Keeps the org chart, which is the same job as keeping the staff
            // file it labels.
            'Departments' => ['view', 'manage'],
            // Sees what the approval rules are without being able to rewrite
            // them — rewriting one is the same as authorising the spend.
            'Workflows' => ['view'],
            'Users' => ['view'],
            'Devices' => ['view'],
            'Settings' => ['view'],
        ]],
        'accountant' => ['name' => 'Accountant', 'level' => 4, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view', 'create', 'update', 'issue'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record', 'refund'],
            // The spending side is the accountant's before it is anyone's.
            'Expenses' => ['view', 'create', 'update', 'pay', 'void', 'claim-view', 'claim-create', 'claim-reimburse'],
            // Running the payroll is bookkeeping. Approving it commits the
            // business to a month's wages and the declarations that follow,
            // which is the owner's signature, not the accountant's.
            'Employees' => ['view', 'update'],
            'Payroll' => ['view', 'run', 'pay'],
            'Leave' => ['view'],
            'Customers' => ['view', 'create', 'update'],
            'Deals' => ['view'],
            'Products' => ['view'],
            // The asset register and the bank reconciliation are the
            // accountant's work before they are anybody's.
            'Assets' => ['view', 'create', 'update', 'depreciate', 'dispose', 'transfer', 'maintain'],
            'Banking' => ['view', 'manage', 'import', 'reconcile'],
            // Reconciling a supplier's statement against our books is the same
            // job as reconciling the bank. Building the payment run is too.
            // Approving and executing it are not — those are the owner's, for
            // the same reason approving the payroll is.
            'Payables' => ['view', 'manage', 'statement-view', 'reconcile'],
            'Procurement' => ['requisition-view', 'rfq-view'],
            // The statutory calendar is the accountant's work — the tax
            // returns and CNPS declarations on it are theirs to file.
            'Compliance' => ['view', 'manage', 'file'],
            'Contracts' => ['view'],
            'Risks' => ['view'],
            'Positions' => ['view'],
            // Reads attendance because absence deductions are entered onto the
            // payroll run by hand, and this is where the evidence for one is.
            'Attendance' => ['view'],
            // Costing a project against the books is the accountant's before
            // it is anyone's.
            'Projects' => ['view'],
            'Papers' => ['view', 'create'],
            'Forms' => ['view', 'responses'],
            'Events' => ['view'],
            'Loyalty' => ['view'],
            'Reports' => ['view', 'export'],
            // The books are the accountant's job before anyone else's.
            'Accounting' => ['view', 'export', 'manage'],
            // Reads the org chart — cost allocation and payroll reporting both
            // run on it — without being able to redraw it.
            'Departments' => ['view'],
            'Workflows' => ['view'],
            'Settings' => ['view'],
        ]],
        'sales-officer' => ['name' => 'Sales Officer', 'level' => 5, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view', 'create', 'update'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record'],
            'Customers' => ['view', 'create', 'update'],
            // Chasing deals is this role's actual job, so it gets the full set
            // here even though it cannot issue the invoice one turns into.
            'Deals' => ['view', 'create', 'update', 'delete'],
            'Products' => ['view', 'track-view'],
            // Anybody who spends their own money on the business's behalf needs
            // to be able to claim it back; approving it is the workflow's job.
            'Expenses' => ['claim-view', 'claim-create'],
            // Asking for something is not spending it. Anybody doing the job
            // can raise a requisition; the workflow decides whether it happens.
            'Procurement' => ['requisition-view', 'requisition-manage'],
            // A sales officer takes the call that becomes a ticket. Raising
            // one is the same act as taking the complaint.
            'Service' => ['view', 'create', 'update'],
            // Chasing a client's project is close enough to the deal it grew
            // out of that a sales officer needs to see it, and to log time
            // spent on it, without being able to move its budget.
            'Projects' => ['view', 'log-time'],
            'Papers' => ['view', 'create'],
            'Forms' => ['view', 'create', 'update', 'responses'],
            'Events' => ['view', 'create', 'update', 'check-in'],
            'Loyalty' => ['view', 'redeem'],
            'Reports' => ['view'],
            'Partners' => ['view', 'issue'],
        ]],
        // No Papers for a Cashier: a till operator has no reason to read the
        // business's employment letters and contracts. Read Only does get them,
        // because that role is for auditors and accountants looking in.
        // Events check-in for a Cashier: door staff scanning tickets at a paid
        // event is the same job as the till, just standing up.
        'cashier' => ['name' => 'Cashier', 'level' => 6, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record'],
            'Customers' => ['view', 'create'],
            'Products' => ['view'],
            'Events' => ['view', 'check-in'],
            'Loyalty' => ['view', 'redeem'],
        ]],
        // No Employees or Payroll for Read Only. "Can see everything" is a
        // reasonable description of an auditor's access right up until it
        // includes what every colleague earns; a business that wants that
        // grants it deliberately rather than getting it by default.
        'read-only' => ['name' => 'Read Only', 'level' => 7, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view'],
            'Receipts' => ['view'],
            'Payments' => ['view'],
            'Expenses' => ['view'],
            'Assets' => ['view'],
            'Banking' => ['view'],
            'Customers' => ['view'],
            'Deals' => ['view'],
            'Products' => ['view'],
            'Papers' => ['view'],
            'Forms' => ['view', 'responses'],
            'Events' => ['view'],
            'Loyalty' => ['view'],
            'Reports' => ['view'],
            'Departments' => ['view'],
            'Workflows' => ['view'],
            'Projects' => ['view'],
        ]],
    ];

    public function run(): void
    {
        $ids = [];

        foreach ($this->permissions as $group => $actions) {
            foreach ($actions as $action) {
                $slug = Permissions::slug($group, $action);

                $permission = Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => Str::headline($action).' '.$group, 'group' => $group],
                );

                $ids[$group][$action] = $permission->id;
            }
        }

        foreach ($this->roles as $slug => $definition) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'level' => $definition['level'],
                    'is_system' => true,
                ],
            );

            $grants = $definition['grants'] === '*'
                ? collect($ids)->flatten()->all()
                : collect($definition['grants'])
                    ->flatMap(fn (array $actions, string $group) => collect($actions)
                        ->map(fn (string $action) => $ids[$group][$action] ?? null))
                    ->filter()
                    ->all();

            $role->permissions()->sync($grants);
        }
    }
}
