# Workflows and approvals

One approval engine for the whole product. Documents, Procurement, Expenses
and HR ask this for an approval rather than each growing their own — four
approval engines is the failure mode the master brief names outright, and the
only reliable way to avoid it is for there to be somewhere obvious to go
instead.

A **platform service**, not a module. It is deliberately absent from
`config/modules.php`: a business that switches Procurement off must keep the
approval history of the purchase orders it already raised.

---

## The model, in one picture

```
Workflow            "Purchase order approval", applies to PurchaseOrder
  └── WorkflowStep  ordered; each has a type, an approver rule, a quorum,
                    and an optional condition
        │
        │  start(subject)
        ▼
WorkflowInstance    this PO, at step 2, running
  ├── WorkflowAssignment   one per person who must act on the current step
  └── WorkflowDecision     the permanent record of who did what, and when
```

**Assignments are current; decisions are forever.** An assignment is closed as
the instance advances. A decision is never modified and never deleted — the
model refuses both — because it is the audit trail, and because approval
history has to survive somebody editing the workflow afterwards.

---

## Making a model approvable

```php
use App\Models\Concerns\Approvable;

class PurchaseOrder extends Model
{
    use Approvable;
}
```

That is the whole integration. The trait gives you:

| Method | Answers |
|---|---|
| `approval()` | The current run, or the most recent finished one |
| `isAwaitingApproval()` | Is it with somebody right now? |
| `isApproved()` | Did it come back yes? |
| `workflowInstances()` | Every run, newest first |

If your model records who raised it in a column other than `created_by`,
override one method:

```php
    /** Expenses record who entered them as `recorded_by`. */
    protected function workflowCreatorColumn(): string
    {
        return 'recorded_by';
    }
```

Then submit it:

```php
$workflow = Workflow::defaultFor(PurchaseOrder::class);

app(WorkflowEngine::class)->start($purchaseOrder, $workflow, auth()->user());
```

A module never touches assignments, steps or quorums. That thinness is the
whole point of having one engine.

---

## Approver modes

Resolved **when the step is reached, never when it is written.** A workflow
that stored a user id is wrong the day that person leaves, and nobody finds
out until an invoice has sat unapproved for a week.

| Mode | Resolves to |
|---|---|
| `role` | Everyone holding `approver_role` in this company |
| `department` | The manager of `approver_department_id` |
| `user` | The named person |
| `owner` | The business owner |
| `manager` | The submitter's department manager, via their employee record |
| `creator` | Whoever raised the record |

Every candidate is then filtered to active members of the company. Somebody
whose membership is marked removed is not an approver, whatever the workflow
says.

### A step that resolves to nobody stalls

It does **not** pass. An approval that approves itself because its approver
left the company is worse than one that gets stuck, because the stuck one gets
noticed and fixed. The instance goes to `stalled`, keeps its position, and can
be resubmitted once somebody can actually fill the step.

---

## Quorum

One field rather than four flags. Single, multiple, sequential and parallel
approval all fall out of it.

| `quorum` | Meaning |
|---|---|
| `any` | The first approval closes the step |
| `all` | Everybody asked must approve |
| a number | That many approvals close it |

A number larger than the people available is capped at that many, so a quorum
of 3 with 2 approvers closes on 2 rather than stalling forever on an
arithmetic impossibility.

The same person cannot approve twice — their assignment is closed by their
first decision, and the engine refuses a second.

---

## Conditions

"Under ten million a manager signs; over it, the director" is the most
asked-for rule in the brief. It is expressed as data:

```php
'conditions' => [
    ['field' => 'total', 'operator' => '>=', 'value' => 10_000_000],
],
```

Operators: `>` `>=` `<` `<=` `=` `!=`. Every condition on a step must pass for
the step to apply; a step whose conditions do not match is **stepped over**,
not stalled on.

**There is deliberately no expression language, no callable and no eval.** A
condition is typed by an administrator into a form, and a condition typed into
a form must never be executable code.

Everything unrecognised **fails closed** — an unknown operator, a field the
subject does not have, a malformed row. Skipping the step is the failure that
gets reported ("why did this not need approval?"); silently inserting one
nobody expected is the failure that goes unnoticed.

---

## Outcomes

| Action | What happens |
|---|---|
| `approved` | Counts towards the step's quorum; the instance advances when met |
| `rejected` | **Stops the instance.** Terminal, with `completed_at` set |
| `changes_requested` | **Returns it to the submitter.** No `completed_at` |
| `delegated` | Hands the decision to somebody else |
| `cancelled` | Stops the instance; recorded like any other decision |

**"No" and "not yet" are different answers.** Rejection is terminal; asking for
changes is an invitation to fix something and resubmit. Collapsing them would
lose the difference between a rejected purchase and one that needs a receipt
attaching.

`resubmit()` sends a returned or stalled instance back round from the first
step. An approved, rejected or cancelled one cannot be resubmitted.

---

## Delegation

A handover, not an escape.

```php
app(WorkflowEngine::class)->delegate($instance, $manager, $deputy, 'On leave until Monday.');
```

The decision log keeps the delegation with `delegated_to`, and the new
assignment keeps `delegated_from`. Without both, "the manager approved it"
would be indistinguishable from "the manager passed it to a friend". The
original approver can no longer act once they have delegated.

---

## Permissions

`workflows.view` and `workflows.manage` govern **defining** an approval path,
not making one. `manage` stops at the Owner and the Administrator, because
whoever can edit a workflow can write themselves a path with no approver in
it — which is the same thing as being able to spend the money.

**Approving requires no permission at all.** Being asked *is* the permission.
Requiring a second one would mean an approver the engine had just assigned
could not act, and `/actions` is therefore ungated for signed-in users. The
engine performs the only check that matters: it refuses a decision from
anybody without a pending assignment on that instance.

---

## My actions

`/actions` lists everything waiting on the signed-in user, from every module,
oldest due date first, paginated. Every button re-enters the engine rather
than deciding anything itself — one check in one place beats the same check
written twice, slightly differently.

---

## What this does not touch

`document_approvals`, the sales-specific approval that already exists and
works, is untouched. Migrating it onto this engine is a later, separate
decision: two mechanisms briefly coexisting is far cheaper than rewriting a
working sales flow inside a foundational change.
