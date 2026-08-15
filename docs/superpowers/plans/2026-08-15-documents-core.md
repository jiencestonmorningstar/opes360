# Documents — Core & ERP Relationships Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the existing Papers module into a real document-management layer: any file or composed document, filed in folders, tagged, classified, owned, and — the point of the whole thing — attached to the customer, supplier, employee or invoice it belongs to.

**Architecture:** Extends `business_documents` rather than creating a `documents` table, which is the ERP's. Files go through the existing polymorphic `media` table. ERP links go through one polymorphic `business_document_relations` table so no ERP record is ever duplicated.

**Tech Stack:** Laravel 12, Livewire 3, Tailwind v4, Pest/PHPUnit. No new Composer packages.

**Audit:** `docs/superpowers/specs/2026-08-15-documents-module-audit.md`

---

## Decisions taken

These were the five open questions in the audit. Defaults applied, each stated so they can be overturned cheaply:

| # | Decision | Why |
|---|---|---|
| 1 | Root is **`business_documents`**; children are `business_document_*` | `documents` is the ERP's, 67 live rows |
| 2 | **Departments and projects are cut**, not built | Neither table exists; building HR org structure is not a Documents job |
| 3 | **Locking, not realtime co-editing** | No websocket tier; wrong trade for an offline-first PWA |
| 4 | **Print-to-PDF stays**; no server-side engine, no DOCX | Adding one is its own project |
| 5 | Phase 1 **Core + ERP relations together** | A DMS with no ERP links has no reason to exist |

**Nothing in this plan alters `documents`, `document_lines`, `media` or `activity_log`.** The six existing Papers test files must pass untouched at every step.

---

## File structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_24_000001_extend_business_documents.php` | Metadata columns |
| `database/migrations/2026_08_24_000002_create_business_document_folders.php` | Nested folders |
| `database/migrations/2026_08_24_000003_create_business_document_relations.php` | Polymorphic ERP links |
| `app/Support/DocumentKinds.php` | The document-type catalogue |
| `app/Models/BusinessDocumentFolder.php` | Folder tree |
| `app/Models/BusinessDocumentRelation.php` | One ERP link |
| `app/Services/Documents/DocumentFiler.php` | Upload, file, move, tag |
| `app/Services/Documents/DocumentLinker.php` | Attach/detach ERP records |
| `app/Livewire/Papers/Index.php` | Grows into the workspace |
| `resources/views/components/documents/panel.blade.php` | The Documents tab on any ERP record |

---

### Task 1: Metadata on the document record

**Files:**
- Create: `database/migrations/2026_08_24_000001_extend_business_documents.php`
- Modify: `app/Models/BusinessDocument.php`
- Create: `app/Support/DocumentKinds.php`
- Test: `tests/Feature/Documents/DocumentMetadataTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocument;
use App\Support\DocumentKinds;
use Tests\Feature\Documents\DocumentsTestCase;

class DocumentMetadataTest extends DocumentsTestCase
{
    public function test_a_document_carries_its_classification(): void
    {
        $doc = $this->document(['kind' => 'contract', 'security' => 'confidential']);

        $this->assertSame('contract', $doc->kind);
        $this->assertSame('Contract', $doc->kindLabel());
        $this->assertTrue($doc->isConfidential());
    }

    public function test_an_unknown_kind_falls_back_rather_than_breaking_a_list(): void
    {
        $doc = $this->document(['kind' => 'not-a-kind']);

        $this->assertSame('Document', $doc->kindLabel());
    }

    public function test_security_defaults_to_internal(): void
    {
        $this->assertSame('internal', $this->document()->security);
    }

    public function test_tags_round_trip(): void
    {
        $doc = $this->document(['tags' => ['contract', '2026']]);

        $this->assertSame(['contract', '2026'], $doc->fresh()->tags);
    }

    public function test_an_expiring_document_can_be_found(): void
    {
        $this->document(['expires_on' => now()->addDays(10)->toDateString()]);
        $this->document(['expires_on' => now()->addYear()->toDateString()]);
        $this->document();

        $this->assertSame(1, BusinessDocument::expiringWithin(30)->count());
    }

    public function test_an_already_expired_document_is_not_reported_as_expiring(): void
    {
        $this->document(['expires_on' => now()->subDay()->toDateString()]);

        $this->assertSame(0, BusinessDocument::expiringWithin(30)->count());
        $this->assertSame(1, BusinessDocument::expired()->count());
    }

    public function test_every_catalogued_kind_has_a_label_and_a_group(): void
    {
        foreach (DocumentKinds::all() as $key => $kind) {
            $this->assertArrayHasKey('label', $kind, $key);
            $this->assertArrayHasKey('group', $kind, $key);
        }
    }

    /** Existing rows predate every one of these columns. */
    public function test_a_row_with_no_metadata_still_works(): void
    {
        $doc = $this->document();
        $doc->forceFill(['kind' => null, 'security' => null, 'tags' => null])->save();

        $this->assertSame('Document', $doc->fresh()->kindLabel());
        $this->assertFalse($doc->fresh()->isConfidential());
        $this->assertSame([], $doc->fresh()->tags ?? []);
    }
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `php artisan test --filter=DocumentMetadataTest`
Expected: FAIL, `Class "App\Support\DocumentKinds" not found`

- [ ] **Step 3: The migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            // Every column nullable. This table has live rows composed from
            // templates before any of this existed, and they must keep working
            // untouched — the Papers screens have six test files proving it.
            $table->string('kind')->nullable()->after('template');
            $table->text('description')->nullable()->after('title');
            $table->string('security')->nullable()->after('status');
            $table->string('language', 8)->nullable()->after('security');
            $table->json('tags')->nullable()->after('language');
            $table->foreignId('owner_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->date('expires_on')->nullable()->after('issued_at');

            $table->index(['company_id', 'kind']);
            $table->index(['company_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['kind', 'description', 'security', 'language', 'tags', 'expires_on']);
        });
    }
};
```

- [ ] **Step 4: `app/Support/DocumentKinds.php`**

A catalogue keyed by slug, each with `label` and `group`. Groups: General, Legal,
HR, Procurement, Finance, Operations. **No ERP transaction types** — no invoice,
quotation, proforma or receipt kinds. Those records belong to the sales module
and a Documents copy of them is exactly what the brief forbids.

Include at minimum: `document, letter, memo, report, policy, procedure, minutes,
proposal, contract, agreement, amendment, nda, legal_notice, resolution,
employment_contract, offer_letter, warning_letter, performance_review,
certificate, supplier_agreement, tender, evaluation, financial_report,
audit_document, budget, specification, meeting_minutes`.

Provide `all(): array`, `label(?string $key): string` returning `'Document'` for
null or unknown, and `groups(): array`.

- [ ] **Step 5: Extend the model**

Add to `$casts`: `'tags' => 'array'`, `'expires_on' => 'date'`. Add
`kindLabel()`, `isConfidential()` (true for `confidential` and `restricted`),
`owner()`, and scopes `expiringWithin(int $days)` (between today and today+days,
excluding null) and `expired()`.

- [ ] **Step 6: Run — expect pass, then run the Papers suite**

```bash
php artisan test --filter=DocumentMetadataTest
php artisan test --filter="DocumentGenerator|FillPreview|LetterheadDesigns|NewBusinessDocumentTemplates|PrintFidelity"
```

Both must be green. The second is the regression gate.

- [ ] **Step 7: Commit**

---

### Task 2: Folders

**Files:**
- Create: migration `..._create_business_document_folders.php`, `app/Models/BusinessDocumentFolder.php`
- Test: `tests/Feature/Documents/DocumentFolderTest.php`

Columns: `id`, `company_id`, `parent_id` (self, nullOnDelete), `name`,
`kind` (`personal|company|entity`), `owner_id`, `is_pinned`, `sort_order`,
timestamps, soft deletes. Add `folder_id` to `business_documents`.

Tests must cover: nesting; a folder's `path()` reading `Sales / Contracts / 2026`;
deleting a folder does **not** delete its documents (they fall to the root — a
folder is an arrangement, not a container, and losing documents to a mis-click is
unrecoverable); a cycle is refused (`A` cannot be moved inside its own
descendant); depth is capped at 5; tenancy isolation.

---

### Task 3: Uploading a file as a document

**Files:**
- Create: `app/Services/Documents/DocumentFiler.php`
- Test: `tests/Feature/Documents/DocumentUploadTest.php`

Uses the existing `media` table via the polymorphic `attachable` — no new file
table, no second upload path.

Tests: an upload creates one `BusinessDocument` and one `Media` row; the
checksum is stored; mime and extension are validated against an allow-list;
an oversized file is refused with a usable message; a duplicate checksum in the
same company is **flagged, not blocked** (§69 — warn, never auto-delete); the
original file is never modified.

---

### Task 4: ERP relationships — the reason this module exists

**Files:**
- Create: migration `..._create_business_document_relations.php`, `app/Models/BusinessDocumentRelation.php`, `app/Services/Documents/DocumentLinker.php`
- Test: `tests/Feature/Documents/DocumentRelationTest.php`

```php
Schema::create('business_document_relations', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
    $table->foreignUlid('business_document_id')->constrained()->cascadeOnDelete();

    // Polymorphic, so a document attaches to a contact, an invoice, an
    // employee or a purchase order without this module knowing anything
    // about any of them — and without copying a single ERP row.
    $table->string('related_type');
    $table->string('related_id');

    // What the link means: 'about', 'supporting', 'signed-by', 'supersedes'.
    $table->string('role')->default('about');
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->unique(['business_document_id', 'related_type', 'related_id', 'role']);
    $table->index(['company_id', 'related_type', 'related_id']);
});
```

Tests: attach to a `Contact`, a `Document` (invoice) and an `Employee`; the same
link twice is idempotent rather than duplicated; detaching leaves the ERP record
untouched; **deleting the ERP record does not delete the document** (a contract
outlives the customer row — this is the test that proves Documents is not a
child table); querying "documents for this customer" returns only that
customer's, only in that company; a document can carry many relations.

---

### Task 5: Permissions

**Files:** modify `app/Support/Permissions.php`, `database/seeders/RolePermissionSeeder.php`, `app/Policies/BusinessDocumentPolicy.php`

Extend the existing `Papers` group rather than inventing a second one:
`['view', 'create', 'issue', 'void', 'share', 'manage']`.

Policy gains `share` and `manage`. Confidentiality is enforced **in the policy**:
a `restricted` document is visible only to its owner and to holders of
`papers.manage`. Test that the policy denies before any screen hides anything.

---

### Task 6: The Documents workspace

**Files:** modify `app/Livewire/Papers/Index.php` and its view

Adds: search by title/description/number, filters (kind, security, folder, tag,
status), the folder tree, and the overview counters — Total, Mine, Drafts,
Expiring, Archived. Paginated; no unbounded loads (§60).

Reuses the existing design system exactly: `x-ui.panel`, pill filters, `card`,
the token palette. Nothing new invented.

---

### Task 7: The Documents tab on ERP records

**Files:** create `resources/views/components/documents/panel.blade.php` + a Livewire component; modify the customer profile screen first

`<x-documents.panel :for="$contact" />` lists that record's documents, uploads
into it, and creates from a template with the relation pre-attached — so a
document started from a customer already knows its customer (§67) and the user
never picks it twice.

Tests: the panel renders on the customer profile; an upload from it is linked
automatically; a user without `papers.view` sees nothing; another company's
documents never appear.

---

### Task 8: API

**Files:** create `app/Http/Controllers/Api/BusinessDocumentController.php`; modify `routes/api.php`, `docs/API.md`, the OpenAPI summaries

`GET|POST /api/v1/library`, `GET|PUT|DELETE /api/v1/library/{document}`,
`POST|DELETE /api/v1/library/{document}/relations`.

Under existing `read`/`write` abilities and the `papers.*` permissions. Same
envelope, same conventions — no new auth, no new shape.

---

### Task 9: Documentation and regression

- [ ] `docs/documents.md` — the namespace rule (why not `documents`), the
      relation model, how another module registers a document kind.
- [ ] `docs/API.md` — new section.
- [ ] Regenerate the install schema: `php artisan opes:export-schema`
- [ ] **Full suite green**, including all six Papers test files.
- [ ] Commit.

---

## Testing plan

Per-commit gate, not a final one:

| Risk | Test |
|---|---|
| ERP `documents` altered | Migration touches only `business_documents` |
| Papers regressed | All six existing test files, every task |
| Documents become a child of ERP records | Deleting a contact leaves its documents |
| Cross-tenant leakage | Every new table |
| Confidentiality via UI only | Policy denies directly |
| Unbounded queries | Index paginates |
