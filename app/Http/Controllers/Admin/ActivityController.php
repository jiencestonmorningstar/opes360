<?php

namespace App\Http\Controllers\Admin;

use App\Models\Company;
use App\Models\PlatformAdminActivity;
use Illuminate\Routing\Controller;

/**
 * Every admin action across every business, in one place — the per-company
 * activity list on CompanyController::show() only answers "what happened to
 * this business"; this answers "what has admin staff been doing".
 */
class ActivityController extends Controller
{
    public function index()
    {
        $activity = PlatformAdminActivity::query()
            ->with('admin')
            ->latest()
            ->paginate(30);

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
        ]);
    }
}
