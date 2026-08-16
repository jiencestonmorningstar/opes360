# Global search — integration handoff

One search box (Ctrl/Cmd-K palette in the top bar) that finds customers, sales
documents, papers, service tickets, contracts, employees, products, projects,
leads and purchase requisitions, filtered by the searcher's own permissions and
fenced to the current company.

## What was built

| Piece | Path |
| --- | --- |
| Index table | `database/migrations/2026_09_13_000300_create_search_entries_table.php` |
| Entry model | `app/Models/SearchEntry.php` (BelongsToCompany) |
| Sources, indexing, querying | `app/Search/GlobalSearch.php` |
| Observer | `app/Search/SearchIndexObserver.php` |
| Backfill | `php artisan opes:search-reindex [--company=]` (`app/Console/Commands/SearchReindex.php`) |
| Palette | `app/Livewire/Search/Palette.php` + `resources/views/livewire/search/palette.blade.php` |
| Layout wiring | `@livewire('search.palette')` added to `resources/views/partials/topbar.blade.php` |
| Tests | `tests/Feature/Search/GlobalSearchTest.php` |

## REQUIRED registration (one line)

The observers are not self-registering — providers were out of this task's
ownership. Add to `AppServiceProvider::boot()` (or any provider that boots on
every request **and** in console):

```php
\App\Search\GlobalSearch::observe();
```

Until that line lands, the index only updates via `opes:search-reindex` — run
it once after deploying the migration, and again after adding the line.

No new route was needed: the palette is an embedded Livewire component, not a
page, so the reserved `search` route name is unused.

## Approach, defended

A `search_entries` table maintained by observers and queried with `LIKE`,
rather than Scout/Meilisearch or per-table UNIONs:

- **Zero infrastructure.** Works identically on shared hosting, the VPS,
  SQLite tests and MySQL production. No daemon, no paid vendor. `MATCH
  AGAINST` was deliberately avoided (SQLite tests would diverge from MySQL).
- **Fast enough.** One indexed table, `(company_id, title)` composite index,
  prefix matches ranked first, candidates capped. At this product's per-tenant
  volumes a LIKE over a few tens of thousands of narrow rows is milliseconds.
- **Permissions are the hard part, not ranking.** Each row carries the
  page-level ability of the screen a click opens; `GlobalSearch::query()`
  re-checks it per searcher through `Gate` **and** `Modules::allowsAbility`
  (module switched off ⇒ no results), then re-checks record-level `view`
  policies on the hydrated model for anything that has one (so a restricted
  paper is only offered to people its policy would let open it).
- **Tenancy is structural.** `SearchEntry` uses `BelongsToCompany`; the
  `CompanyScope` fences every read. There is no code path that queries the
  index unscoped except the reindex command's delete.

## Deliberately NOT indexed (and why)

- **Bodies of any paper with a security level** (`business_documents.security
  != null`): title and reference only. The words of a confidential paper must
  not exist in the index at all, or a search snippet becomes an oracle.
- **Employee pay and personal details**: name and staff number only.
- **Voided documents/papers** and **soft- or force-deleted records**: removed
  from the index the moment they are voided/trashed (observer `saved` +
  `deleted` + `forceDeleted`; `restored` re-indexes).
- **Payslips/payroll, bank data, audit rows**: not sources at all.
- **Searches themselves are not audit-logged**: typing is navigation, and
  logging it would bury the trail in noise.

## Meilisearch upgrade path (VPS, later)

The entry shape (title/subtitle/body/route/ability/company_id) is already a
search-engine document. To swap:

1. Install Scout + the Meilisearch driver on the VPS; keep `search_entries` as
   the canonical source (make `SearchEntry` itself `Searchable`, syncing on the
   same observer writes — nothing else changes).
2. Replace the `LIKE` block in `GlobalSearch::query()` with a Scout query
   filtered by `company_id`; keep everything after it — the ability filter, the
   policy re-check and the grouping are storage-agnostic and must survive any
   engine swap.
3. Shared-hosting fallback: keep the LIKE branch behind a config check
   (`config('scout.driver')`), so the same build degrades gracefully.

## Notes for the next hand

- `GlobalSearch::observe()` has no static guard on purpose: the test suite
  rebuilds the event dispatcher per test, and a guard that survives it left
  later tests silently unobserved. Call it once per boot.
- Items/projects/leads/requisitions link to their index pages (they have no
  show routes); everything else deep-links to its record.
- Lazy loading is disabled app-wide: the query eager-loads `searchable`, and
  the Document source reads the contact name with an explicit relation query.
