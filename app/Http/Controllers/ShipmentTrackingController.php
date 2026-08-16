<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AbortsForSuspendedCompany;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\Shipment;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Illuminate\Routing\Controller;

/**
 * Public shipment tracking — the link on the customer's waybill.
 *
 * Same tenancy rule as verification, forms and walk-in triage: the visitor is
 * not a user, the token names the shipment, the shipment names the company,
 * and nothing is ever resolved across tenants. The page shows the status
 * history and the shipment's own route — and deliberately nothing else. No
 * other cargo, no manifest, no vehicle, no money, no names beyond the
 * shipment's own two parties.
 */
class ShipmentTrackingController extends Controller
{
    use AbortsForSuspendedCompany;

    public function show(string $token)
    {
        $shipment = Shipment::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('tracking_token', $token)
            ->first();

        if ($shipment === null) {
            abort(404);
        }

        $company = Company::find($shipment->company_id);

        if ($company === null) {
            abort(404);
        }

        $this->abortIfSuspended($company);

        // A business that has switched transport off has taken its tracking
        // pages down with it — a live link to a dead module must 404, not
        // keep answering into a void.
        if (Modules::exists('logistics') && ! Modules::enabled($company, 'logistics')) {
            abort(404);
        }

        return app(CurrentCompany::class)->as($company, function () use ($company, $shipment) {
            return view('public.track', [
                'company' => $company,
                'shipment' => $shipment,
                'events' => $shipment->events()->get(),
            ]);
        });
    }
}
