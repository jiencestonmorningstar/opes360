# Wave 1b handoff — workflow/state flow gaps

All items landed and tested (`--filter="Workflow|Insurance|Compliance|Logistics|Rfq|Risk|Offer|DefaultWorkflows"`: 319 passed; pint clean). What YOU must still do:

## Must run in production
- **`php artisan opes:seed-workflows`** after deploy. Existing businesses gain the two new default paths — "Claim settlements" (InsuranceClaim) and "Document approvals" (BusinessDocument). The command already tops up partially-seeded companies (it counts distinct subjects against `DefaultWorkflows::subjects()`); verified by `DefaultWorkflowsTest::test_the_backfill_tops_up_a_business_seeded_before_the_new_paths_existed`.

## Behaviour changes to communicate
- **Insurance**: once a company has the seeded "Claim settlements" path, the *direct* Settle button starts refusing ("go through an approval workflow") — `Claims::settle` gates on `Workflow::defaultFor(InsuranceClaim::class)`. That is the designed behaviour finally activating; businesses that want direct settlement can deactivate the seeded path on the approval-paths screen.
- **Papers**: drafts now have "Send for approval" (Papers Show, gated `update` on the paper). Issue is blocked only *while* an approval is running; approval is advisory, not mandatory, so nothing breaks for businesses that issue directly.
- **Removed from the workflow builder**: Expense, Project (no submit path, no listener — a path built for them could never run) and ServiceJob (also unseeded now; its status machine has no approval states and nothing consumes the verdict). Existing workflow rows for those subjects survive, stay editable (Edit screen accepts a workflow's own legacy subject), and simply can't be created anew. JobOffer and InsuranceClaim were added (fixes audit F2.4/F2.1).
- **Stalled approvals**: recovered from the Workflow Inbox ("Stuck approvals" panel) — submitter sees their own, `workflows.manage` sees all. There is no instance-admin screen in the product, and the Inbox is the submitter's action list, so it lives there. `changes_requested` instances are deliberately NOT resubmittable from the Inbox — those are recovered from the record's own module screen.
- **Compliance**: refused filings show under "Refused — needs correcting" with a send-back button (returnToPreparer now accepts submitted|rejected, no-ops on draft).

## Deferred / possible follow-ups
- ServiceJob billing approval: if the business case appears, it needs a pending status on the model, a submit in ServiceBilling, and a listener — removed rather than half-wired.
- Existing seeded "Service visits" workflow rows in old tenants are left in place (harmless, deactivatable); a cleanup migration was judged not worth the risk.
- Withdrawing a *pending* JobOffer leaves its workflow instance running (pre-existing; engine cancel could be wired into `JobOffers::withdraw`).
