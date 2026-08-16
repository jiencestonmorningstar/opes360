<?php

namespace Tests\Feature\Insurance;

use App\Models\Company;
use App\Models\Contact;
use App\Models\InsurancePolicy;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PolicyWatch;
use Illuminate\Support\Str;

/**
 * One brokerage's book must be invisible to another.
 */
class InsuranceIsolationTest extends InsuranceTestCase
{
    public function test_another_companys_policies_never_cross_the_wall(): void
    {
        // A policy in this company's book, lapsed, so it would appear on any
        // watchlist that could see it.
        $ours = $this->activePolicy(['covers_to' => now()->subDays(2)->toDateString()]);

        // A second brokerage with a lapsed policy of its own.
        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'rival-'.Str::lower(Str::random(6)),
            'name' => 'Courtage Rival Sarl',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);

        $theirs = app(CurrentCompany::class)->as($other, function () use ($other, $otherOwner) {
            $client = Contact::create([
                'company_id' => $other->id,
                'type' => 'customer',
                'name' => 'Their Client',
            ]);

            $policy = $this->policies()->place([
                'holder_contact_id' => $client->id,
                'product_line' => 'property',
                'premium' => 100_000,
                'covers_from' => now()->subYear()->toDateString(),
                'covers_to' => now()->subDays(2)->toDateString(),
            ], $otherOwner);

            return $this->policies()->bind($policy, $otherOwner)->fresh();
        });

        // Back in our tenant: the register and the watch see ours and only
        // ours.
        $watch = new PolicyWatch;

        $this->assertPolicyIn($ours, $watch->lapsed());
        $this->assertPolicyNotIn($theirs, $watch->lapsed());
        $this->assertFalse(InsurancePolicy::query()->pluck('id')->contains($theirs->id));

        // And theirs really exists — the wall is the scope, not a failed
        // insert.
        $this->assertSame(
            1,
            InsurancePolicy::withoutGlobalScopes()->where('company_id', $other->id)->count(),
        );
    }
}
