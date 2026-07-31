<?php

namespace App\Http\Controllers\Admin;

use App\Models\Company;
use App\Models\Document;
use App\Models\PlatformAdminActivity;
use App\Support\PlanEntitlements;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Full read access to every business, by design — see the docblock on
 * PlatformAdminActivity for the accountability trade-off that comes with it.
 * Every route here is behind the 'admin' guard (routes/admin.php); nothing
 * here re-checks a business permission, because a platform admin isn't a
 * member of any business to hold one.
 */
class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = Company::query()->withCount('users')->latest();
        $status = $request->query('status');

        if ($search = trim((string) $request->query('search'))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        // 'deleted' is a separate branch, not a where() on the normal query:
        // Company uses SoftDeletes, so the default query already excludes
        // trashed rows and needs onlyTrashed() to see them at all.
        if ($status === 'deleted') {
            $query->onlyTrashed();
        } elseif (in_array($status, ['demo', 'trial', 'active'], true)) {
            $query->where('account_type', $status);
        }

        return view('admin.companies.index', [
            'companies' => $query->paginate(20)->withQueryString(),
            'search' => $search,
            'status' => $request->query('status', ''),
        ]);
    }

    public function show(Company $company)
    {
        // Full read access: no CurrentCompany::as() wrapper, no tenant scope
        // to work around — Company itself carries no scope, and this
        // controller's whole purpose is seeing across all of them.
        $company->loadCount('users');

        return view('admin.companies.show', [
            'company' => $company,
            'members' => $company->users()->withPivot(['status', 'job_title', 'joined_at'])->get(),
            'documentCount' => Document::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'recentActivity' => $company->platformAdminActivity()->with('admin')->latest()->limit(15)->get(),
            'plans' => PlanEntitlements::PLANS,
        ]);
    }

    public function suspend(Request $request, Company $company)
    {
        $company->forceFill(['suspended_at' => now()])->save();

        PlatformAdminActivity::log($request->user('admin'), 'suspended_company', $company);

        return back()->with('status', 'Company suspended — its users can no longer sign in to it.');
    }

    public function activate(Request $request, Company $company)
    {
        $company->forceFill(['suspended_at' => null])->save();

        PlatformAdminActivity::log($request->user('admin'), 'reactivated_company', $company);

        return back()->with('status', 'Company reactivated.');
    }

    public function updatePlan(Request $request, Company $company)
    {
        $data = $request->validate([
            'plan' => ['required', 'in:'.implode(',', PlanEntitlements::PLANS)],
        ]);

        $from = $company->plan;
        $company->update(['plan' => $data['plan']]);

        PlatformAdminActivity::log($request->user('admin'), 'changed_plan', $company, [
            'from' => $from,
            'to' => $data['plan'],
        ]);

        return back()->with('status', "Plan changed to {$data['plan']}.");
    }
}
