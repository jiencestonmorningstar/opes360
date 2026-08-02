<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PartnerClient;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\Partners\PartnerProgramme;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A worked example of the secretariat programme.
 *
 * Without this the feature is invisible: a plain business account cannot reach
 * any of it, so the only way to see a client book or an earnings page was to
 * build one by hand. This seeds a print shop in Douala with the clients it
 * serves, a few cards already issued against it, and one client that has since
 * signed up — so the earnings page has commission on it rather than three zeros.
 */
class DemoSecretariatSeeder extends Seeder
{
    public function run(): void
    {
        if (Company::query()->where('kind', 'secretariat')->exists()) {
            return;
        }

        $owner = User::firstOrCreate(
            ['email' => 'secretariat@opesware.com'],
            [
                'name' => 'Alice Manga',
                'password' => Hash::make(config('opes.demo.password', 'password')),
                'phone' => '+237 6 70 41 62 38',
            ],
        );

        $owner->forceFill(['email_verified_at' => now()])->save();

        $partner = Company::create([
            'slug' => 'secretariat-bonamoussadi',
            'name' => 'Secrétariat Bonamoussadi',
            'motto' => 'Impression, bureautique et infographie',
            'industry' => 'Professional Services',
            'city' => 'Douala',
            'country' => 'CM',
            'currency' => 'XAF',
            'owner_id' => $owner->id,
            'account_type' => 'active',
            'plan' => 'growth',
            'kind' => 'secretariat',
            'email' => 'secretariat@opesware.com',
            'phones' => ['+237 6 70 41 62 38'],
        ]);

        $partner->users()->attach($owner->id, [
            'role_id' => Role::where('slug', Role::OWNER)->value('id'),
            'job_title' => 'Proprietor',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $owner->forceFill(['current_company_id' => $partner->id])->save();

        app(CurrentCompany::class)->as($partner, function () use ($partner, $owner) {
            ChartOfAccounts::seed($partner);

            VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => Company::class,
                'subject_id' => $partner->id,
            ]);

            $programme = app(PartnerProgramme::class);

            $clients = collect([
                ['Boulangerie Nkolbisson', 'Marie Nkolo', 'Food & Beverage', 'Yaoundé'],
                ['Garage Akwa', 'Paul Etoa', 'Transport & Logistics', 'Douala'],
                ['Coiffure Bépanda', 'Sylvie Ngo', 'Beauty & Wellness', 'Douala'],
                ['Pharmacie Bonapriso', 'Dr Ateba', 'Healthcare', 'Douala'],
                ['Ets. Mbarga & Fils', 'Jean Mbarga', 'Construction', 'Douala'],
            ])->map(fn (array $row) => PartnerClient::create([
                'name' => $row[0],
                'contact_name' => $row[1],
                'industry' => $row[2],
                'city' => $row[3],
                'phone' => '+237 6 '.random_int(50, 99).' '.random_int(10, 99).' '.random_int(10, 99).' '.random_int(10, 99),
            ]));

            // Cards already printed, so the earnings page has fees to net off
            // rather than only commission.
            foreach ([['classic', 2], ['azure', 1], ['auto-01', 3]] as [$design, $count]) {
                for ($i = 0; $i < $count; $i++) {
                    $programme->recordIssuance(
                        $partner, 'card', $design,
                        $clients->random()->name,
                        $clients->random(),
                        $owner,
                    );
                }
            }
        });
    }
}
