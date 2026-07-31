<?php

namespace App\Http\Controllers\Admin;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Every admin action across every business, in one place — the per-company
 * activity list on CompanyController::show() only answers "what happened to
 * this business"; this answers "what has admin staff been doing".
 */
class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $query = PlatformAdminActivity::query()->with('admin')->latest();

        $adminId = $request->query('admin');
        $action = $request->query('action');

        if ($adminId) {
            $query->where('platform_admin_id', $adminId);
        }

        if ($action) {
            $query->where('action', $action);
        }

        $activity = $query->paginate(30)->withQueryString();

        // Companies are looked up separately rather than via a polymorphic
        // relation load, since Company needs withTrashed() to resolve a
        // deleted business's name too.
        $companyIds = $activity->getCollection()
            ->where('subject_type', Company::class)
            ->pluck('subject_id')
            ->filter()
            ->unique();

        $companies = Company::withTrashed()->whereIn('id', $companyIds)->get()->keyBy('id');

        return view('admin.activity.index', [
            'activity' => $activity,
            'companies' => $companies,
            // Every admin who has EVER logged an action, not just currently
            // active ones — a revoked admin's past filter option shouldn't
            // vanish along with them.
            'admins' => PlatformAdmin::withTrashed()
                ->whereIn('id', PlatformAdminActivity::query()->select('platform_admin_id')->distinct())
                ->get(),
            'actions' => PlatformAdminActivity::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'selectedAdmin' => $adminId,
            'selectedAction' => $action,
        ]);
    }
}
