# Documents: multilingual templates + department/project dossiers

Covers items 3 and 4 of `docs/superpowers/plans/2026-08-17-documents-completion.md`:
§44 (multilingual templates) and §9–10 (department/project dossier folders).

## §44 — Multilingual templates

### Storage decision

New table `business_document_template_translations` (migration
`2026_08_17_000402_create_business_document_template_translations.php`):
`id`, `company_id`, `business_document_template_id`, `language` (varchar 8,
same width as `business_documents.language`), `body` (longtext), timestamps.
Unique on `(business_document_template_id, language)`.

Reasoning: `business_document_templates.body` is a plain `longText` column,
and every existing reader — `CustomDocumentTemplates::find()`/`update()`,
`DocumentComposer::resolveTemplate()`, the `business_document_template_versions`
snapshot table — already treats it as one string. A JSON column would force
every one of those callers to learn a new shape just to keep working. A
translations table is purely additive: a template with zero rows in it (the
overwhelming majority, forever, for any business that never translates)
behaves exactly as it always has — `body` untouched, no reader changed.

A companion note: `companies.language` already existed as of migration
`2026_08_17_000401_add_language_to_companies.php` (found already in the repo
when this work started, likely landed by a concurrent session on the same
plan) — so "the company's configured language" the plan asks the picker to
default to is `Company::$language`, not something this change had to invent.

### Fallback / default behaviour

- `CustomDocumentTemplates::findModel(string $key): ?BusinessDocumentTemplate`
  — the model behind a published custom template key, additive alongside the
  existing `find()` (array shape) and `exists()`.
- `CustomDocumentTemplates::languagesFor($template): array` — every language
  code with a variant row.
- `CustomDocumentTemplates::setTranslation($template, $language, $body)` /
  `removeTranslation(...)` — manage variants, never touching `body`.
- `CustomDocumentTemplates::bodyFor($template, ?$language)` — the body to use:
  the variant if one exists for `$language`, else the template's own `body`.
  Never returns blank.
- `DocumentComposer::merge()` gained an optional trailing `?string $language`
  parameter (default `null` — every existing call site is unaffected).
  Internally it resolves the raw body via a new protected `bodyFor()` that:
  - returns the template's own `body` unchanged for a built-in template, or
    a custom template with zero translation rows (the "no behaviour change"
    case §44 requires);
  - otherwise resolves `$language ?? $company->language ?? config('app.locale')`
    against the variant list, falling back to the default `body` if that
    language has no variant.
- `DocumentComposer::resolveLanguage(string $templateKey, ?string $language, Company $company): ?string`
  — the language to persist on `BusinessDocument->language`. Returns `null`
  for a built-in template or an untranslated custom one (§44 stays opt-in
  per template, and existing single-body composition regresses to nothing).
  For a translated template it is never null: either the requested/default
  language matched a variant and that is reported, or it did not and the
  body fell back to the default — attributed to the company's configured
  language, the closest thing an un-tagged default body has to one.

### Where the language picker lives

`App\Livewire\Papers\Compose` (`app/Livewire/Papers/Compose.php`) — the
screen every `DocumentComposer::merge()` caller for hand-composed documents
already goes through. Added:

- `public string $language = ''` (empty means "company default").
- `public array $availableLanguages = []`, populated in `mount()` from
  `CustomDocumentTemplates::languagesFor()` when the template is a custom
  one; stays `[]` for a built-in or untranslated template.
- `preview()` and `save()` both pass `language: $this->language ?: null`
  into `merge()`, and `save()` sets `'language' => $composer->resolveLanguage(...)`
  on the `BusinessDocument` attributes.

The Blade view (`resources/views/livewire/papers/compose.blade.php`) shows a
`<select>` for language only when `count($availableLanguages) >= 1` — i.e.
never for a built-in or single-body template. It sits inside the same
"Details" panel already gated by the screen's own `papers.create` /
`papers.issue` authorization (`Compose::save()` calls
`$this->authorize($issue ? 'papers.issue' : 'papers.create')`); no separate
gate was needed since the whole screen is already behind one.

### Tests

`tests/Feature/Documents/TemplateTranslationTest.php`:
- composing with the company's default language picks the matching variant;
- composing with an explicit language override;
- falling back to the default body when the chosen language has no variant
  (body never blank);
- `BusinessDocument->language` set correctly through the `Compose` Livewire
  component for a multi-variant template;
- `BusinessDocument->language` stays `null` for a single-body custom
  template (regression);
- a built-in template composes unaffected and `resolveLanguage()` reports
  `null` for it (regression).

## §9–10 — Department / project dossier folders

Both were blocked only on Department and Project being real Eloquent models
(per the plan's own note) — they already are, and they already use
`BelongsToCompany`, so `DocumentLinker::assertSameCompany()` and
`DocumentDossiers::forRecord(Model $related)` work against them with zero
changes. No new query mechanism was written. `DocumentDossiers` gained two
one-line named wrappers so the two call sites read naturally and match every
other "dossier for X" method in the class:

```php
public function forDepartment(Department $department): array { return $this->forRecord($department); }
public function forProject(Project $project): array { return $this->forRecord($project); }
```

**Confirmed: not stored rows.** Both delegate straight to `forRecord()`,
which asks `DocumentLinker::documentsFor()` — a query over
`business_document_relations` — and groups the result by kind in memory.
There is no folder/dossier table for departments or projects; removing a
`DocumentLinker::attach()` link removes the document from the dossier
immediately (covered by
`test_the_department_dossier_reflects_a_link_being_removed`).

### Where `x-documents` was embedded

- **Projects** (`app/Livewire/Projects/Index.php`,
  `resources/views/livewire/projects/index.blade.php`): `Projects\Index` is
  an index/list screen, but it already has a per-row expand
  (`$open`/`$openProject`, toggled by `toggleOpen()`) used for the
  tasks/milestones panel. `<x-documents.library-panel :record="$openProject" title="Project dossier" />`
  was added inside that existing expand, right after the task form. No new
  screen invented — this reuses the one detail area the screen already has.
- **Departments** (`app/Livewire/Business/Departments.php`,
  `resources/views/livewire/business/departments.blade.php`): unlike
  Projects, `Departments` is a pure list + side-panel edit form with **no
  existing per-row detail/expand area**. A minimal same-shaped affordance
  was added — `public ?string $openDocuments`, `toggleDocuments(string $id)`,
  and an `openDepartment` value passed to the view — mirroring Projects'
  `$open`/`$openProject` pattern exactly, then
  `<x-documents.library-panel :record="$openDepartment" title="Department dossier" />`
  is shown when a row is unfolded. This is the smallest form of "embed the
  panel" available without adding a genuinely new show/detail screen: it is
  an extra button in the existing row, not a new route or component.

Both embeds inherit `x-documents.library-panel`'s own gating —
`@can('papers.view')` wraps the whole panel, `@can('papers.create')` wraps
its "Add a document" link — so no separate permission check was needed in
either Blade view. The "Documents" toggle button on the Departments row is
additionally wrapped in `@can('papers.view')` so a user without it does not
even see the button.

### Tests

`tests/Feature/Documents/DepartmentProjectDossierTest.php`:
- department dossier returns only documents linked to that department;
- empty dossier for a department with none;
- project dossier returns only documents linked to that project;
- empty dossier for a project with none;
- dossier reflects a link being removed (proves it is a live query, not a
  cached/stored folder);
- the Departments screen renders with the documents affordance present;
- the Projects screen renders with an unfolded project's dossier;
- a `Role::CASHIER` user (no `papers.view`) sees no document titles on the
  Departments screen, mirroring the existing pattern in
  `tests/Feature/Documents/LibraryPanelTest.php`.

## Files changed

- `database/migrations/2026_08_17_000402_create_business_document_template_translations.php` (new)
- `app/Models/BusinessDocumentTemplateTranslation.php` (new)
- `app/Models/BusinessDocumentTemplate.php` (added `translations()`)
- `app/Services/Documents/CustomDocumentTemplates.php` (language-variant API)
- `app/Services/DocumentComposer.php` (`merge($language)`, `resolveLanguage()`, `bodyFor()`, `defaultLanguage()`)
- `app/Livewire/Papers/Compose.php` (language picker state + wiring)
- `resources/views/livewire/papers/compose.blade.php` (language `<select>`)
- `app/Services/Documents/DocumentDossiers.php` (`forDepartment()`, `forProject()`)
- `app/Livewire/Business/Departments.php` (`openDocuments`/`toggleDocuments`)
- `resources/views/livewire/business/departments.blade.php` (embed + toggle button)
- `resources/views/livewire/projects/index.blade.php` (embed in existing expand)
- `tests/Feature/Documents/TemplateTranslationTest.php` (new)
- `tests/Feature/Documents/DepartmentProjectDossierTest.php` (new)
- `docs/handoff/documents-i18n-dossiers.md` (this file)

## Follow-ups / gaps

- No UI was added for a business to *manage* template translations (create,
  edit, delete a variant) — `CustomDocumentTemplates::setTranslation()` /
  `removeTranslation()` exist and are tested indirectly through the
  composer, but there is no template-editor screen change. The plan's wave
  description for §44 only asked for compose-time language selection and
  per-language storage; a translations-management UI is a reasonable
  follow-up but out of scope here.
- `defaultLanguage()` in `DocumentComposer` falls back to
  `config('app.locale', 'en')` if `Company->language` is somehow null
  (shouldn't happen given the column defaults to `'en'`), for defensiveness
  only.
- The Departments embed is a same-page toggle, not a dedicated department
  show/detail screen — there isn't one in this codebase yet. If one is ever
  built, the panel should move there instead of the list-row toggle added
  here.
- Ran `php artisan test --filter="Template|Dossier|Document|Department|Project"`
  as instructed; a concurrent session was editing many other Documents-module
  files at the same time (visible in `git status` — files this task never
  touched, e.g. `app/Livewire/Papers/Show.php`, `app/Services/Documents/DocumentBundles.php`),
  so that broad run's pass/fail counts also reflect that other, unrelated
  in-flight work rather than only this change. The scoped run against just
  the files this task touched — `ComposeCustomTemplateTest`, `CustomTemplateTest`,
  `DepartmentProjectDossierTest`, `DossierAndPackageTest`, `LibraryPanelTest`,
  `TemplateTranslationTest` — passed cleanly: 49 tests, 76 assertions, 0 failures.
