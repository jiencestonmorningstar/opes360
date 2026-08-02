<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\Item;
use App\Models\LedgerAccount;
use App\Models\PartnerClient;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\Assets\AssetRegister;
use App\Services\Banking\Reconciler;
use App\Services\Partners\PartnerProgramme;
use App\Services\Payroll\PayrollRunner;
use App\Services\Stock\LocationLedger;
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
            // Top plan, like the other demo: payroll is a Business-tier module
            // and this is the account whose staff, payslips and asset register
            // exist to show it working. A Growth-plan showcase would seed four
            // employees and a paid payroll run behind a 403.
            'plan' => 'business',
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

            $this->seedTeam($partner, $owner);
            $this->seedAssets($partner, $owner);
            $this->seedBanking($partner);
            $this->seedStockLocations($partner);
        });
    }

    /**
     * A press, a laminator and a van, part-way through their lives.
     *
     * Seeded with a year of depreciation already charged rather than bought
     * yesterday, because the interesting figure is the gap between cost and
     * book value and an asset register full of brand-new things does not show
     * it.
     */
    protected function seedAssets(Company $partner, User $owner): void
    {
        $register = app(AssetRegister::class);
        $boughtOn = now()->subYear()->startOfMonth();

        $assets = [
            ['Presse offset Heidelberg', 'equipment', 6500000, 96],
            ['Plastifieuse A3', 'equipment', 450000, 60],
            ['Toyota Hilux — livraisons', 'vehicles', 8200000, 48],
            ['Mobilier accueil', 'furniture', 380000, 120],
            ['Terrain Bonamoussadi', 'land', 12000000, 0],
        ];

        foreach ($assets as [$name, $category, $cost, $life]) {
            $asset = $register->record([
                'name' => $name,
                'category' => $category,
                'acquired_on' => $boughtOn->toDateString(),
                'cost' => $cost,
                'useful_life_months' => $life,
                'funded_by' => 'bank',
            ], $owner);

            // Twelve months of charges, so the register shows wear rather than
            // a list of things bought this morning.
            for ($i = 0; $i < 12; $i++) {
                $register->depreciate(
                    $asset->refresh(),
                    $boughtOn->copy()->addMonths($i)->toDateString(),
                    $owner,
                );
            }
        }
    }

    /**
     * A bank account with a statement that does not quite agree with the books.
     *
     * Two of the four lines match entries the business made; the other two are
     * a bank charge and a credit interest nobody recorded, which is exactly the
     * case the reconciliation exists for.
     */
    protected function seedBanking(Company $partner): void
    {
        $account = BankAccount::create([
            'company_id' => $partner->id,
            'name' => 'Compte courant',
            'bank_name' => 'UBA Cameroun',
            'account_number' => '10033 05001 07834521001 62',
            'currency' => 'XAF',
            'ledger_account_id' => LedgerAccount::query()->where('number', '521')->value('id'),
            // Everything up to the end of last month was already agreed; this
            // month is what is open. Without a starting point every movement
            // the business ever posted would count as unmatched, which is
            // exactly the state that makes a first reconciliation impossible.
            'opening_balance' => 4250000,
            'opened_on' => now()->subMonth()->endOfMonth()->toDateString(),
            'is_default' => true,
            'active' => true,
        ]);

        $month = now()->startOfMonth();

        app(Reconciler::class)->import($account, [
            ['value_date' => $month->copy()->addDays(3)->toDateString(), 'description' => 'Virement Ets. Mbarga & Fils', 'amount' => 450000, 'reference' => 'VIR-77201'],
            ['value_date' => $month->copy()->addDays(9)->toDateString(), 'description' => 'Frais de tenue de compte', 'amount' => -7500, 'reference' => 'FRAIS'],
            ['value_date' => $month->copy()->addDays(14)->toDateString(), 'description' => 'Chèque n° 004112 — fournisseur papier', 'amount' => -1250000, 'reference' => 'CHQ4112'],
            ['value_date' => $month->copy()->addDays(21)->toDateString(), 'description' => 'Intérêts créditeurs', 'amount' => 3100, 'reference' => 'INT'],
        ]);

        // What the statement would say if the four lines above were the only
        // movements — so the demo opens on "nothing unexplained, four lines to
        // match" rather than on an alarming red figure.
        $summary = app(Reconciler::class)->summary($account->fresh());

        $account->forceFill([
            'statement_balance' => round(
                $summary['book_balance'] + $summary['unmatched_in'] - $summary['unmatched_out'] - $summary['unmatched_book'],
                2
            ),
            'statement_date' => now()->endOfMonth()->toDateString(),
        ])->save();
    }

    /** A counter and a store room, with stock split between them. */
    protected function seedStockLocations(Company $partner): void
    {
        $counter = StockLocation::create([
            'company_id' => $partner->id,
            'name' => 'Comptoir', 'code' => 'CPT', 'kind' => 'shop',
            'city' => 'Douala', 'is_default' => true, 'active' => true,
        ]);

        $store = StockLocation::create([
            'company_id' => $partner->id,
            'name' => 'Réserve', 'code' => 'RES', 'kind' => 'warehouse',
            'city' => 'Douala', 'active' => true,
        ]);

        $supplies = [
            ['Papier A4 80g (rame)', 'PAP-A4', 3500, 40, 260],
            ['Papier photo A3 (paquet)', 'PAP-A3', 12000, 8, 45],
            ['Cartouche encre noire', 'ENC-NR', 28000, 6, 22],
            ['Pochette plastification A4', 'PLA-A4', 9000, 12, 60],
        ];

        $ledger = app(LocationLedger::class);

        foreach ($supplies as [$name, $sku, $price, $atCounter, $inStore]) {
            $item = Item::create([
                'company_id' => $partner->id,
                'name' => $name,
                'sku' => $sku,
                'type' => 'product',
                'price' => $price,
                'track_stock' => true,
                'is_active' => true,
            ]);

            $ledger->adjust($item, $counter, $atCounter, 'opening');
            $ledger->adjust($item, $store, $inStore, 'opening');
        }

        // One restock, so the transfer list is not an empty panel.
        $ledger->transfer(
            $store,
            $counter,
            [['item_id' => Item::query()->where('sku', 'PAP-A4')->value('id'), 'quantity' => 20]],
            null,
            now()->subDays(3)->toDateString(),
            'Réassort comptoir',
        );
    }

    /**
     * Four staff and last month's payroll, run and paid.
     *
     * This lives on the secretariat rather than the main demo company because
     * the payroll is Cameroonian — CNPS, IRPP, centimes additionnels — and the
     * other demo business trades from Lagos in dollars. Showing a Douala print
     * shop's payslips is the honest version; showing the same deductions
     * against a Nigerian company would be a demo that teaches the wrong thing.
     */
    protected function seedTeam(Company $partner, User $owner): void
    {
        $partner->forceFill([
            'cnps_employer_number' => '9-01-2019-0004821',
            // A print shop is a workshop, not an office: group B.
            'cnps_risk_group' => 'b',
            'cnps_family_regime' => 'general',
        ])->save();

        $hired = now()->subYears(2)->startOfMonth();

        $staff = [
            ['Sylvie', 'Ekwalla', 'Infographiste', 185000, 'mobile_money'],
            ['Bertrand', 'Mballa', 'Opérateur PAO', 140000, 'cash'],
            ['Nadège', 'Fotso', 'Accueil et caisse', 95000, 'cash'],
            ['Ibrahim', 'Sali', 'Coursier', 70000, 'cash'],
        ];

        foreach ($staff as $index => [$first, $last, $title, $salary, $method]) {
            $employee = Employee::create([
                'company_id' => $partner->id,
                'number' => 'SB-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'first_name' => $first,
                'last_name' => $last,
                'job_title' => $title,
                'phone' => '+237 6 '.random_int(50, 99).' '.random_int(10, 99).' '.random_int(10, 99).' '.random_int(10, 99),
                'cnps_number' => (string) random_int(100000000, 999999999),
                'hired_on' => $hired->copy()->addMonths($index * 3)->toDateString(),
                'status' => 'active',
                'payment_method' => $method,
                'created_by' => $owner->id,
            ]);

            EmploymentContract::create([
                'company_id' => $partner->id,
                'employee_id' => $employee->id,
                'type' => $index === 3 ? 'cdd' : 'cdi',
                'job_title' => $title,
                'starts_on' => $employee->hired_on->toDateString(),
                'ends_on' => $index === 3 ? now()->addMonths(4)->toDateString() : null,
                'base_salary' => $salary,
                'currency' => 'XAF',
                'status' => 'active',
            ]);

            // A transport allowance outside both bases, which is the case that
            // makes the three-base distinction visible on a payslip.
            if ($index < 2) {
                SalaryComponent::create([
                    'company_id' => $partner->id,
                    'employee_id' => $employee->id,
                    'name' => 'Prime de transport',
                    'kind' => 'allowance',
                    'amount' => 20000,
                    'taxable' => false,
                    'cnps_liable' => false,
                    'active' => true,
                ]);
            }
        }

        $runner = app(PayrollRunner::class);
        $lastMonth = now()->subMonth()->startOfMonth();

        $run = $runner->build($runner->open($lastMonth->toDateString(), $owner), $owner);
        $run = $runner->approve($run, $owner);

        $runner->markPaid($run, [
            'method' => 'bank',
            'paid_on' => $lastMonth->copy()->endOfMonth()->toDateString(),
            'reference' => 'VIR-'.$lastMonth->format('Ym'),
        ], $owner);
    }
}
