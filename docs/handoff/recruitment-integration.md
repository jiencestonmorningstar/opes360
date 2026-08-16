# Recruitment — integration handoff

Candidates, applications, interviews, offers, and a public application page.
The last HR gap (checklist #9), built as its own module the way the 3.9
handoff prescribed. Model-, service- and screen-level only: nothing was
routed, gated, seeded or documented in the shared files, because those files
belong to other agents in flight.

## What shipped

Migrations (`2026_09_12_0003xx_`):

- `000301_create_vacancies_table` — `vacancies` (points at `positions`, carries the public share token)
- `000302_create_candidates_table` — `candidates` (the person, apart from their applications; `employee_id` records who they became)
- `000303_create_job_applications_table` — `job_applications` (deliberately not `recruitment_applications`: the long prefix pushes generated identifiers toward MySQL's 64-char limit)
- `000304_create_application_stage_moves_table` — append-only stage history
- `000305_create_interviews_table`
- `000306_create_interview_feedback_table` — one scorecard row per interviewer, created blank at scheduling
- `000307_create_job_offers_table`

Models: `Vacancy`, `Candidate`, `JobApplication`, `ApplicationStageMove`,
`Interview`, `InterviewFeedback`, `JobOffer`. All tenant-scoped
(`BelongsToCompany`) with ULID keys; user FKs are `foreignId` (users.id is
BIGINT). `JobOffer` is `Approvable` + `EmitsDomainEvents`.

Services (`app/Services/Recruitment/`):

- `RecruitmentPipeline` — apply (the public page's one write), stage moves +
  history, interview scheduling, scorecards, and `purge()` (GDPR).
- `JobOffers` — make (offer + letter together), submit (workflow engine),
  accept (hires), decline, withdraw.
- `CandidateHiring` — the accepted offer → Employee + first
  EmploymentContract.

Listener: `App\Listeners\MarkApprovedJobOffers` — mirrors
`ActivateApprovedContracts`; auto-discovered from its
`handle(DomainEvent $event)` signature, so **do not** add an
`Event::listen` line for it.

Controller + views: `VacancyPublicController` with `public/vacancy.blade.php`
and `public/vacancy-thanks.blade.php`, following `FormPublicController`'s
token pattern exactly.

Screens: `App\Livewire\Recruitment\Index` (vacancies + pipeline board) and
`Show` (one application: timeline, interviews, offer) + views. Route calls in
the blades are guarded with `Route::has()` so nothing breaks before routing
lands.

Tests: `tests/Feature/Recruitment/` — 33 passing. Regression gate
(`Recruitment|Position|Employee|Team|Form`) 285 passing; `Contract|Tenancy|
DomainEvent|Listener` 72 passing. Pint clean.

## How hiring reuses the existing employee-creation path

`App\Livewire\Team\Index::save()` is the existing path, and it lives inside a
Livewire component this module could not edit or call. `CandidateHiring`
therefore mirrors its exact shape — an `Employee` and their first active
`EmploymentContract` in one transaction, because an employee without a
contract cannot be paid — rather than inventing a different one. What hiring
adds: `position_id` from the vacancy, the free-text `job_title`/`department`
snapshot columns filled from the position (3.9's handoff is explicit the
strings stay alongside the ids — payroll snapshots them), and
`candidate.employee_id` pointing at who they became.
`PipelineTest::test_the_full_pipeline_produces_a_real_employee_linked_to_the_position`
pins the two-record shape. If Team ever extracts its save into a service,
`CandidateHiring::hire()` is the second caller waiting for it.

One deliberate default: `payment_method` is set to `cash` (the Team form's
own default) because an offer letter has not had the "how do we pay you"
conversation; the staff page is where it gets corrected. An employee with no
method at all would break payroll.

## Things you need to add (I could not)

### 1. Routes (`routes/web.php`)

Authenticated, wherever the Team/HR screens live — **not** inside the
`/team/{employee}` group (`/team/recruitment` would be swallowed as an
employee ULID; the same trap that put attendance under `/hr/`):

```php
use App\Livewire\Recruitment\Index as RecruitmentIndex;
use App\Livewire\Recruitment\Show as RecruitmentShow;

Route::get('/hr/recruitment', RecruitmentIndex::class)
    ->middleware('can:recruitment.view')->name('recruitment');

Route::get('/hr/recruitment/{application}', RecruitmentShow::class)
    ->middleware('can:recruitment.view')->name('recruitment.show');
```

Public, beside the `/f/{token}` form routes (same middleware group, no auth):

```php
use App\Http\Controllers\VacancyPublicController;

Route::get('/jobs/{token}', [VacancyPublicController::class, 'show'])->name('vacancy.public');
Route::post('/jobs/{token}', [VacancyPublicController::class, 'submit'])->name('vacancy.public.submit');
Route::get('/jobs/{token}/thanks', [VacancyPublicController::class, 'thanks'])->name('vacancy.public.thanks');
```

These three public lines are verbatim what `RecruitmentTestCase` registers,
so they are already proven end-to-end. `Vacancy::publicUrl()` assumes
`/jobs/{token}`.

Not yet routed anywhere: downloading a stored CV. The path sits on
`job_applications.cv_path` on the private `documents` disk; it needs a small
controller that gates on `recruitment.view` and streams
`Storage::disk($application->cv_disk)->…` — the Show screen currently shows
the filename only.

### 2. Permissions — a `Recruitment` group

```
recruitment.view       see vacancies, the pipeline, an application
recruitment.manage     vacancies, stage moves, rejections, scheduling
recruitment.interview  fill in one's own interview scorecard
recruitment.offer      make / submit / accept / decline offers
```

Why four rather than view/manage: **interview** exists because a panel
member is often exactly the person who should touch nothing else — a
workshop foreman scoring a candidate must not be able to reject applications
or read the pipeline of another vacancy's salaries; the component lets
`recruitment.interview` write only their own scorecard. **offer** is split
from manage because an offer names a salary and ends in an employment
contract — it is the money-shaped act of the module, and the person who runs
the pipeline day-to-day (an office administrator) is frequently not the
person allowed to put a number in front of a candidate. Collapsing offer
into manage silently gives the scheduler the chequebook.

The test case defines these four gates inline (delegating to
`hasPermissionIn`, exactly what the `AuthServiceProvider` loop produces);
once the slugs land in `App\Support\Permissions` those definitions become
harmless duplicates and can be deleted from `RecruitmentTestCase::setUp()`.

### 3. Role seeding

`RolePermissionSeeder`: owner/administrator all four; manager
view/manage/interview (offer is arguable — grant it if managers hire);
everyone on a panel needs at least `recruitment.interview`.

### 4. DefaultWorkflows entry

Offers refuse submission until a workflow exists (`JobOffers::submit`
throws, tested). Add to `DefaultWorkflows::catalogue()` — same conservative
owner-approves shape, no threshold (every salary is worth a signature, the
Contracts reasoning):

```php
// Offering somebody a salary. Signing the business up to a wage bill
// is a contract in all but name, so it gets the same single signature.
JobOffer::class => [
    'name' => 'Job offers',
    'step' => 'Owner approves',
],
```

(plus `use App\Models\JobOffer;`.) `RecruitmentTestCase::offerWorkflow()`
creates this exact shape, so the seeded path is already what the tests prove.

### 5. Listener registration — nothing to do, but read this

`MarkApprovedJobOffers` is picked up by event discovery. Do **not** add an
`Event::listen` line in `AppServiceProvider` — that is the double-fire bug
its comment block documents. The tests register it explicitly (discovery
did not fire in the test environment, same as `ActivateApprovedContracts`'s
tests); the listener only acts on a `pending` offer so a double
registration writes once.

### 6. Nav

A "Recruitment" link in the Team area beside Attendance/Reviews, under the
existing `team` highlight — both components already pass `'active' => 'team'`.

### 7. `config/modules.php`

Recruitment belongs in the module list, **switchable, and off by default**.
The argument: unlike positions (which outlive any module switch because an
employee's job title is permanent data), recruitment is a pure activity —
turning it off strands nothing that payroll, documents or the staff file
read. And most businesses this product serves hire rarely; a module that
sits unused in the nav eleven months a year is exactly what the module
switch exists for. The one caveat: a disabled module must also 404 the
public `/jobs/{token}` pages (a live advert for a switched-off module takes
applications nobody will ever see), so the module check belongs in
`VacancyPublicController::findVacancy()` or its route middleware when this
lands. Ability prefix `recruitment.` needs mapping in `Modules::forAbility`.

## The retention question (raised, not solved)

`purge()` makes deletion *possible*: force-deletes the candidate, their
applications, stage history, interviews, scorecards and CV files; refuses
while an application is live; refuses outright for hired candidates (their
trail is part of an employment record with its own rules). What nobody has
decided is *when it happens without being asked*: GDPR-style regimes expect
rejected candidates' data to go after a defined period (commonly 6–24
months) unless consent to keep it was given. That is a business-policy
decision — retention period, whether to ask consent on the public form, and
a scheduled command sweeping `stage = rejected` past the cutoff. The
plumbing (`softDeletes` everywhere, `purge()` as the single erasure path)
is ready for whichever answer is chosen. Decide it before this ships to a
jurisdiction that asks.

## Smaller decisions worth knowing

- The offer letter is generated **at draft time** so the approver reads the
  actual document, from the existing `offer_letter` template via
  `DocumentComposer::merge` — recruitment writes no letters. It is created
  `security = confidential` because it names a salary.
- Hiring cannot happen from the board. `moveStage` refuses `hired`; the only
  route in is `JobOffers::accept()`, which re-checks `isApproved()` against
  the engine — a hand-edited `status` column is refused (tested).
- Re-applying with the same email refreshes the papers but never resets the
  stage: a rejected candidate cannot un-reject themselves.
- A vacancy closes itself when hires reach `openings`.
- The public page's tenancy is the token, full stop: vacancy looked up
  `withoutGlobalScope`, its eager-loaded position explicitly unscoped
  (CompanyScope fails closed with no current company — an oversight there
  rendered the advert titleless), and every written row stamped with the
  vacancy's own `company_id`.
- CV uploads: private `documents` disk (no URL), 10 MB, allow-list where the
  extension and the **sniffed** mime must agree — DocumentFiler's rule,
  narrowed (no spreadsheets on a public endpoint). A PHP script named
  `cv.pdf` is refused, tested with a real temp file because
  `UploadedFile::fake()` reports the mime its name suggests.
