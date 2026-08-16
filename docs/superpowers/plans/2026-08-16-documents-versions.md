# Document Versions, Locking & Finalisation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** every content change to a draft document leaves a retrievable
version, a draft can be locked against further edits without being issued, and
issuing a document is the finalisation event §71 describes — already mostly
true, made explicit.

**Architecture:** `business_document_versions` is an append-only table, one row
per content snapshot. A version is written automatically whenever a draft's
content actually changes — not on every save, since filing columns changing
is not content changing. Locking is a boolean on the document, checked
alongside `isDraft()` everywhere content edits are gated, so a locked draft
behaves like an issued one for editing purposes without going through
issuance.

**What already exists and is not rebuilt:** issuance already freezes content
(`BusinessDocument::booted()`), already computes a tamper hash
(`canonicalPayload()`/`content_hash`), and already records who issued a
document and when. That is finalisation (§71) in substance; this plan does not
duplicate it, only names it in the guide.

**Tech Stack:** Laravel 12, Pest/PHPUnit. No new Composer packages.

**Roadmap item:** 2.3. **Spec:** §12 (versions), §70 (locking), §71
(finalisation) of the master spec.

---

## Decisions taken

| # | Decision | Why |
|---|---|---|
| 1 | A version is written on **content change**, not every save | Filing a document (folder, tags, owner) is not editing (per `BusinessDocument::booted()`); versioning every filing action would flood the history with nothing to compare |
| 2 | Versions are **never destroyed**, even by restore | §12: "never destroy historical versions unless retention explicitly permits". Restore creates a new current state and a new version recording it; it does not delete what came after |
| 3 | Restoring is itself a versioned edit | Otherwise "what does the document say right now" and "what does the version history say happened" could disagree |
| 4 | Locking is separate from issuing | §70 describes lock states distinct from final; a business may want to freeze a draft mid-review without committing to issuing it that day |
| 5 | Finalisation is not re-implemented | Issuance already does it. Building a second "finalise" action beside "issue" would be the exact duplication pattern this whole effort exists to avoid |

---

## File structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_29_000001_create_business_document_versions_table.php` | The table |
| `app/Models/BusinessDocumentVersion.php` | One snapshot |
| `app/Services/Documents/DocumentVersioner.php` | Decides whether content changed; writes a version; restores one |
| `app/Models/BusinessDocument.php` (modify) | `is_locked` column, `versions()`, hook into the versioner |
| `tests/Feature/Documents/DocumentVersionTest.php` | Task 1 |
| `tests/Feature/Documents/DocumentLockingTest.php` | Task 2 |

---

### Task 1: Versions

**Files:**
- Create: the migration, `app/Models/BusinessDocumentVersion.php`,
  `app/Services/Documents/DocumentVersioner.php`
- Modify: `app/Models/BusinessDocument.php`
- Test: `tests/Feature/Documents/DocumentVersionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\DocumentVersioner;

class DocumentVersionTest extends DocumentsTestCase
{
    public function test_creating_a_document_writes_its_first_version(): void
    {
        $paper = $this->document(['title' => 'Service agreement']);

        $this->assertSame(1, $paper->versions()->count());
        $this->assertSame('Service agreement', $paper->versions()->first()->title);
    }

    public function test_editing_content_writes_a_new_version(): void
    {
        $paper = $this->document(['title' => 'Draft title']);

        $paper->update(['title' => 'Revised title']);

        $this->assertSame(2, $paper->fresh()->versions()->count());
        $this->assertSame('Revised title', $paper->fresh()->versions()->latest('version_number')->first()->title);
    }

    /** Filing is not editing, and must not flood the history with nothing to compare. */
    public function test_filing_a_document_does_not_write_a_new_version(): void
    {
        $paper = $this->document();

        $paper->update(['tags' => ['2026'], 'folder_id' => null]);

        $this->assertSame(1, $paper->fresh()->versions()->count());
    }

    public function test_versions_are_numbered_in_order(): void
    {
        $paper = $this->document(['title' => 'One']);
        $paper->update(['title' => 'Two']);
        $paper->update(['title' => 'Three']);

        $this->assertSame([1, 2, 3], $paper->fresh()->versions()->orderBy('version_number')->pluck('version_number')->all());
    }

    public function test_a_version_records_who_made_it(): void
    {
        $paper = $this->document();

        $this->assertSame($this->owner->id, $paper->versions()->first()->created_by);
    }

    public function test_restoring_a_version_updates_the_current_content(): void
    {
        $paper = $this->document(['title' => 'Original', 'body' => 'Original terms.']);
        $paper->update(['title' => 'Changed', 'body' => 'Changed terms.']);
        $original = $paper->fresh()->versions()->orderBy('version_number')->first();

        app(DocumentVersioner::class)->restore($paper->fresh(), $original, $this->owner);

        $this->assertSame('Original', $paper->fresh()->title);
        $this->assertSame('Original terms.', $paper->fresh()->body);
    }

    /** Restoring is itself a versioned edit, or the two records of "now" disagree. */
    public function test_restoring_creates_a_new_version_rather_than_rewriting_history(): void
    {
        $paper = $this->document(['title' => 'Original']);
        $paper->update(['title' => 'Changed']);
        $original = $paper->fresh()->versions()->orderBy('version_number')->first();

        app(DocumentVersioner::class)->restore($paper->fresh(), $original, $this->owner);

        $versions = $paper->fresh()->versions()->orderBy('version_number')->get();

        $this->assertSame(3, $versions->count());
        $this->assertSame('Changed', $versions[1]->title);
        $this->assertSame('Original', $versions[2]->title);
    }

    public function test_an_issued_document_cannot_be_restored(): void
    {
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $version = $paper->versions()->first();

        $this->expectException(\RuntimeException::class);

        app(DocumentVersioner::class)->restore($paper->fresh(), $version, $this->owner);
    }

    public function test_deleting_a_document_takes_its_versions(): void
    {
        $paper = $this->document();

        $paper->forceDelete();

        $this->assertDatabaseCount('business_document_versions', 0);
    }
}
```

- [ ] **Step 2: Run it — expect failure**

```bash
export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:$PATH"
php artisan test --filter=DocumentVersionTest
```

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
        Schema::create('business_document_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('business_document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            // A snapshot of the content columns only — never the filing
            // columns, which are not what a version is a version of.
            $table->string('title');
            $table->string('recipient')->nullable();
            $table->json('fields')->nullable();
            $table->longText('body')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_document_id', 'version_number']);
            $table->index(['company_id', 'business_document_id']);
        });

        Schema::table('business_documents', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });

        Schema::dropIfExists('business_document_versions');
    }
};
```

- [ ] **Step 4: `app/Models/BusinessDocumentVersion.php`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One snapshot of a document's content. Never destroyed, never edited — §12:
 * historical versions survive unless a retention policy explicitly permits
 * their disposal, and no such policy exists yet.
 */
class BusinessDocumentVersion extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('A document version cannot be edited.'));
        static::deleting(fn () => throw new RuntimeException('A document version cannot be deleted.'));
    }
}
```

- [ ] **Step 5: `app/Services/Documents/DocumentVersioner.php`**

```php
<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\User;
use RuntimeException;

/**
 * Decides whether a save changed content, and if so, snapshots it.
 *
 * The distinction this exists to make: filing (kind, tags, folder, owner,
 * expiry, department) is not content, and moving a document into a folder
 * must not flood its history with a version that reads identically to the
 * one before it.
 */
class DocumentVersioner
{
    /** The columns a version is a version of. Everything else is filing. */
    protected const CONTENT_COLUMNS = ['title', 'recipient', 'fields', 'body'];

    public function snapshotIfChanged(BusinessDocument $document): void
    {
        $dirty = array_intersect_key($document->getDirty(), array_flip(self::CONTENT_COLUMNS));

        if ($document->wasRecentlyCreated === false && $dirty === []) {
            return;
        }

        $this->snapshot($document);
    }

    protected function snapshot(BusinessDocument $document): BusinessDocumentVersion
    {
        $next = (int) $document->versions()->max('version_number') + 1;

        return BusinessDocumentVersion::create([
            'business_document_id' => $document->id,
            'version_number' => $next,
            'title' => $document->title,
            'recipient' => $document->recipient,
            'fields' => $document->fields,
            'body' => $document->body,
            'created_by' => $document->getDirty()['updated_by'] ?? auth()->id() ?? $document->created_by,
        ]);
    }

    /**
     * Restore replaces current content with a past version's, and — because a
     * version is never rewritten — records that act as a new version of its
     * own. The two records of "what does this say right now" would otherwise
     * disagree.
     */
    public function restore(BusinessDocument $document, BusinessDocumentVersion $version, User $actor): BusinessDocument
    {
        if (! $document->isDraft()) {
            throw new RuntimeException('Only a draft can be restored to an earlier version.');
        }

        $document->forceFill([
            'title' => $version->title,
            'recipient' => $version->recipient,
            'fields' => $version->fields,
            'body' => $version->body,
        ])->save();

        return $document->fresh();
    }
}
```

- [ ] **Step 6: Wire it into `BusinessDocument`**

Add the relation and call the versioner from `booted()`, after the existing
issued-document guard:

```php
public function versions(): HasMany
{
    return $this->hasMany(BusinessDocumentVersion::class);
}
```

In `booted()`, add a `created` and `updated` hook:

```php
static::created(fn (BusinessDocument $document) => app(DocumentVersioner::class)->snapshotIfChanged($document));
static::updated(fn (BusinessDocument $document) => app(DocumentVersioner::class)->snapshotIfChanged($document));
```

**Check `wasRecentlyCreated` handling carefully** — on `created`, every column
is "dirty" by Eloquent's bookkeeping even though nothing changed from nothing,
so `snapshotIfChanged` must always version a brand-new document regardless of
which columns technically differ from an unset state. Adjust the condition in
Step 5 if the first test fails on this point; the fix is checking
`$document->wasRecentlyCreated` before checking dirty content columns, not
after.

- [ ] **Step 7: Run — expect pass, then the regression gate**

```bash
php artisan test --filter=DocumentVersionTest
php artisan test --filter="DocumentGenerator|FillPreview|LetterheadDesigns|NewBusinessDocumentTemplates|PrintFidelity|Documents"
```

- [ ] **Step 8: Regenerate the schema and commit**

```bash
export PATH="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
php artisan opes:export-schema
```

---

### Task 2: Locking

**Files:**
- Modify: `app/Models/BusinessDocument.php`, `app/Policies/BusinessDocumentPolicy.php`
- Test: `tests/Feature/Documents/DocumentLockingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Documents;

class DocumentLockingTest extends DocumentsTestCase
{
    public function test_a_locked_draft_cannot_be_edited(): void
    {
        $paper = $this->document(['is_locked' => true]);

        $this->assertFalse($this->owner->can('update', $paper));
    }

    public function test_locking_is_itself_a_filing_action(): void
    {
        $paper = $this->document();

        $this->assertTrue($this->owner->can('file', $paper));

        $paper->update(['is_locked' => true]);

        $this->assertSame(1, $paper->fresh()->versions()->count());
    }

    public function test_an_unlocked_draft_can_be_edited_again(): void
    {
        $paper = $this->document(['is_locked' => true]);

        $paper->update(['is_locked' => false]);

        $this->assertTrue($this->owner->can('update', $paper->fresh()));
    }

    public function test_locking_does_not_require_issuing(): void
    {
        $paper = $this->document(['is_locked' => true]);

        $this->assertTrue($paper->fresh()->isDraft());
        $this->assertFalse($paper->fresh()->isIssued());
    }
}
```

- [ ] **Step 2: Run it — expect failure.**

- [ ] **Step 3: Add `is_locked` to `BusinessDocument`'s filing allow-list**

In `booted()`'s issued-document guard, add `'is_locked'` to `$mutable` (it is
already filed alongside the others once issued, harmlessly — an issued
document is locked by definition).

- [ ] **Step 4: Gate `update()` on it**

In `BusinessDocumentPolicy::update()`:

```php
    public function update(User $user, Model $paper): bool
    {
        return $paper instanceof BusinessDocument
            && $paper->isDraft()
            && ! $paper->is_locked
            && parent::update($user, $paper)
            && $this->readable($user, $paper);
    }
```

- [ ] **Step 5: Run — expect pass. Regression gate. Commit.**

```bash
php artisan test --filter=DocumentLockingTest
php artisan test --filter="DocumentGenerator|FillPreview|LetterheadDesigns|NewBusinessDocumentTemplates|PrintFidelity|Documents"
```

---

### Task 3: Documentation

- [ ] Add a **Versions** guide, or extend `documents-workspace.md`: how a
      version is made, that filing does not make one, how restore works, and
      that finalisation is issuance — there is no separate "finalise" button
      because issuing already does everything §71 asks for.
- [ ] `docs/API.md`: `GET /api/v1/library/{document}/versions` and
      `POST /api/v1/library/{document}/versions/{version}/restore` — or note
      explicitly that this ships without an API yet, the way Projects did,
      rather than silently omitting it.
- [ ] Update `docs/GAP-ANALYSIS.md` and the roadmap.
- [ ] Full suite. Commit.

---

## Testing plan

| Risk | Test |
|---|---|
| Filing floods the version history | `test_filing_a_document_does_not_write_a_new_version` |
| Restore rewrites history instead of adding to it | `test_restoring_creates_a_new_version_rather_than_rewriting_history` |
| An issued document is restored, breaking its hash | `test_an_issued_document_cannot_be_restored` |
| A locked draft is still editable | `test_a_locked_draft_cannot_be_edited` |
| Locking is confused with issuing | `test_locking_does_not_require_issuing` |
| MySQL rejects a key SQLite accepted | `php artisan opes:export-schema` before commit |
