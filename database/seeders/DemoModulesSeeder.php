<?php

namespace Database\Seeders;

use App\Models\AssetLocation;
use App\Models\AssetMaintenance;
use App\Models\Company;
use App\Models\ComplianceObligation;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\FixedAsset;
use App\Models\Lead;
use App\Models\PurchaseRequisition;
use App\Models\Risk;
use App\Models\ServiceSlaPolicy;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\Assets\AssetMovements;
use App\Services\Compliance\RiskRegister;
use App\Services\Contracts\ContractLifecycle;
use App\Services\LeadFunnel;
use App\Services\Procurement\Requisitions;
use App\Services\Service\TicketDesk;
use App\Support\CurrentCompany;
use App\Support\DefaultWorkflows;
use App\Support\Modules;
use Illuminate\Database\Seeder;

/**
 * Fills a business with the situations the newer screens exist to surface.
 *
 * Not "one row per table so nothing looks empty" — each module gets the
 * specific state its screen was designed around, because a contract list with
 * three healthy contracts demonstrates nothing, and a contract whose notice
 * deadline passed last week while it renews itself is the entire reason the
 * watch screen exists. Someone opening each screen should immediately see
 * what it is for.
 *
 * Everything goes through the same services the screens call, so this doubles
 * as an end-to-end exercise of the real paths — a seeder that wrote rows
 * directly could paint states the product cannot actually reach. The one
 * exception is backdating (an SLA breach needs a ticket opened in the past),
 * which no service offers because no real path opens a ticket yesterday.
 */
class DemoModulesSeeder extends Seeder
{
    protected Company $company;

    protected User $owner;

    public function forCompany(Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function run(): void
    {
        $company = $this->company ?? Company::query()->firstOrFail();

        app(CurrentCompany::class)->as($company, function () use ($company) {
            $this->company = $company;
            $this->owner = User::findOrFail($company->owner_id);

            // The paths first: several of the demos submit for approval, and
            // a business without workflows would refuse every one of them.
            DefaultWorkflows::seed($company);

            // Payables, procurement and the service desk ship off. A demo of
            // a screen nobody can reach demonstrates nothing.
            $this->enableModules(['payables', 'procurement', 'service']);

            $this->contracts();
            $this->leads();
            $this->compliance();
            $this->risks();
            $this->serviceDesk();
            $this->procurement();
            $this->assetMovements();
        });
    }

    protected function enableModules(array $keys): void
    {
        $settings = (array) ($this->company->modules ?? []);

        foreach ($keys as $key) {
            $settings[$key] = true;
        }

        $this->company->forceFill(['modules' => $settings])->save();
        Modules::flush();
    }

    protected function supplier(string $name): Contact
    {
        return Contact::firstOrCreate(
            ['name' => $name],
            ['type' => 'supplier', 'email' => str($name)->slug().'@example.test']
        );
    }

    protected function customer(string $name): Contact
    {
        return Contact::firstOrCreate(
            ['name' => $name],
            ['type' => 'customer', 'email' => str($name)->slug().'@example.test']
        );
    }

    /**
     * Three contracts, one per watch list: healthy, deadline coming, and the
     * alarm case — auto-renewing with the notice date already gone.
     */
    protected function contracts(): void
    {
        if (Contract::query()->exists()) {
            return; // Demonstrated once; an interrupted run must not pile up.
        }

        $lifecycle = app(ContractLifecycle::class);

        $raise = function (array $data) use ($lifecycle) {
            $contract = $lifecycle->raise($data, $this->owner);
            // Activated directly rather than through the approval, because
            // the watch only watches running contracts and this demo is
            // about the notice dates, not the submission flow — the
            // requisitions below demonstrate that.
            $lifecycle->activate($contract, $this->owner);

            return $contract;
        };

        // Healthy: ends most of a year from now, notice window far off.
        $raise([
            'title' => 'Office cleaning — Propreté Plus',
            'type' => 'service',
            'direction' => 'inbound',
            'counterparty_id' => $this->supplier('Propreté Plus Sarl')->id,
            'value' => 1200000,
            'starts_on' => now()->subMonths(2)->toDateString(),
            'ends_on' => now()->addMonths(10)->toDateString(),
            'renewal_type' => 'auto',
            'renewal_term_months' => 12,
            'notice_period_days' => 60,
        ]);

        // Notice deadline a fortnight away: still actionable, which is what
        // the "deadline coming" list is for.
        $raise([
            'title' => 'Internet dédié — CamTel Pro',
            'type' => 'service',
            'direction' => 'inbound',
            'counterparty_id' => $this->supplier('CamTel Pro')->id,
            'value' => 3600000,
            'starts_on' => now()->subMonths(11)->toDateString(),
            'ends_on' => now()->addDays(75)->toDateString(),
            'renewal_type' => 'auto',
            'renewal_term_months' => 12,
            'notice_period_days' => 60,
        ]);

        /*
         * The alarm: renews itself, and the last day to say no went by ten
         * days ago. This is the case that costs real money by being ignored,
         * and the watch screen's red block should never be demonstrated
         * empty — an empty alarm teaches people not to look at it.
         */
        $raise([
            'title' => 'Photocopieurs — location Bureautec',
            'type' => 'lease',
            'direction' => 'inbound',
            'counterparty_id' => $this->supplier('Bureautec Location')->id,
            'value' => 2400000,
            'starts_on' => now()->subMonths(11)->toDateString(),
            'ends_on' => now()->addDays(20)->toDateString(),
            'renewal_type' => 'auto',
            'renewal_term_months' => 12,
            'notice_period_days' => 30,
        ]);
    }

    /**
     * The funnel's states: a fresh enquiry, one being worked, one converted
     * into a real customer and deal, and one lost with its reason kept —
     * because "why do we lose work" is the question the register answers.
     */
    protected function leads(): void
    {
        if (Lead::query()->exists()) {
            return;
        }

        $funnel = app(LeadFunnel::class);

        $funnel->create([
            'name' => 'Mme Ngo Bassa',
            'company_name' => 'Restaurant Le Wouri',
            'phone' => '+237 699 11 22 33',
            'source' => 'walk_in',
            'notes' => 'Veut des menus plastifiés et des cartes de fidélité.',
        ], $this->owner);

        $working = $funnel->create([
            'name' => 'M. Talla',
            'company_name' => 'Collège Bilingue Excellence',
            'phone' => '+237 677 44 55 66',
            'source' => 'referral',
            'notes' => 'Impression des bulletins — volume à chiffrer.',
        ], $this->owner);
        $funnel->moveTo($working, 'working');

        $won = $funnel->create([
            'name' => 'Dr Mbarga',
            'company_name' => 'Clinique des Palmiers',
            'email' => 'direction@palmiers.example.test',
            'source' => 'website',
        ], $this->owner);
        $funnel->moveTo($won, 'qualified');
        $funnel->convert($won->fresh(), $this->owner, [
            'title' => 'Signalétique clinique — 3 étages',
            'value' => 1850000,
        ]);

        $lost = $funnel->create([
            'name' => 'M. Fokou',
            'company_name' => 'Quincaillerie Centrale',
            'source' => 'phone',
        ], $this->owner);
        $funnel->lose($lost, 'Parti chez un concurrent moins cher.');
    }

    /**
     * The calendar's three states: due soon, overdue, and the completion-
     * based licence that contrasts with the authority-set deadlines.
     *
     * Written to the model because defining an obligation has no service
     * method — the screens create them the same way.
     */
    protected function compliance(): void
    {
        $define = fn (array $attributes) => ComplianceObligation::firstOrCreate(
            ['name' => $attributes['name']],
            $attributes + ['is_active' => true, 'created_by' => $this->owner->id]
        );

        $define([
            'name' => 'Déclaration TVA mensuelle',
            'authority' => 'DGI',
            'category' => 'tax',
            'interval_months' => 1,
            'schedule_basis' => 'due',
            'next_due_on' => now()->addDays(12)->toDateString(),
        ]);

        // Overdue: the count at the top of the screen, in red. A compliance
        // calendar demonstrated with nothing overdue looks like decoration.
        $define([
            'name' => 'Déclaration CNPS — cotisations',
            'authority' => 'CNPS',
            'category' => 'social',
            'interval_months' => 1,
            'schedule_basis' => 'due',
            'next_due_on' => now()->subDays(9)->toDateString(),
        ]);

        // Completion-based: a licence runs a year from its renewal, however
        // late that renewal happened. The contrast between the two bases is
        // the thing worth demonstrating.
        $define([
            'name' => 'Patente — renouvellement',
            'authority' => 'Commune de Douala',
            'category' => 'licence',
            'interval_months' => 12,
            'schedule_basis' => 'completion',
            'next_due_on' => now()->addMonths(4)->toDateString(),
        ]);
    }

    /** Two risks: one reassessed downward after a control, one past review. */
    protected function risks(): void
    {
        if (Risk::query()->exists()) {
            return;
        }

        $register = app(RiskRegister::class);

        $fire = $register->record([
            'title' => 'Incendie atelier — stock papier',
            'category' => 'operational',
            'likelihood' => 3,
            'impact' => 5,
            'next_review_on' => now()->addMonths(5)->toDateString(),
        ], $this->owner);

        $extinguishers = $register->addControl($fire, [
            'title' => 'Extincteurs contrôlés',
            'description' => 'Contrat de vérification semestrielle, dernier passage le mois dernier.',
            'kind' => 'preventive',
        ], $this->owner);
        $register->markControlInPlace($extinguishers);

        // Reassessed by a person, deliberately — the score never moves just
        // because a control was typed in.
        $register->reassess($fire->fresh(), 2, 4);

        // Past its review date: feeds the "needs looking at" tab.
        $register->record([
            'title' => 'Dépendance fournisseur unique — encres',
            'category' => 'operational',
            'likelihood' => 4,
            'impact' => 3,
            'next_review_on' => now()->subMonth()->toDateString(),
        ], $this->owner);
    }

    /**
     * A policy with real targets, then three tickets: resolved inside its
     * promise, waiting on the customer with the clock stopped, and one past
     * its response deadline — the board's red row.
     */
    protected function serviceDesk(): void
    {
        $desk = app(TicketDesk::class);

        // updateOrCreate, not firstOrCreate: a policy left half-made by an
        // earlier interrupted run would otherwise be found and kept, and a
        // calendar that never opens refuses every ticket.
        $policy = ServiceSlaPolicy::updateOrCreate(
            ['name' => 'Standard'],
            [
                'timezone' => 'Africa/Douala',
                'clock' => 'business',
                'is_default' => true,
                'is_active' => true,
                // Half-day Saturday, because that is how a Douala workshop
                // actually opens — and a calendar that never opens computes
                // no deadline at all, by design.
                'business_hours' => [
                    'mon' => [['08:00', '17:30']],
                    'tue' => [['08:00', '17:30']],
                    'wed' => [['08:00', '17:30']],
                    'thu' => [['08:00', '17:30']],
                    'fri' => [['08:00', '17:30']],
                    'sat' => [['08:00', '13:00']],
                ],
                'holidays' => [],
            ]
        );

        if ($policy->targets()->count() === 0) {
            foreach ([
                ['urgent', 2 * 60, 8 * 60],
                ['high', 4 * 60, 16 * 60],
                ['normal', 8 * 60, 40 * 60],
            ] as [$priority, $respond, $resolve]) {
                $policy->targets()->create([
                    'company_id' => $this->company->id,
                    'priority' => $priority,
                    'response_minutes' => $respond,
                    'resolution_minutes' => $resolve,
                ]);
            }
        }

        if (ServiceTicket::query()->exists()) {
            return; // Already demonstrated; do not pile up breaches.
        }

        $resolved = $desk->open([
            'contact_id' => $this->customer('Boulangerie du Rond-Point')->id,
            'subject' => 'Imprimante caisse ne répond plus',
            'description' => 'Plus de tickets de caisse depuis ce matin.',
            'priority' => 'high',
            'channel' => 'phone',
        ], $this->owner);
        $desk->recordResponse($resolved, $this->owner);
        $desk->resolve($resolved, $this->owner, 'Câble réseau débranché — reconnecté à distance.');

        $waiting = $desk->open([
            'contact_id' => $this->customer('Pharmacie Santa Lucia')->id,
            'subject' => 'Devis maintenance climatisation serveur',
            'description' => 'Demande de contrat d\'entretien trimestriel.',
            'priority' => 'normal',
            'channel' => 'email',
        ], $this->owner);
        $desk->recordResponse($waiting, $this->owner);
        $desk->waitOnCustomer($waiting, $this->owner, 'En attente du bon de commande client.');

        /*
         * The breach: opened two days ago at urgent priority and never
         * answered, so the board has something honest to be red about. The
         * deadlines are shifted with the opening instant, exactly as they
         * would stand had the ticket really been opened then.
         */
        $breached = $desk->open([
            'contact_id' => $this->customer('Hôtel Akwa Palace')->id,
            'subject' => 'Groupe électrogène en panne — réception',
            'description' => 'Le groupe ne démarre plus, réception sans courant ce soir.',
            'priority' => 'urgent',
            'channel' => 'phone',
        ], $this->owner);

        // Each instant moves back by the same two days, so the deadlines
        // stand exactly as they would had the ticket really been opened
        // then. (Not diffInSeconds arithmetic: Carbon 3 returns it signed,
        // and a negative shift quietly moved the ticket into the future.)
        $breached->forceFill([
            'opened_at' => $breached->opened_at->copy()->subDays(2),
            'response_due_at' => $breached->response_due_at?->copy()->subDays(2),
            'resolution_due_at' => $breached->resolution_due_at?->copy()->subDays(2),
        ])->save();
    }

    /**
     * Two requisitions: one under the threshold that sailed through, one
     * over it now sitting in the owner's inbox — the pair demonstrates the
     * threshold better than any sentence in a guide could.
     */
    protected function procurement(): void
    {
        if (PurchaseRequisition::query()->exists()) {
            return;
        }

        $requisitions = app(Requisitions::class);
        $threshold = DefaultWorkflows::thresholdFor($this->company);

        $small = $requisitions->create([
            'title' => 'Papier A4 et toners',
            'needed_by' => now()->addWeek()->toDateString(),
            'lines' => [
                ['description' => 'Cartons papier A4 80g', 'quantity' => 10, 'estimated_unit_price' => 22000],
                ['description' => 'Toner HP 26A', 'quantity' => 4, 'estimated_unit_price' => 45000],
            ],
        ], $this->owner);
        $requisitions->submit($small->fresh(), actor: $this->owner);

        $large = $requisitions->create([
            'title' => 'Massicot électrique — atelier',
            'needed_by' => now()->addMonth()->toDateString(),
            'lines' => [
                ['description' => 'Massicot électrique 520mm', 'quantity' => 1, 'estimated_unit_price' => $threshold + 250000],
            ],
        ], $this->owner);
        $requisitions->submit($large->fresh(), actor: $this->owner);
    }

    /** A site, one recorded move, and servicing overdue enough to show. */
    protected function assetMovements(): void
    {
        $asset = FixedAsset::query()->where('status', 'active')->first();

        if ($asset === null) {
            return; // A business with no register has nothing to move.
        }

        $depot = AssetLocation::firstOrCreate(
            ['name' => 'Atelier Bonabéri'],
            ['is_active' => true]
        );

        if ($asset->asset_location_id !== $depot->id) {
            app(AssetMovements::class)->transfer($asset, [
                'to_location_id' => $depot->id,
                'reason' => 'Rapproché de la production',
            ], userId: $this->owner->id);
        }

        AssetMaintenance::firstOrCreate(
            ['fixed_asset_id' => $asset->id, 'title' => 'Révision annuelle'],
            [
                'kind' => 'service',
                'due_on' => now()->subWeeks(2)->toDateString(),
                'interval_months' => 12,
                'created_by' => $this->owner->id,
            ]
        );
    }
}
