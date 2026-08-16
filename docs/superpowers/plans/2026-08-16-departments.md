# Departments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** promote departments from a free-text string on `employees` to a real
company-scoped entity, so HR, Documents and (later) approval routing all read
the same list instead of three spellings of "Finance".

**Architecture:** A `departments` table, tenant-scoped like every other, with an
optional parent so a business can express an org tree. `employees.department_id`
is added alongside the existing `employees.department` string, which is
**backfilled and then left in place** — the brief forbids deleting existing
data, and a business that has typed 400 department names into that column must
not lose them to a migration. Documents gains a `department_id` filing column
on the same allow-list reasoning as `folder_id`: filing is not editing.

Departments are **core, not a module.** They are not listed in
`config/modules.php`, so a business that switches HR off keeps its
departments — otherwise Documents' department folders would vanish with it.

**Tech Stack:** Laravel 12, Livewire 3, Tailwind v4, Pest/PHPUnit. No new
Composer packages.

**Roadmap item:** 1.1 of `docs/superpowers/plans/2026-08-16-build-everything-roadmap.md`

---

## File structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_25_000001_create_departments_table.php` | The table |
| `database/migrations/2026_08_25_000002_link_departments_to_records.php` | `department_id` on `employees`, `business_documents`, `business_document_folders`, and the backfill |
| `app/Models/Department.php` | The entity and its tree |
| `app/Policies/DepartmentPolicy.php` | `departments.view` / `departments.manage` |
| `app/Livewire/Business/Departments.php` | The admin screen |
| `resources/views/livewire/business/departments.blade.php` | Its view |
| `tests/Feature/Departments/DepartmentTestCase.php` | Shared setup |
| `tests/Feature/Departments/DepartmentTest.php` | The entity |
| `tests/Feature/Departments/DepartmentBackfillTest.php` | The migration's data promise |
| `tests/Feature/Departments/DepartmentScreenTest.php` | The screen and its permissions |

---

### Task 1: The department entity

**Files:**
- Create: `database/migrations/2026_08_25_000001_create_departments_table.php`
- Create: `app/Models/Department.php`
- Create: `tests/Feature/Departments/DepartmentTestCase.php`
- Test: `tests/Feature/Departments/DepartmentTest.php`

- [ ] **Step 1: Write the shared test case**

```php
<?php

namespace Tests\Feature\Departments;

use App\Models\Company;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class DepartmentTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function department(array $attributes = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Finance',
        ], $attributes));
    }

    protected function memberAt(string $role): User
    {
        $user = User::factory()->create();

        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Departments;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

class DepartmentTest extends DepartmentTestCase
{
    public function test_a_department_belongs_to_the_current_company(): void
    {
        $this->assertSame($this->company->id, $this->department()->company_id);
    }

    public function test_two_departments_in_one_company_cannot_share_a_name(): void
    {
        $this->department(['name' => 'Finance']);

        $this->expectException(QueryException::class);

        $this->department(['name' => 'Finance']);
    }

    /** Two businesses naming a department Finance is not a collision. */
    public function test_another_company_may_use_the_same_name(): void
    {
        $this->department(['name' => 'Finance']);

        $stranger = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $stranger->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($other, $stranger, Role::OWNER);
        app(CurrentCompany::class)->set($other);

        $elsewhere = Department::create(['name' => 'Finance']);

        $this->assertSame($other->id, $elsewhere->company_id);
        $this->assertSame(1, Department::query()->count());
    }

    public function test_a_department_can_sit_under_another(): void
    {
        $parent = $this->department(['name' => 'Operations']);
        $child = $this->department(['name' => 'Logistics', 'parent_id' => $parent->id]);

        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->first()->is($child));
    }

    public function test_the_path_reads_from_the_root_down(): void
    {
        $parent = $this->department(['name' => 'Operations']);
        $child = $this->department(['name' => 'Logistics', 'parent_id' => $parent->id]);

        $this->assertSame('Operations / Logistics', $child->path());
    }

    /** A cycle makes path() recurse until the process dies. */
    public function test_a_department_cannot_be_moved_inside_its_own_descendant(): void
    {
        $parent = $this->department(['name' => 'Operations']);
        $child = $this->department(['name' => 'Logistics', 'parent_id' => $parent->id]);

        $this->expectException(RuntimeException::class);

        $parent->update(['parent_id' => $child->id]);
    }

    public function test_a_department_cannot_be_its_own_parent(): void
    {
        $department = $this->department();

        $this->expectException(RuntimeException::class);

        $department->update(['parent_id' => $department->id]);
    }

    public function test_the_tree_is_capped_at_four_levels(): void
    {
        $a = $this->department(['name' => 'A']);
        $b = $this->department(['name' => 'B', 'parent_id' => $a->id]);
        $c = $this->department(['name' => 'C', 'parent_id' => $b->id]);
        $d = $this->department(['name' => 'D', 'parent_id' => $c->id]);

        $this->expectException(RuntimeException::class);

        $this->department(['name' => 'E', 'parent_id' => $d->id]);
    }

    public function test_a_department_can_have_a_manager(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $department = $this->department(['manager_id' => $manager->id]);

        $this->assertTrue($department->manager->is($manager));
    }

    public function test_an_employee_belongs_to_a_department(): void
    {
        $department = $this->department();
        $employee = $this->employee(['department_id' => $department->id]);

        $this->assertTrue($employee->department->is($department));
        $this->assertTrue($department->employees->first()->is($employee));
    }

    /**
     * Losing the staff file to a mis-clicked department would be
     * unrecoverable. A department is a label on a person, not a container
     * holding them.
     */
    public function test_deleting_a_department_does_not_delete_its_employees(): void
    {
        $department = $this->department();
        $employee = $this->employee(['department_id' => $department->id]);

        $department->delete();

        $this->assertNotNull($employee->fresh());
        $this->assertNull($employee->fresh()->department_id);
    }

    public function test_a_child_survives_its_parent_being_deleted(): void
    {
        $parent = $this->department(['name' => 'Operations']);
        $child = $this->department(['name' => 'Logistics', 'parent_id' => $parent->id]);

        $parent->delete();

        $this->assertNotNull($child->fresh());
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_an_archived_department_is_out_of_the_active_list(): void
    {
        $this->department(['name' => 'Finance']);
        $this->department(['name' => 'Typing pool', 'is_active' => false]);

        $this->assertSame(2, Department::query()->count());
        $this->assertSame(1, Department::active()->count());
    }

    protected function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'first_name' => 'Aïcha',
            'last_name' => 'Njoya',
            'status' => 'active',
        ], $attributes));
    }
}
```

- [ ] **Step 3: Run it — expect failure**

```bash
export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:$PATH"
php artisan test --filter=DepartmentTest
```

Expected: FAIL, `Class "App\Models\Department" not found`

- [ ] **Step 4: The migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // A child outlives its parent and falls to the root. Deleting
            // "Operations" must not silently take Logistics with it.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('departments')->nullOnDelete();

            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();

            $table->foreignId('manager_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
```

**Note on the unique key:** `company_id` is a 26-character ULID and `name` is a
`varchar(255)`. Under `utf8mb4` that is 26 + 1020 = 1046 bytes, well inside
MySQL's 3072-byte limit. Confirm anyway with `php artisan opes:export-schema`
before committing — SQLite will accept a key MySQL refuses, and has done before.

- [ ] **Step 5: The model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A department: the org unit a person works in and a document is filed under.
 *
 * Core rather than a module. A business that switches HR off still has
 * departments, because Documents files by them and approval routing will read
 * them — tying them to HR would make a document's filing disappear when
 * somebody turns off payroll.
 */
class Department extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /** Deep org charts are a sign of a business that needs a different tool. */
    public const MAX_DEPTH = 4;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "Operations / Logistics", root first. */
    public function path(): string
    {
        $names = [$this->name];

        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            array_unshift($names, $node->name);
        }

        return implode(' / ', $names);
    }

    public function depth(): int
    {
        $depth = 1;

        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            $depth++;
        }

        return $depth;
    }

    protected static function booted(): void
    {
        static::saving(function (self $department) {
            if ($department->parent_id === null) {
                return;
            }

            if ($department->parent_id === $department->id) {
                throw new RuntimeException('A department cannot be its own parent.');
            }

            /*
             * Refused rather than tolerated. A cycle is not a strange-looking
             * org chart, it is an infinite loop in path() and in every tree
             * render built on it — the process dies rather than the page
             * looking odd.
             */
            for ($node = self::find($department->parent_id); $node !== null; $node = $node->parent) {
                if ($node->id === $department->id) {
                    throw new RuntimeException('A department cannot be moved inside its own descendant.');
                }
            }

            $parentDepth = self::find($department->parent_id)?->depth() ?? 0;

            if ($parentDepth >= self::MAX_DEPTH) {
                throw new RuntimeException(
                    'Departments may be nested '.self::MAX_DEPTH.' levels deep at most.'
                );
            }
        });
    }
}
```

- [ ] **Step 6: Add the relation to Employee**

Add to `app/Models/Employee.php`:

```php
public function department(): BelongsTo
{
    return $this->belongsTo(Department::class);
}
```

- [ ] **Step 7: Run — expect pass**

```bash
php artisan test --filter=DepartmentTest
```

Expected: PASS, 15 tests.

- [ ] **Step 8: Regenerate the schema and commit**

```bash
export PATH="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
php artisan opes:export-schema
```

```bash
git add database/migrations app/Models tests/Feature/Departments opes360-install.sql
git commit -m "Give departments a table of their own"
```

---

### Task 2: Linking departments to the records that need them

**Files:**
- Create: `database/migrations/2026_08_25_000002_link_departments_to_records.php`
- Modify: `app/Models/BusinessDocument.php`
- Test: `tests/Feature/Departments/DepartmentBackfillTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Departments;

use App\Models\BusinessDocument;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DepartmentBackfillTest extends DepartmentTestCase
{
    /** The typed-in column stays. Deleting a business's data to tidy a schema is not a migration. */
    public function test_the_original_free_text_column_survives(): void
    {
        $this->assertTrue(Schema::hasColumn('employees', 'department'));
        $this->assertTrue(Schema::hasColumn('employees', 'department_id'));
    }

    public function test_a_document_can_be_filed_under_a_department(): void
    {
        $department = $this->department();

        $paper = BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Service agreement',
            'reference' => 'DOC-'.Str::upper(Str::random(5)),
            'recipient' => 'Un Client',
            'fields' => [],
            'body' => 'The agreed terms.',
            'status' => 'draft',
            'created_by' => $this->owner->id,
            'department_id' => $department->id,
        ]);

        $this->assertTrue($paper->department->is($department));
    }

    /**
     * Filing is not editing — the same rule folder_id already lives under.
     * An issued contract must be fileable into a department without its
     * tamper hash changing, or the module is useless for the documents that
     * most need managing.
     */
    public function test_an_issued_document_can_be_filed_into_a_department(): void
    {
        $department = $this->department();

        $paper = BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Service agreement',
            'reference' => 'DOC-'.Str::upper(Str::random(5)),
            'recipient' => 'Un Client',
            'fields' => [],
            'body' => 'The agreed terms.',
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);

        $paper->forceFill([
            'status' => 'issued',
            'issued_at' => now(),
            'content_hash' => hash('sha256', $paper->canonicalPayload()),
        ])->save();

        $paper->fresh()->update(['department_id' => $department->id]);

        $this->assertFalse($paper->fresh()->isTampered());
        $this->assertSame($department->id, $paper->fresh()->department_id);
    }

    public function test_the_department_is_not_part_of_the_tamper_hash(): void
    {
        $paper = BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Service agreement',
            'reference' => 'DOC-'.Str::upper(Str::random(5)),
            'recipient' => 'Un Client',
            'fields' => [],
            'body' => 'The agreed terms.',
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);

        $this->assertStringNotContainsString('department', $paper->canonicalPayload());
    }

    public function test_a_folder_can_belong_to_a_department(): void
    {
        $this->assertTrue(Schema::hasColumn('business_document_folders', 'department_id'));
    }

    public function test_an_employee_keeps_their_department_when_it_is_deleted(): void
    {
        $department = $this->department();
        $employee = Employee::create([
            'first_name' => 'Aïcha',
            'last_name' => 'Njoya',
            'status' => 'active',
            'department' => 'Finance',
            'department_id' => $department->id,
        ]);

        $department->forceDelete();

        $this->assertSame('Finance', $employee->fresh()->department);
        $this->assertNull($employee->fresh()->department_id);
    }

    public function test_departments_are_not_a_switchable_module(): void
    {
        // Core, like users and settings. A business that turns HR off keeps
        // its departments, because Documents files by them.
        $this->assertArrayNotHasKey('departments', config('modules'));
    }
}
```

- [ ] **Step 2: Run it — expect failure**

```bash
php artisan test --filter=DepartmentBackfillTest
```

Expected: FAIL, `employees` has no column `department_id`.

- [ ] **Step 3: The migration**

```php
<?php

use App\Models\Department;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignUlid('department_id')->nullable()->after('department')
                ->constrained('departments')->nullOnDelete();

            $table->index(['company_id', 'department_id']);
        });

        Schema::table('business_documents', function (Blueprint $table) {
            $table->foreignUlid('department_id')->nullable()->after('folder_id')
                ->constrained('departments')->nullOnDelete();

            $table->index(['company_id', 'department_id']);
        });

        Schema::table('business_document_folders', function (Blueprint $table) {
            $table->foreignUlid('department_id')->nullable()->after('owner_id')
                ->constrained('departments')->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Every distinct department name already typed against an employee becomes
     * a real department, and that employee is linked to it.
     *
     * The string column is left exactly as it was. It is the business's data,
     * it is what they typed, and if this backfill guesses wrong about two
     * spellings being the same department they must be able to see that.
     */
    protected function backfill(): void
    {
        $rows = DB::table('employees')
            ->select('company_id', 'department')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $id = (string) Str::ulid();

            DB::table('departments')->insert([
                'id' => $id,
                'company_id' => $row->company_id,
                'name' => $row->department,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('employees')
                ->where('company_id', $row->company_id)
                ->where('department', $row->department)
                ->update(['department_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('business_document_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
```

- [ ] **Step 4: Add `department_id` to the document's filing allow-list**

In `app/Models/BusinessDocument.php`, inside `booted()`, extend `$mutable`:

```php
            $mutable = [
                'status', 'updated_at', 'deleted_at', 'verification_token_id',
                'voided_at', 'voided_by', 'void_reason',
                'kind', 'description', 'security', 'language', 'tags',
                'owner_id', 'expires_on', 'folder_id', 'department_id',
            ];
```

**Do not add `department_id` to `canonicalPayload()`.** Filing an issued
document must leave its hash intact; there is a test that fails if any filing
column leaks into that payload.

- [ ] **Step 5: Add the relation to BusinessDocument**

```php
public function department(): BelongsTo
{
    return $this->belongsTo(Department::class);
}
```

- [ ] **Step 6: Run — expect pass, then the regression gate**

```bash
php artisan test --filter=DepartmentBackfillTest
php artisan test --filter="DocumentGenerator|FillPreview|LetterheadDesigns|NewBusinessDocumentTemplates|PrintFidelity|Documents"
```

Both must be green.

- [ ] **Step 7: Regenerate the schema and commit**

```bash
export PATH="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
php artisan opes:export-schema
```

```bash
git add database/migrations app/Models tests/Feature/Departments opes360-install.sql
git commit -m "Link employees, documents and folders to departments"
```

---

### Task 3: Permissions and the admin screen

**Files:**
- Modify: `app/Support/Permissions.php`
- Modify: `database/seeders/RolePermissionSeeder.php`
- Create: `app/Policies/DepartmentPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Create: `app/Livewire/Business/Departments.php`
- Create: `resources/views/livewire/business/departments.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Departments/DepartmentScreenTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Departments;

use App\Livewire\Business\Departments;
use App\Models\Department;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

class DepartmentScreenTest extends DepartmentTestCase
{
    public function test_the_catalogue_carries_the_department_abilities(): void
    {
        $this->assertSame(['view', 'manage'], Permissions::CATALOGUE['Departments']);
        $this->assertTrue(Gate::has('departments.view'));
        $this->assertTrue(Gate::has('departments.manage'));
    }

    public function test_a_manager_may_run_the_department_list(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $this->assertTrue($manager->can('viewAny', Department::class));
        $this->assertTrue($manager->can('create', Department::class));
    }

    public function test_a_sales_officer_may_not(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);

        $this->assertFalse($clerk->can('create', Department::class));
    }

    public function test_the_screen_lists_the_active_departments(): void
    {
        $this->actingAs($this->owner);

        $this->department(['name' => 'Finance']);
        $this->department(['name' => 'Typing pool', 'is_active' => false]);

        Livewire::test(Departments::class)
            ->assertSee('Finance')
            ->assertDontSee('Typing pool');
    }

    public function test_the_screen_shows_archived_departments_on_request(): void
    {
        $this->actingAs($this->owner);

        $this->department(['name' => 'Typing pool', 'is_active' => false]);

        Livewire::test(Departments::class)
            ->set('showArchived', true)
            ->assertSee('Typing pool');
    }

    public function test_a_department_can_be_created_from_the_screen(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Departments::class)
            ->set('name', 'Procurement')
            ->call('save');

        $this->assertDatabaseHas('departments', [
            'company_id' => $this->company->id,
            'name' => 'Procurement',
        ]);
    }

    public function test_a_duplicate_name_is_refused_with_a_usable_message(): void
    {
        $this->actingAs($this->owner);
        $this->department(['name' => 'Finance']);

        Livewire::test(Departments::class)
            ->set('name', 'Finance')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, Department::query()->count());
    }

    public function test_a_department_is_archived_rather_than_destroyed(): void
    {
        $this->actingAs($this->owner);
        $department = $this->department();

        Livewire::test(Departments::class)
            ->call('archive', $department->id);

        $this->assertFalse($department->fresh()->is_active);
        $this->assertNotNull($department->fresh());
    }

    public function test_the_route_is_closed_to_a_cashier(): void
    {
        $this->actingAs($this->memberAt(Role::CASHIER));

        $this->get(route('departments'))->assertForbidden();
    }

    public function test_the_route_opens_for_a_manager(): void
    {
        $this->actingAs($this->memberAt(Role::MANAGER));

        $this->get(route('departments'))->assertOk();
    }
}
```

- [ ] **Step 2: Run it — expect failure**

```bash
php artisan test --filter=DepartmentScreenTest
```

Expected: FAIL, `Undefined array key "Departments"`.

- [ ] **Step 3: Extend the catalogue**

In `app/Support/Permissions.php`, add after `'Users' => [...]`:

```php
        /*
         * The org chart. Core rather than a module, and separate from
         * Employees because a department outlives the staff file: Documents
         * files by department, and approval routing will read it. A business
         * that switches HR off must not lose its filing.
         */
        'Departments' => ['view', 'manage'],
```

- [ ] **Step 4: Grant it in the seeder**

Manager gains it; the accountant and read-only see the list; nobody else.

In `database/seeders/RolePermissionSeeder.php`, add to the `manager` grants:

```php
            'Departments' => ['view', 'manage'],
```

to `accountant`:

```php
            'Departments' => ['view'],
```

and to `read-only`:

```php
            'Departments' => ['view'],
```

- [ ] **Step 5: The policy**

```php
<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DepartmentPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'departments';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'manage');
    }
}
```

Register it in `app/Providers/AuthServiceProvider.php`:

```php
        Department::class => DepartmentPolicy::class,
```

- [ ] **Step 6: The Livewire component**

```php
<?php

namespace App\Livewire\Business;

use App\Models\Department;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Departments extends Component
{
    public string $name = '';

    public string $code = '';

    public ?string $parentId = null;

    public ?string $editing = null;

    public bool $showArchived = false;

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('departments', 'name')
                    ->where('company_id', app(\App\Support\CurrentCompany::class)->id())
                    ->ignore($this->editing)
                    ->whereNull('deleted_at'),
            ],
            'code' => ['nullable', 'string', 'max:20'],
            'parentId' => ['nullable', 'string', Rule::exists('departments', 'id')],
        ];
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'update' : 'create', $this->editing
            ? Department::findOrFail($this->editing)
            : Department::class);

        $this->validate();

        Department::updateOrCreate(
            ['id' => $this->editing],
            [
                'name' => $this->name,
                'code' => $this->code ?: null,
                'parent_id' => $this->parentId,
            ],
        );

        $this->reset(['name', 'code', 'parentId', 'editing']);
    }

    public function edit(string $id): void
    {
        $department = Department::findOrFail($id);

        $this->authorize('update', $department);

        $this->editing = $department->id;
        $this->name = $department->name;
        $this->code = (string) $department->code;
        $this->parentId = $department->parent_id;
    }

    /**
     * Archived, never destroyed. A department name appears on payslips,
     * documents and approvals that have already happened, and deleting it
     * would leave that history reading "—".
     */
    public function archive(string $id): void
    {
        $department = Department::findOrFail($id);

        $this->authorize('update', $department);

        $department->update(['is_active' => false]);
    }

    public function restore(string $id): void
    {
        $department = Department::findOrFail($id);

        $this->authorize('update', $department);

        $department->update(['is_active' => true]);
    }

    public function render()
    {
        $departments = Department::query()
            ->when(! $this->showArchived, fn ($q) => $q->active())
            ->withCount('employees')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('livewire.business.departments', [
            'departments' => $departments,
        ]);
    }
}
```

- [ ] **Step 7: The view**

Reuse the existing design system exactly — `x-ui.panel`, `card`, the token
palette. No new components.

```blade
<div class="space-y-6">
    <x-ui.panel>
        <x-slot:heading>Departments</x-slot:heading>

        <form wire:submit="save" class="flex flex-wrap items-end gap-3">
            <div class="min-w-48 flex-1">
                <label for="dept-name" class="text-sm text-muted">Name</label>
                <input id="dept-name" wire:model="name" type="text" class="input w-full">
                @error('name') <p class="text-sm text-negative">{{ $message }}</p> @enderror
            </div>

            <div class="w-32">
                <label for="dept-code" class="text-sm text-muted">Code</label>
                <input id="dept-code" wire:model="code" type="text" class="input w-full">
            </div>

            <div class="w-56">
                <label for="dept-parent" class="text-sm text-muted">Sits under</label>
                <select id="dept-parent" wire:model="parentId" class="input w-full">
                    <option value="">Nothing — a top-level department</option>
                    @foreach ($departments as $option)
                        @if ($option->id !== $editing)
                            <option value="{{ $option->id }}">{{ $option->path() }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                {{ $editing ? 'Save' : 'Add department' }}
            </button>
        </form>
    </x-ui.panel>

    <x-ui.panel>
        <x-slot:heading>
            <div class="flex items-center justify-between gap-4">
                <span>{{ $showArchived ? 'All departments' : 'Active departments' }}</span>
                <label class="flex items-center gap-2 text-sm text-muted">
                    <input type="checkbox" wire:model.live="showArchived">
                    Show archived
                </label>
            </div>
        </x-slot:heading>

        @forelse ($departments as $department)
            <div class="card flex items-center justify-between gap-4">
                <div>
                    <p class="font-medium">{{ $department->path() }}</p>
                    <p class="text-sm text-muted">
                        {{ $department->employees_count }} {{ Str::plural('person', $department->employees_count) }}
                        @if ($department->code) · {{ $department->code }} @endif
                        @unless ($department->is_active) · Archived @endunless
                    </p>
                </div>

                <div class="flex gap-2">
                    <button wire:click="edit('{{ $department->id }}')" class="btn btn-ghost">Edit</button>

                    @if ($department->is_active)
                        <button wire:click="archive('{{ $department->id }}')" class="btn btn-ghost">Archive</button>
                    @else
                        <button wire:click="restore('{{ $department->id }}')" class="btn btn-ghost">Restore</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-muted">No departments yet. Add the first one above.</p>
        @endforelse
    </x-ui.panel>
</div>
```

- [ ] **Step 8: The route**

In `routes/web.php`, beside the other business settings routes:

```php
    Route::get('/business/departments', App\Livewire\Business\Departments::class)
        ->middleware('can:departments.view')
        ->name('departments');
```

- [ ] **Step 9: Run — expect pass**

```bash
php artisan test --filter=DepartmentScreenTest
```

Expected: PASS, 10 tests.

- [ ] **Step 10: Full suite, then commit**

```bash
php artisan test
```

All green — including `AuthorizationTest::test_every_seeded_permission_has_a_matching_gate`, which will now cover the two new abilities.

```bash
git add app resources routes database tests
git commit -m "Let a business keep its own list of departments"
```

---

### Task 4: Documentation

- [ ] **Step 1: `docs/API.md`** — departments are readable through the API so
      an integration can file a document under one. Add to section 21's list
      that department write access is deliberately screen-only for now.

- [ ] **Step 2: Update `docs/GAP-ANALYSIS.md`** — move departments out of
      ERP #9's "missing" list and out of the Documents blocked-prerequisite
      table.

- [ ] **Step 3: Tick 1.1 in the roadmap.**

- [ ] **Step 4: Commit**

```bash
git add docs
git commit -m "Document departments"
```

---

## Testing plan

Per-commit, not final:

| Risk | Test |
|---|---|
| A business loses its typed department names | `test_the_original_free_text_column_survives` |
| A mis-clicked delete takes the staff file | `test_deleting_a_department_does_not_delete_its_employees` |
| A cycle hangs every tree render | `test_a_department_cannot_be_moved_inside_its_own_descendant` |
| Cross-tenant leakage | `test_another_company_may_use_the_same_name` |
| Filing an issued document breaks its hash | `test_an_issued_document_can_be_filed_into_a_department` |
| Departments vanish when HR is switched off | `test_departments_are_not_a_switchable_module` |
| A seeded ability with no gate | `AuthorizationTest` (existing) |
| MySQL rejects a key SQLite accepted | `php artisan opes:export-schema` before commit |
