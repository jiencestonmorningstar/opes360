# Platform audit — fix plan (2026-08-17)

Evidence: `docs/audits/{wiring,flows,bugs,docs,production}.md`. Status marks
updated as waves land. Money wave (discount-ledger P0, XAF notes, over-credit
race, API unit_price) already dispatched separately.

## Wave 1 — dark machinery gets its UI (flows audit)
- [ ] ExpenseClaim: full service exists, ZERO UI — build the claims screens
- [ ] FiscalPeriods close/reopen: four methods, no caller — settings screen
- [ ] ProjectTask/Milestone: whole models unreachable; Project stuck planning→cancelled — task board + status transitions
- [ ] Risk close/reopen + RiskControl failed; manifest close button; JobOffer withdraw; RFQ close/cancel + quotation shortlist/withdraw; orderDirect button
- [ ] WorkflowEngine::resubmit — stalled approvals permanently stuck
- [ ] InsuranceClaim: seed default workflow + add to WorkflowSubjects (settle button always throws today)
- [ ] ComplianceFiling rejected → returnToPreparer path on screen
- [ ] BusinessDocument: seed default workflow or remove submit affordance
- [ ] ServiceJob/Expense/Project workflow-builder entries: wire submits or drop the entries
- [ ] StockReservation sweepExpired scheduled?

## Wave 2 — events + wiring
- [ ] 20 emitted events into DomainEvents::CATALOGUE; 4 phantom hr.* resolved (emit or remove)
- [ ] Policies for the 42 module models without one (modules.php's own design contract)
- [ ] settings/api-tokens gets ability middleware (credential-issuing screen)
- [ ] Nav links: assets/fleet + 8 unreachable routed screens (audit.governance, payables.runs, reports.collections/statement, papers.analytics, settings.notification-rules/notifications)
- [ ] Audit\History orphan: embed or delete
- [x] modules.php manufacturing imports (committed)

## Wave 3 — production readiness (done — see docs/handoff/wave3.md)
- [x] CLAMAV_SOCKET via config() not env() (dead after config:cache) + .env.example keys (CONTACT_RECIPIENT, CSP_ENABLED, OPES_DEMO_LOGINS, CLAMAV_SOCKET…)
- [x] Branded 404/500/503/419 error pages (403 exists)
- [x] DeliverWebhook sync-driver inline-retry hazard
- [x] Kanbans unbounded (Deals/Leads); Dashboard double query + PHP low-stock filter
- [x] preventLazyLoading decision for prod (log, don't throw); SLA policy-edit recompute (self-declared gap)

## Wave 4 — API + docs
- [ ] API routes for service desk + 4 verticals + manufacturing; workflow-rule CRUD
- [ ] Guides for 15 module gaps (sales, customers, products, accounting, payroll, expenses, banking, forms, events, reports, partners…) + vertical guide gaps (rent reviews, rate cards)
- [ ] API.md +9 endpoints; GAP-ANALYSIS.md refresh; PRODUCTION-READINESS.md refresh; VPS deploy runbook (clamd, scheduler, queue, demo-logins off)
