<?php

namespace App\Http\Controllers\Admin;

use App\Models\Company;
use App\Models\Document;
use App\Models\Event;
use App\Models\Form;
use App\Models\PlatformAdminActivity;
use App\Models\User;
use App\Notifications\CompanyPlanChangedNotification;
use App\Notifications\CompanyReactivatedNotification;
use App\Notifications\CompanySuspendedNotification;
use App\Support\PlanEntitlements;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;

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
        $query = Company::query()->withCount('users');
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

        $sort = $request->query('sort', 'newest');
        match ($sort) {
            'name' => $query->orderBy('name'),
            'plan' => $query->orderBy('plan'),
            'oldest' => $query->oldest(),
            default => $query->latest(),
        };

        return view('admin.companies.index', [
            'companies' => $query->paginate(20)->withQueryString(),
            'search' => $search,
            'status' => $request->query('status', ''),
            'sort' => $sort,
        ]);
    }

    public function show(Company $company)
    {
        // Full read access: no CurrentCompany::as() wrapper, no tenant scope
        // to work around — Company itself carries no scope, and this
        // controller's whole purpose is seeing across all of them.
        $company->loadCount('users');

        $scoped = fn ($model) => $model::withoutGlobalScopes()->where('company_id', $company->id);

        return view('admin.companies.show', [
            'company' => $company,
            'members' => $company->users()->withPivot(['status', 'job_title', 'joined_at'])->get(),
            'documentCount' => $scoped(Document::class)->count(),
            'recentDocuments' => $scoped(Document::class)->with('contact')->latest()->limit(8)->get(),
            'formCount' => $scoped(Form::class)->count(),
            'eventCount' => $scoped(Event::class)->count(),
            'recentActivity' => $company->platformAdminActivity()->with('admin')->latest()->limit(15)->get(),
            'plans' => PlanEntitlements::PLANS,
        ]);
    }

    public function suspend(Request $request, Company $company)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $company->forceFill(['suspended_at' => now()])->save();

        // The reason is a support note for other admins, not customer-facing
        // copy — see CompanySuspendedNotification's docblock for why it
        // isn't included in the email.
        PlatformAdminActivity::log($request->user('admin'), 'suspended_company', $company, [
            'reason' => $data['reason'] ?? null,
        ]);

        $company->owner?->notify(new CompanySuspendedNotification);

        return back()->with('status', 'Company suspended — its users can no longer sign in to it.');
    }

    public function activate(Request $request, Company $company)
    {
        $company->forceFill(['suspended_at' => null])->save();

        PlatformAdminActivity::log($request->user('admin'), 'reactivated_company', $company);

        $company->owner?->notify(new CompanyReactivatedNotification);

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

        if ($from !== $data['plan']) {
            $company->owner?->notify(new CompanyPlanChangedNotification($from, $data['plan']));
        }

        return back()->with('status', "Plan changed to {$data['plan']}.");
    }

    public function removeMember(Request $request, Company $company, User $member)
    {
        abort_unless($company->users()->whereKey($member->id)->exists(), 404);

        if ($member->id === $company->owner_id) {
            return back()->with('status', "Can't remove the account owner — change ownership from within the business first.");
        }

        $company->users()->detach($member->id);

        PlatformAdminActivity::log($request->user('admin'), 'removed_member', $company, [
            'user_id' => $member->id,
            'user_email' => $member->email,
        ]);

        return back()->with('status', "{$member->name} removed from {$company->name}.");
    }

    public function resetMemberPassword(Request $request, Company $company, User $member)
    {
        abort_unless($company->users()->whereKey($member->id)->exists(), 404);

        Password::broker('users')->sendResetLink(['email' => $member->email]);

        PlatformAdminActivity::log($request->user('admin'), 'sent_password_reset', $company, [
            'user_id' => $member->id,
            'user_email' => $member->email,
        ]);

        return back()->with('status', "Password reset link sent to {$member->email}.");
    }
}
