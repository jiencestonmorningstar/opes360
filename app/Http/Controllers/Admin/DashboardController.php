<?php

namespace App\Http\Controllers\Admin;

use App\Models\Company;
use App\Support\PlanEntitlements;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        $companies = Company::query()->withCount('users')->get();

        $byPlan = $companies->where('account_type', 'active')->groupBy('plan');

        // A rough MRR: each active company's plan price. Good enough for a
        // dashboard total, not an invoice — real billing lives elsewhere.
        $planPrices = ['basic' => 3000, 'growth' => 9000, 'business' => 21000];
        $mrr = $companies->where('account_type', 'active')
            ->sum(fn (Company $c) => $planPrices[$c->plan] ?? 0);

        return view('admin.dashboard', [
            'stats' => [
                'total' => $companies->count(),
                'demo' => $companies->where('account_type', 'demo')->count(),
                'trial' => $companies->where('account_type', 'trial')->count(),
                'active' => $companies->where('account_type', 'active')->count(),
                'suspended' => $companies->whereNotNull('suspended_at')->count(),
                'mrr' => $mrr,
            ],
            'byPlan' => [
                'basic' => $byPlan->get('basic', collect())->count(),
                'growth' => $byPlan->get('growth', collect())->count(),
                'business' => $byPlan->get('business', collect())->count(),
            ],
            'recentCompanies' => Company::query()->latest()->limit(10)->get(),
            'plans' => PlanEntitlements::PLANS,
        ]);
    }
}
