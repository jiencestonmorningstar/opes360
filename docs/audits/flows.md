# Flow audit — broken and missing flows

Read-only audit, 2026-08-17. No fixes applied. Scope: status state machines, the
approval (workflow) engine and its subjects, domain events, scheduled commands,
and recovery paths. All findings verified against source; file:line refs included.

---

## 2. Approvable subjects — seeding and verdict handling

Ten models use `App\Models\Concerns\Approvable`
(`app\Models\Concerns\Approvable.php`):

| Subject | Seeded by DefaultWorkflows | Offered on workflow screen (WorkflowSubjects::CATALOGUE) | workflow.approved listener |
|---|---|---|---|
| PurchaseRequisition | yes | yes | SyncApprovedRequisitions |
| ExpenseClaim | yes | yes | PostApprovedExpenseClaims |
| Contract | yes | yes | ActivateApprovedContracts |
| ComplianceFiling | yes | yes | CompleteApprovedComplianceFilings |
| ServiceJob | **yes** | yes | **NONE** |
| JobOffer | yes | **no — not in WorkflowSubjects::CATALOGUE** | MarkApprovedJobOffers |
| BusinessDocument | **no** | yes | TranslateDocumentWorkflowEvents |
| Expense | **no** | yes | **NONE** |
| Project | **no** | yes | **NONE** |
| InsuranceClaim | **no** | **no** | SettleApprovedInsuranceClaims |

Findings:

- **F2.1 — InsuranceClaim is doubly orphaned.** `DefaultWorkflows::catalogue()`
  (`app\Support\DefaultWorkflows.php:74`) does not seed a path for it, and
  `WorkflowSubjects::CATALOGUE` (`app\Support\WorkflowSubjects.php:33`) does not
  offer it on the workflow admin screen, so no business can ever create one.
  Yet `app\Services\Insurance\Claims.php:127` resolves
  `Workflow::defaultFor(InsuranceClaim::class)` and
  `WorkflowEngine::start()` on submit — which will always refuse ("no approval
  path is defined"). The listener `SettleApprovedInsuranceClaims` exists but can
  never fire. Claim settlement approval is a dead flow end-to-end.
- **F2.2 — ServiceJob is seeded a workflow but no listener consumes the
  verdict.** DefaultWorkflows seeds "Service visits" (line 112), the engine will
  run it, and nothing in `app\Listeners\` reacts to `workflow.approved` for a
  `ServiceJob`. The approval happens and the job's own status/billing flow never
  hears about it.
- **F2.3 — Expense and Project are offered on the workflow screen but have no
  listener.** An administrator can build a path for either
  (`WorkflowSubjects::CATALOGUE` includes both), submissions will be approved
  or rejected, and the verdict changes nothing on the record — no listener in
  `app\Listeners\` matches `Expense` or `Project`.
- **F2.4 — JobOffer is seeded but not editable.** It is absent from
  `WorkflowSubjects::CATALOGUE`, so the seeded "Job offers" path cannot be
  viewed, adjusted, or replaced on the workflow screen even though the class
  comment says seeded rows are "editable, deactivatable, and replaceable".
- **F2.5 — BusinessDocument has a listener and a screen entry but no seeded
  default.** A brand-new business that submits a document for approval gets the
  "no approval path is defined" refusal that DefaultWorkflows was written to
  prevent (see the file's own header comment, lines 19–29).

---

## 4. Domain events — emissions vs DomainEvents::CATALOGUE

Every `emitDomainEvent(...)` call site was compared against
`App\Support\DomainEvents::CATALOGUE` (`app\Support\DomainEvents.php`).
The catalogue is what the automation/notification rule screens offer, and
`RunAutomationRules` / `RunNotificationRules` match on exact name — an
uncatalogued event fires but no rule can ever be written against it.

**Uncatalogued emissions (20 distinct names):**

| Event | Emitted from |
|---|---|
| service.sla.breached | `app\Console\Commands\SweepServiceSlaBreaches.php:45`, `app\Services\Service\TicketDesk.php:124,230` |
| service.ticket.opened | `TicketDesk.php:68` |
| service.ticket.assigned | `TicketDesk.php:89` |
| service.ticket.reprioritised | `TicketDesk.php:197` |
| service.ticket.resolved | `TicketDesk.php:237` |
| service.ticket.closed | `TicketDesk.php:269` |
| service.ticket.reopened | `TicketDesk.php:306` |
| service.job.scheduled | `app\Services\Service\ServiceScheduling.php:58` |
| service.job.completed | `ServiceScheduling.php:111` |
| service.job.billed | `app\Services\Service\ServiceBilling.php:130` |
| rfq.opened | `app\Services\Procurement\Sourcing.php:80` |
| quotation.received | `Sourcing.php:201` |
| quotation.awarded | `Sourcing.php:295` |
| requisition.created | `app\Services\Procurement\Requisitions.php:80` |
| contract.raised | `app\Services\Contracts\ContractLifecycle.php:92` |
| contract.activated | `ContractLifecycle.php:149` |
| contract.renewed | `ContractLifecycle.php:225` |
| contract.terminated | `ContractLifecycle.php:258` |
| asset.transferred | `app\Services\Assets\AssetMovements.php:83` |
| asset.serviced | `app\Services\Assets\AssetServicing.php:86` |

Entire modules — **service**, **procurement** (rfq/quotation/requisition),
**contract**, **asset** — emit events with no catalogue section, so none of
their 20 events can be selected as a trigger on the rules screens, and
`DomainEvents::exists()` reports them as nonexistent.

Catalogued events that are emitted and match correctly: document.*, workflow.*
(engine announces via `WorkflowEngine::announce()`, line 312), expense.*,
sales.*, orders.*, compliance.filing.*, insurance.*, risk.*, logistics.*,
estate.*. Note `hr.*` events are catalogued — `hr.employee.created`,
`hr.employee.ended`, `hr.leave.requested`, `hr.leave.approved` — but **no
`emitDomainEvent` call anywhere emits any `hr.` event**: the HR section of the
catalogue is aspirational; a rule written against it never fires (the inverse
failure).

---

## 5. Scheduled commands vs existing commands

`routes\console.php` schedules 12 entries: opes:expire-leases, model:prune
(SyncReceipt), opes:convert-expired-demos, opes:remind-plan-renewals,
opes:alert-low-stock, notifications:digest, service:sla-sweep,
opes:remind-actions, opes:prune-audit, opes:expire-vip-memberships,
opes:generate-recurring-invoices, opes:send-dunning-reminders.

Commands in `app\Console\Commands\` never scheduled:

| Command | Verdict |
|---|---|
| Doctor, Install, ExportInstallSchema, ExportOpenApi, FindUnreachable, BackfillLedger, SeedDefaultWorkflows | Operator/one-off tools — correctly unscheduled |
| SearchReindex | One-off maintenance — acceptable, though a periodic reindex may be intended |

No time-dependent business flow relies on an unscheduled command. The one soft
spot: `SeedDefaultWorkflows` must be run manually (or on signup) for existing
companies — if company creation does not call `DefaultWorkflows::seed()`, older
tenants keep hitting the "no approval path" refusal (cross-ref F2.5).

---

## 1. Status state machines — dead ends and UI-less transitions

Every `STATUSES` const / `STATUS_*` const in `app\Models\` was enumerated and
its writers traced through `app\Services\` and `app\Livewire\`.

### Broken machines

- **F1.1 — Project: `active`, `on_hold`, `completed` are unreachable.**
  `Project::STATUSES` declares planning / active / on_hold / completed /
  cancelled (`app\Models\Project.php:30`). The only screen is
  `app\Livewire\Projects\Index.php` (no Show component exists): it creates
  projects as `'planning'` (line 55) and offers `cancel` (line 69). **No code
  anywhere writes `active`, `on_hold`, or `completed`** — a project can only
  ever be planning or cancelled. Three of five states are dead letters, and
  the status filter (line 84) offers filters that can never match.
- **F1.2 — ExpenseClaim: the entire machine is unreachable from any screen.**
  `ExpenseClaim::STATUSES` (draft/submitted/approved/rejected/reimbursed,
  `app\Models\ExpenseClaim.php:31`) is fully implemented in
  `app\Services\ExpenseClaimService.php` (start at line 130, postApproval),
  with a seeded default workflow and the `PostApprovedExpenseClaims` listener —
  but there is **no Livewire component, no controller, and no route** that
  references ExpenseClaim (`app\Livewire\`, `app\Http\`, `routes\` all empty of
  it). The whole claims flow is dark: nothing can be created, so nothing can be
  submitted, approved, or reimbursed. See also F3.1.
- **F1.3 — ComplianceFiling `rejected` is a dead end.**
  `ComplianceRegister::reject` sets `'rejected'`
  (`app\Services\Compliance\ComplianceRegister.php:148`) and no transition
  leaves it: `returnToPreparer` (line 162, back to `'draft'`) fires only on
  `workflow.changes_requested`, never on rejection. A refused statutory filing
  cannot be corrected and refiled — the obligation's filing record is stuck.
- **F1.4 — ServiceJob has no approval-driven states despite a seeded approval
  path.** `ServiceJob::STATUSES` (scheduled/in_progress/completed/cancelled,
  `app\Models\ServiceJob.php:31`) contains no pending/approved state, nothing
  submits a ServiceJob to the engine, and no listener consumes its verdict —
  yet `DefaultWorkflows` seeds "Service visits" for every company
  (`app\Support\DefaultWorkflows.php:112`). The seeded workflow row is
  unreachable machinery (cross-ref F2.2).

### Sound machines (spot-checked, transitions and UI both present)

- SalesOrder (draft→confirmed→picking→delivered→invoiced, cancel) —
  `app\Services\Orders\Fulfilment.php`.
- Shipment — `exception` explicitly recovers: retry back to `in_transit`
  (`app\Services\Logistics\Dispatch.php:304`) or `returned` (line 324).
- ServiceTicket — full loop including `reopened`
  (`app\Services\Service\TicketDesk.php:306`).
- SupplierStatementLine `disputed` — recoverable: disputed lines remain
  matchable (`app\Services\Payables\SupplierReconciler.php:290`) and the
  statement status recomputes (line 428).
- JobOffer — accept/decline/withdraw all guarded and reachable
  (`app\Services\Recruitment\JobOffers.php:138-178`).
- PurchaseRequisition `returned` — recoverable because `Requisitions::submit`
  only refuses awaiting/approved (`app\Services\Procurement\Requisitions.php:98-104`),
  so a returned requisition can be submitted again (but see F6.2: the old
  workflow instance is abandoned, not resubmitted).
- PerformanceReview draft→shared→acknowledged — wired in
  `app\Livewire\Hr\Reviews.php`.

---

## 3. Submit-able with no screen button (and buttons that always fail)

Engine entry points: `WorkflowEngine::start` is called from six services and
one automation runner (`app\Services\Automation\ActionRunner.php:64`).

| Subject | Submit service call | UI action |
|---|---|---|
| Contract | ContractLifecycle::submit | `app\Livewire\Contracts\Show.php:66`, `Index.php:147` — OK |
| ComplianceFiling | ComplianceRegister::submit | `app\Livewire\Compliance\Index.php:190` — OK |
| JobOffer | JobOffers::submit | `app\Livewire\Recruitment\Show.php:191` — OK |
| PurchaseRequisition | Requisitions::submit | `app\Livewire\Procurement\Requisitions.php:149` — OK |
| InsuranceClaim | Claims::submitSettlement | Button exists (`app\Livewire\Insurance\Show.php:303`) **but always throws** — no workflow for InsuranceClaim can exist (F2.1) |
| ExpenseClaim | ExpenseClaimService (start: line 130) | **No screen at all** (F1.2) |
| BusinessDocument | none | **No submit path** — Papers screens (`app\Livewire\Papers\`) contain no workflow/submit call; `TranslateDocumentWorkflowEvents` can only fire if an automation rule starts a workflow via ActionRunner |
| Expense | none | **No submit path** despite being offered on the workflow-builder screen |
| Project | none | **No submit path** despite being offered on the workflow-builder screen |
| ServiceJob | none | **No submit path** despite a seeded default workflow |

- **F3.1 — ExpenseClaim: complete service + workflow + listener, zero UI.**
- **F3.2 — InsuranceClaim settlement submit button is a guaranteed error.**
  `Claims::submitSettlement` resolves `Workflow::defaultFor(InsuranceClaim::class)`
  (`app\Services\Insurance\Claims.php:127`) which is always null (not seeded,
  not creatable on screen). Mitigation that exists: direct `settle`/`reject`
  buttons (`Insurance\Show.php:336,353`) work because the approval gate at
  `Claims.php:166` only engages when a default workflow exists.
- **F3.3 — Four subjects (BusinessDocument, Expense, Project, ServiceJob) are
  Approvable with no way for any user to submit them.** An administrator can
  spend an afternoon building a workflow for Expense or Project on the
  workflow screen (`WorkflowSubjects::CATALOGUE` offers both) and it will
  never once run.

---

## 6. Stalled / exception / returned states with no recovery path

- **F6.1 — `WorkflowInstance` status `stalled` has no recovery anywhere.**
  The engine sets it when a step has no resolvable approver
  (`app\Services\Workflow\WorkflowEngine.php:215`) and announces
  `workflow.stalled`. The designed recovery, `WorkflowEngine::resubmit()`
  (line 128, "Send a returned or stalled record back round"), has **zero
  callers** — not in any Livewire component, not in
  `app\Http\Controllers\Api\ApprovalController.php` (whose `act` endpoint only
  accepts approved/rejected/changes_requested, line 115), not in the Inbox
  (`app\Livewire\Workflow\Inbox.php`). A stalled approval is permanently
  stuck, while `Kpis::stalledApprovals` (`app\Support\Kpis.php:262`) counts it
  on the executive report forever (`app\Livewire\Reports\Executive.php:136`
  flags it `alarming`).
- **F6.2 — `changes_requested` instances are likewise never resubmitted.**
  Subjects with a forgiving `submit` (PurchaseRequisition) recover by starting
  a *new* instance, orphaning the old one in `changes_requested`; subjects
  whose listeners return them (ComplianceFiling → draft) do the same. The
  instance-level resubmit path is dead code, so instance history shows
  abandoned rows rather than a resubmitted trail.
- **F6.3 — ComplianceFiling `rejected` — no refile path** (detail in F1.3).
- Recoveries that do exist and are wired: Shipment `exception` (retry /
  return-to-sender, `Dispatch.php:304,324`); disputed supplier statement lines
  (`SupplierReconciler.php:290`); ServiceTicket reopen (`TicketDesk.php:306`).

---

## Summary counts

| Dimension | Count |
|---|---|
| Approvable subjects | 10 (6 seeded; 2 missing from workflow screen; 3 with no verdict listener) |
| Dead-end / unreachable states | 3 unreachable Project states; ComplianceFiling `rejected` dead end; entire ExpenseClaim machine dark; WorkflowInstance `stalled` + orphaned `changes_requested` |
| Submit-able with no button | 4 subjects (BusinessDocument, Expense, Project, ServiceJob) + 1 always-failing button (InsuranceClaim) + 1 screenless module (ExpenseClaim) |
| Uncatalogued emitted events | 20 (service ×10, procurement ×4, contract ×4, asset ×2) |
| Catalogued events never emitted | 4 (all of `hr.*`) |
| Scheduled commands | 12 scheduled; 8 unscheduled (all operator/one-off — no gap) |
| Unrecoverable stalled/exception states | 2 (workflow `stalled`, filing `rejected`); `resubmit()` is dead code |
