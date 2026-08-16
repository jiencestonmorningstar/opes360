<?php

namespace Tests\Feature\Service;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\ServiceSlaPolicy;
use App\Models\ServiceSlaTarget;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class ServiceTestCase extends TestCase
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

    protected function memberAt(string $role): User
    {
        $user = User::factory()->create();

        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    protected function customer(string $name = 'A Customer'): Contact
    {
        return Contact::create(['name' => $name, 'balance' => 0]);
    }

    /**
     * Monday to Friday, 08:00–17:00, with a default target of two hours to
     * respond and eight to resolve. Deliberately not 24/7: the whole point of
     * the clock is what it does outside working hours.
     */
    protected function policy(array $attributes = [], array $targets = []): ServiceSlaPolicy
    {
        $policy = ServiceSlaPolicy::create(array_merge([
            'name' => 'Standard support',
            'is_default' => true,
            'clock' => 'business',
            'timezone' => 'UTC',
            'business_hours' => [
                'mon' => [['08:00', '17:00']],
                'tue' => [['08:00', '17:00']],
                'wed' => [['08:00', '17:00']],
                'thu' => [['08:00', '17:00']],
                'fri' => [['08:00', '17:00']],
            ],
            'holidays' => [],
        ], $attributes));

        $targets = $targets ?: [
            'urgent' => [30, 4 * 60],
            'high' => [60, 8 * 60],
            'normal' => [120, 16 * 60],
            'low' => [240, 40 * 60],
        ];

        foreach ($targets as $priority => [$response, $resolution]) {
            ServiceSlaTarget::create([
                'sla_policy_id' => $policy->id,
                'priority' => $priority,
                'response_minutes' => $response,
                'resolution_minutes' => $resolution,
            ]);
        }

        return $policy->refresh();
    }
}
