<?php

namespace App\Http\Controllers\Admin;

use App\Models\Company;
use App\Support\PlanEntitlements;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        // Counted and grouped in SQL rather than loading every company row
        // into PHP — this page used to do Company::query()->get() and then
        // ->where()/->count() in userland, which is fine at a few dozen
        // companies and gets slow well before a few thousand.
        $byAccountType = Company::query()
            ->selectRaw('account_type, count(*) as aggregate')
            ->groupBy('account_type')
            ->pluck('aggregate', 'account_type');

        $activeByPlan = Company::query()
            ->where('account_type', 'active')
            ->selectRaw('plan, count(*) as aggregate')
            ->groupBy('plan')
            ->pluck('aggregate', 'plan');

        // A rough MRR: each active company's plan price. Good enough for a
        // dashboard total, not an invoice — real billing lives elsewhere.
        $planPrices = ['basic' => 3000, 'growth' => 9000, 'business' => 21000];
        $mrr = collect($planPrices)->map(fn ($price, $plan) => $price * ($activeByPlan[$plan] ?? 0))->sum();

        return view('admin.dashboard', [
            'stats' => [
                'total' => $byAccountType->sum(),
                'demo' => $byAccountType->get('demo', 0),
                'trial' => $byAccountType->get('trial', 0),
                'active' => $byAccountType->get('active', 0),
                'suspended' => Company::query()->whereNotNull('suspended_at')->count(),
                'mrr' => $mrr,
            ],
            'byPlan' => [
                'basic' => $activeByPlan->get('basic', 0),
                'growth' => $activeByPlan->get('growth', 0),
                'business' => $activeByPlan->get('business', 0),
            ],
            'recentCompanies' => Company::query()->latest()->limit(10)->get(),
            'plans' => PlanEntitlements::PLANS,
        ]);
    }
}
