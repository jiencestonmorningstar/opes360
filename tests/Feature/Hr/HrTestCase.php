<?php

namespace Tests\Feature\Hr;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class HrTestCase extends TestCase
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

    protected function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'first_name' => 'Aïcha',
            'last_name' => 'Njoya',
            'status' => 'active',
        ], $attributes));
    }

    protected function position(array $attributes = []): Position
    {
        return Position::create(array_merge([
            'title' => 'Accountant',
            'is_active' => true,
            'sort_order' => 0,
        ], $attributes));
    }

    protected function attendance(Employee $employee, array $attributes = []): AttendanceRecord
    {
        return AttendanceRecord::create(array_merge([
            'employee_id' => $employee->id,
            'worked_on' => '2026-09-10',
            'status' => 'present',
            'source' => 'manual',
        ], $attributes));
    }

    protected function review(Employee $employee, array $attributes = []): PerformanceReview
    {
        return PerformanceReview::create(array_merge([
            'employee_id' => $employee->id,
            'period_starts_on' => '2026-01-01',
            'period_ends_on' => '2026-12-31',
            'cycle' => 'annual',
            'status' => 'draft',
        ], $attributes));
    }
}
