# Documents — master specification

**Received:** 2026-08-16, from the product owner.
**Status:** authoritative brief. Supersedes the feature list in
`2026-08-15-documents-module-audit.md`; that document's *capability map and
blockers* remain valid and are what this one should be read against.

This file records the brief as given. What of it exists today, partly exists, or
does not exist at all is in `docs/GAP-ANALYSIS.md` — deliberately a separate
document, so the brief stays the brief and the gap analysis can go stale
without rewriting it.

---

## 0. The non-negotiable rule

> OPES360 Documents is an enterprise document workspace and document-management
> layer. It is **not** a replacement for Sales, Proforma, Quotations, Receipts,
> Invoices, Contacts, Customers, Suppliers, or any other existing transactional
> module.

Before writing, modifying, migrating or creating **any** code, table, route,
API, component, service, model, permission, workflow, storage mechanism or
business rule: inspect the existing suite and schema first, and produce a
capability map classifying every required feature as

- **A** — already exists, reuse it
- **B** — partly exists, extend it
- **C** — does not exist, build it

**Never create a second implementation where an existing one can be extended.**
If invoice generation exists, do not write another. If PDF generation exists, do
not add a second engine. If uploads, users, roles, contacts, customers or
suppliers exist, reference them.

### The transaction/document rule

| Thing | Owned by |
|---|---|
| The invoice record | Finance / Sales |
| The invoice PDF | The existing invoice generator |
| The supporting purchase contract | Documents |
| The customer record | CRM |
| The customer agreement | Documents |
| The employee record | HR |
| The employment contract | Documents |

The authoritative business record always stays with its originating module.
Documents may attach, reference, preview, export, archive, share, associate,
generate supporting material for, and store supplementary files against it.

### The Document Context Layer

Rather than every module building its own document integration, a context layer
sits between the business modules and Documents, and knows: which ERP record a
document belongs to, who owns it, what permissions apply, what dynamic data may
be inserted, what workflow applies, what documents are related, and which
existing services can generate, preview or export it. This is what keeps
Documents from coupling itself to every module in the suite.

### Platform services, not business modules

An architectural correction to the earlier specification: **Documents,
Workflow, Notifications, Audit, Search, Automation, Files/Storage, Reporting
and AI are platform services.** Business modules (CRM, Sales, Procurement,
Inventory, Finance, HR, Payroll, Projects, Assets, Manufacturing, Service)
consume them.

Procurement does not build an approval engine — it asks the workflow engine to
run an approval. HR does not build a document repository — it uses Documents.
Finance does not build a notifier — it uses Notifications. One engine per
concern, with modules registering into it.

---

## 1–2. Purpose

A universal enterprise content and document-management layer providing:
creation, rich editing, storage, organisation, templates, collaboration,
comments, review, approval, versioning, comparison, sharing, permissions,
workflows, signatures, audit trails, search, metadata, tagging, relationships,
attachments, preview, PDF/DOCX export, printing, lifecycle management,
retention, archival, restoration, automation, ERP data binding, ERP record
association, AI-assisted functions, external sharing, secure links, activity
history, notifications, mobile access, and offline-aware handling where the
existing architecture supports it.

It should feel like Google Docs + Word + Drive + an enterprise DMS + a workflow
system + ERP document automation — while remaining natively part of OPES360.

## 3. Dashboard

**Overview counters:** Total, Mine, Shared with me, Drafts, Awaiting review,
Awaiting approval, Awaiting signature, Recently modified, Recently created,
Expiring, Archived.

**Quick actions:** New document · Upload · New from template · Import · Create
folder · Shared · Templates · My actions.

**Recent documents columns:** Name, Type, Owner, Department, Status, Related
record, Last modified, Version, Security level.

**My actions:** documents needing review, approval or signature; documents with
requested changes; comments mentioning the user; the user's expiring documents.

## 4. Document types

Configurable. General (document, letter, memo, report, policy, procedure,
minutes, proposal, presentation notes, internal/external communication); Legal
(contract, agreement, amendment, NDA, legal notice, resolution, declaration);
HR (employment contract, offer, warning, promotion, termination, performance
review, training, certificate); Procurement (supplier agreement, procurement
report, evaluation, purchase documentation, tender); Finance (financial report,
management report, audit document, budget, payment supporting document);
Project (charter, requirements, specification, minutes, progress report,
acceptance).

**No duplicate ERP transaction types.** Invoices stay invoices; proformas stay
proformas; quotations stay quotations; receipts stay receipts.

## 5. Creation

Blank · from template · from an existing document · duplicate · import DOCX ·
import PDF · upload · from an ERP record · from a workflow · from automation.

Captured at creation: title, description, type, department, owner, author,
tags, security level, folder, related ERP records, retention policy, workflow,
template, language, and a document number where the type requires one.

## 6. Rich editor

Text (bold, italic, underline, strikethrough, highlight, font, size, colour,
super/subscript) · paragraph (H1–H3, paragraph, quote, code, alignment,
indentation, line spacing) · lists (bullet, numbered, nested, checklist) ·
insert (tables, images, links, attachments, page breaks, rules, headers,
footers, page numbers, bookmarks, table of contents, dates, dynamic ERP fields,
signature fields, approval fields, comments) · advanced (find, replace, undo,
redo, copy, paste, shortcuts, word/character count, statistics). Autosave
required.

## 7. ERP data binding

A reusable, extensible dynamic-field registry — **not hard-coded fields**.
Modules expose their own authorised fields: Finance exposes financial fields, HR
employee fields, CRM customer fields, Procurement supplier fields, Projects
project fields. Documents consumes them and never copies the source data.

`{{company.name}}` `{{customer.address}}` `{{employee.position}}`
`{{project.code}}` `{{quotation.number}}` `{{invoice.total}}` …

## 8. Templates

Create, edit, duplicate, publish, unpublish, archive, version, permission,
assign to department and to document type, set default, set variables, preview,
test, and create a document from. Categories: HR, Finance, Sales, Procurement,
Legal, Operations, Management, Projects, Administration, General. Templates
support dynamic fields, conditional sections, repeating data, tables, images,
branding, logos, headers, footers, signature and approval blocks, and QR
verification fields where existing functionality supports them.

## 9. Relationships

To customer, supplier, contact, employee, project, quotation, proforma,
invoice, receipt, payment, purchase order, product, organisation, department.
One-to-one, one-to-many and many-to-many as appropriate, using existing IDs.

## 10–11. Folders and tags

Personal, department, organisation, shared, project, customer, supplier and
custom folders; nested; create, rename, move, copy, delete, restore, archive,
sort, filter, star, pin. Custom, system, department, project, confidentiality
and lifecycle tags, with administrator control of the vocabulary where needed.

## 12–13. Versions and comparison

Every editable document versioned: number, author, timestamp, change summary,
editor, status. View, restore, compare, download, mark approved, mark final.
**Never destroy history** unless a retention policy explicitly permits it.
Visual comparison showing added, removed and modified content plus metadata
changes, between any two versions.

## 14–16. Collaboration, comments, track changes

Concurrent editing with presence, cursor identification, autosave and conflict
handling where technically supported. Comments on the document, a paragraph, a
selection, a table, an image or a section — create, reply, mention, edit,
resolve, reopen, delete by permission, with notification on mention. Track
changes with per-user attribution and accept/reject individually or wholesale.

## 17–18. Workflow and approvals

Configurable stages — Draft → Review → Approval → Signature → Final → Archive —
with submit, review, request changes, approve, reject, delegate, reassign,
sign, publish and archive actions. Approvals: single, multiple, sequential,
parallel, conditional, role-based, department and amount-based. **Extend any
existing approval engine rather than duplicating it.**

## 19. Signatures

Signature, initial, date, name and title fields; signature order; sequential
and parallel signing; reminders; status; completed document. Store signer,
timestamp, document version, status and audit information. Integrate with any
existing e-signature service; never build competing identity infrastructure.

## 20–21. Security and sharing

Permissions: view, edit, comment, download, print, share, approve, sign,
delete, archive, manage. Scopes: owner, user, role, department, organisation,
project, customer-facing, supplier-facing, external. Confidentiality: public,
internal, confidential, restricted, highly confidential.

**All authorisation server-side. UI visibility is never the control.**

External sharing by secure link with expiry, password, view-only, download and
print restriction where enforceable, revocation and access logging. Private
storage URLs are never exposed.

## 22–24. Preview, import, export

Preview PDF, images, DOCX where supported, TXT and other supported formats
without forcing a download. Import DOCX, PDF, images and TXT — imported files
become managed Documents records and **the original is preserved, never
destroyed by conversion**. Export PDF, DOCX, print and original-format
download, reusing existing export engines.

## 25. Numbering

Not every document needs a number. Where a type does, numbering is
configurable (`DOC-2026-000001`, `CONTRACT-2026-000001`, `HR-2026-000001`).
**Existing transaction numbering stays owned by its ERP module** and must not
be disturbed.

## 26–27. Search and OCR

Search filename, title, content, number, author, owner, department, tags, type,
related customer/supplier/employee/project, status, date, version, workflow and
security level, with matching filters, using existing search infrastructure
where available. OCR for scanned documents extracting text, dates, names,
numbers, amounts, organisations and addresses into the search index, without
overwriting the original scan.

## 28–29. Metadata and integrity

Id, UUID, title, description, type, number, version, status, owner, author,
department, organisation, folder, tags, classification, created/modified/
published/expiry/retention dates, file type, size, MIME, storage reference,
checksum. Finalised documents carry a cryptographic hash; verification
**integrates with the existing QR/verification infrastructure** rather than
adding a second one.

## 30–32. Audit, retention, lifecycle

Audit created, viewed (where policy requires), edited, commented, shared,
downloaded, printed, approved, rejected, signed, published, archived, restored,
deleted, permission-changed, version-created and workflow-changed — each with
user, action, timestamp, document, version, device information where policy
permits, and metadata. **Audit history is never silently altered.**

Retention policies and periods, archive status, legal hold (which blocks
deletion while active), expiration and controlled disposal. Nothing is
permanently deleted without a configured policy and an authorised process.

Lifecycle: Draft → In review → Changes requested → Approved → Pending signature
→ Signed → Published → Active → Superseded → Archived → On hold → Expired →
Disposed. Types may define their own states.

## 33–35. Alerts, my actions, activity

Notifications for review/approval/signature requests, approval, rejection,
requested changes, mentions, sharing, expiry, retention events and workflow
failures — **through the existing notification infrastructure**. A unified
action centre. A per-document activity timeline built on existing activity
infrastructure.

## 36–40. Related content, dossiers, packages, bundles, checklists

Related documents shown on every document. A **dossier** — a document view of
an existing customer/supplier/employee record, not a copy of it. **Packages**
grouping documents with their own owner, status, permissions, version, export,
sharing, approval and signature. **Bundles** generating a ZIP or combined PDF
without modifying the sources. **Checklists** of required documents attached to
an existing record (e.g. supplier onboarding).

## 41–45. AI, translation, language, branding

AI assistant (summarise, rewrite, grammar, translate, extract, identify dates/
obligations/risks, compare, outline, generate from ERP data, answer questions,
action items, tables, entities) that **respects document permissions and never
exposes what the user cannot access**. Natural-language search over authorised
documents only. Translation workflows preserving the original. Language
metadata and multilingual templates. Branding inherited from the existing
company configuration.

## 46. Administration

Types, templates, fields, workflows, approval rules, permissions,
classifications, retention policies, numbering rules, tags, storage, export,
signature, sharing, AI, OCR and notification settings.

## 47–49. API, database, storage

Clean APIs following **existing conventions**, not a parallel API style. Before
migrating: inspect the schema; never duplicate users, organisations, companies,
contacts, customers, suppliers, employees, products, invoices, quotations,
proformas, receipts, payments, projects, departments, roles, permissions, audit
logs, notifications, files or storage; only create a table after confirming no
equivalent exists; follow existing naming. Use the existing storage
abstraction; keep binaries out of relational tables; maintain secure reference,
MIME, size, checksum, version, ownership and access policy. **Private documents
stay private.**

## 50–52. Offline, mobile, desktop

Integrate with the existing offline/PWA architecture — **no second
synchronisation engine.** Offline viewing of synchronised documents, draft
editing, metadata updates, queued uploads and actions, with explicit conflict
resolution that never silently overwrites a newer version. Mobile must be a
real mobile interface, not a compressed desktop one, including camera/scanning
where supported. Desktop: navigation + workspace + contextual panel.

## 53. Document view

Header (title, number, status, version, classification); actions (edit, share,
download, export, comment, review, approve, sign, more); main preview/editor;
side panel (details, related records, comments, versions, activity,
permissions, workflow, attachments).

## 54–59. Automation, integration, extensibility, events

Documents integrates with suite automation (employee created → onboarding
documents; contract created → document; document expires → notify owner;
approval completed → advance; signature completed → finalise). It functions as
a cross-module service across CRM, Sales, Customers, Contacts, Suppliers,
Procurement, Inventory, Finance, Accounting, Invoices, Proforma, Quotations,
Receipts, Payments, HR, Payroll, Projects, Reports, Notifications, Audit,
Auth, QR/verification, AI, Search and PWA.

Modules register document types, dynamic fields, templates, relationships,
workflow triggers, actions, context menus and automation events. Documents
emits `document.created`, `.updated`, `.version.created`, `.submitted`,
`.review.requested`, `.changes.requested`, `.approved`, `.rejected`,
`.signature.requested`, `.signed`, `.published`, `.archived`, `.expired`,
`.shared`.

**Explicitly forbidden:** a "Documents Invoice", "Documents Quotation",
"Documents Receipt", "Documents Proforma", "Documents Customer", "Documents
Contact" or "Documents Supplier".

## 60–63. Performance, security, backup, retention

Pagination, lazy loading, indexed search, efficient metadata queries,
background processing for large files, queued conversions/OCR/previews,
efficient version storage, secure caching. **Never load an entire library into
the browser.**

Authentication, authorisation, role-based and record-level permissions, secure
file access, signed URLs, CSRF, XSS, upload/MIME/extension validation, malware
scanning where available, encryption at rest and in transit, audit logging,
rate limiting, access expiration, secure external sharing. Backup uses the
existing strategy — files, metadata, versions, relationships and audit records
all recoverable. Retention is configurable per type; **legal periods are never
hard-coded**.

## 64–68. Status, UX, ERP access, context, bulk

Status configurable per type. A premium, uncluttered UX with clear hierarchy
and obvious primary actions. Every appropriate ERP entity exposes a Documents
tab in place — the user is never moved into a disconnected document
ecosystem. Opening Documents from a record carries that context, so a document
started from a customer already knows its customer. Bulk move, tag, archive,
share, download, export, assign and workflow submission, with permissions
applied per record.

## 69–73. Duplicates, locking, finalisation, watermarks, printing

Detect same filename, checksum, content and probable duplicate versions —
**warn, never auto-delete.** Locking: unlocked, locked for editing, locked for
approval, locked/final; editing a finalised document creates a controlled
revision instead of a silent edit. On finalisation: freeze the version,
generate the artefact, store the hash, record the event, user and timestamp,
prevent unauthorised modification, allow controlled superseding. Configurable
watermarks (draft, confidential, internal use only, copy, sample). Print
control governed by permission.

## 74–75. Analytics

Documents created/modified/archived, approval and review times, signature
completion time, most-used templates, most active departments, expiring
documents, storage usage, types, workflow bottlenecks; and an admin dashboard
over the same.

## 76–80. Module API, testing, regression, migration safety, UI consistency

Modules can create, attach, retrieve, reference, request approval and signature
for, generate and subscribe to documents through the existing API
architecture. Test units, features, integration, permissions, uploads,
downloads, versions, workflow, approvals, signatures, search, relationships,
API, mobile, offline sync, security and performance — **and re-test existing
ERP functionality after integration.**

Documents must not break proformas, quotations, receipts, invoices, payments,
contacts, customers, suppliers, sales, procurement, inventory, HR, projects,
reports, PDFs, document generation, QR verification, APIs or the PWA. Never
rename or drop an existing table to fit Documents; never delete existing data;
keep migrations backward-compatible. Reuse the existing design system entirely.

## 81. Implementation order

Audit → map → identify reusable services → design integration → schema
extensions only where necessary → core → editor → templates → relationships →
collaboration → workflows → approvals → signatures → search → security → AI →
mobile/PWA → full regression → optimise → deploy.

## 82. Required output before coding

Existing-system audit · Documents-related capabilities · duplicate/conflict
analysis · reusable services · required new components · required schema
changes · integration map · API integration map · permission model · workflow
model · UI architecture · migration plan · testing plan.

---

# The ERP completeness checklist

The same brief sets out what a serious business operating system needs. Recorded
here because Documents is only one item on it (#22) and the priorities matter.

## Tier 1 — mandatory

1. **Accounting & general ledger** — chart of accounts, journals, GL, trial
   balance, P&L, balance sheet, cash flow, fiscal periods, cost centres,
   multi-company, multi-currency, tax/VAT, period closing
2. **Banking & treasury** — bank and cash accounts, reconciliation, cash
   management, transfers, payment matching, cash forecasting
3. **Accounts receivable** — balances, aging, allocation, credit and debit
   notes, collections, statements, overdue management
4. **Accounts payable** — supplier bills, aging, payment scheduling,
   statements, allocation, reconciliation
5. **Procurement** — requisitions, RFQ, supplier quotations, purchase orders,
   goods receipt, three-way matching, purchase invoices, approval workflows
6. **Inventory / warehouse** — multi-warehouse, movements, transfers,
   adjustments, counts, batch/lot, serials, expiry, reorder levels, valuation,
   reservations
7. **Sales & CRM** — leads, opportunities, pipeline, activities, communication,
   forecasting, history
8. **Expenses** — employee expenses, claims, receipts, approval,
   reimbursement, categories, cost-centre allocation
9. **HR** — employees, departments, positions, contracts, leave, attendance,
   performance, recruitment, employee documents
10. **Payroll** — runs, salary structures, allowances, deductions, taxes,
    social contributions, payslips, accounting integration
11. **Projects** — projects, tasks, milestones, budgets, expenses, timesheets,
    profitability, resource allocation
12. **Fixed assets** — register, acquisition, depreciation, transfers,
    maintenance, disposal, locations, lifecycle

## Tier 2 — substantially more complete

13. Manufacturing (BOM, work orders, planning, consumption, finished goods,
    work centres, costing, MRP)
14. Supply chain (demand planning, replenishment, supplier performance,
    procurement planning, logistics, delivery)
15. Logistics / fleet (vehicles, drivers, trips, fuel, maintenance, mileage,
    delivery tracking, transport costs)
16. Service management (requests, tickets, SLA, assignment, field service,
    work orders, service history)
17. **Contracts management** — connected to Documents, not a second document
    system: lifecycle, renewals, obligations, counterparties, amendments,
    approval, signature, expiry alerts
18. Compliance & risk (requirements, risk register, controls, policies,
    evidence, incidents, corrective actions, audit findings)
19. BI & analytics (executive dashboard, financial/sales/inventory/procurement/
    HR KPIs, profitability, cash-flow forecasting, custom reports, drill-down)
20. **Workflow & automation engine** — trigger → condition → action, rather
    than a workflow hard-coded per module
21. Notifications & communications (in-app, email, SMS, WhatsApp, templates,
    rules, escalations)
22. Enterprise Documents (the module above)
23. E-signature (inside Documents rather than a top-level module)
24. Audit & governance (activity history, login audit, record changes,
    approval history, document audit, permission changes, exports,
    administrative actions)
25. Administration (companies, branches, departments, users, roles,
    permissions, numbering, currencies, taxes, fiscal periods, approval rules,
    workflow configuration)

## The three the brief is most concerned about

1. **A proper accounting engine.** Being able to raise an invoice does not make
   a system financially complete; double-entry, GL, AR/AP, reconciliation and
   statements do.
2. **One workflow/automation engine** underneath Documents, Procurement, HR,
   Finance and Sales — not four unrelated ones.
3. **The Documents platform**, because connecting documents to ERP records and
   workflows without taking ownership from those modules is the differentiator.
