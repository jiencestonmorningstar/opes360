<?php

namespace App\Http\Controllers\Admin;

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Managing platform staff themselves — who else can sign in to /admin.
 * Deliberately separate from CompanyController: this is the one screen in
 * the panel where the "subject" of an action is another PlatformAdmin
 * rather than a business.
 */
class PlatformAdminsController extends Controller
{
    public function index()
    {
        return view('admin.admins.index', [
            'admins' => PlatformAdmin::query()->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // A soft-deleted (revoked) admin's row still occupies the email,
            // so uniqueness is only checked against currently-active ones —
            // re-inviting a previously-revoked address restores them below
            // instead of bouncing off "already taken".
            'email' => ['required', 'email', Rule::unique('platform_admins', 'email')->whereNull('deleted_at')],
            'role' => ['required', Rule::in(PlatformAdmin::ROLES)],
        ]);

        $revoked = PlatformAdmin::withTrashed()->where('email', $data['email'])->first();

        if ($revoked) {
            $revoked->restore();
            $revoked->update(['name' => $data['name'], 'role' => $data['role']]);
            $admin = $revoked;
        } else {
            $admin = PlatformAdmin::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                // An unusable placeholder — the invitee sets their real
                // password through the same reset link every "forgot
                // password" uses, so no first password is ever generated or
                // transmitted by hand.
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        Password::broker('platform_admins')->sendResetLink(['email' => $admin->email]);

        PlatformAdminActivity::log($request->user('admin'), 'invited_admin', $admin, [
            'email' => $admin->email,
        ]);

        return back()->with('status', "Invited {$admin->email} — they'll get an email to set their password.");
    }

    public function destroy(Request $request, PlatformAdmin $admin)
    {
        if ($admin->id === $request->user('admin')->id) {
            return back()->with('status', "You can't revoke your own access.");
        }

        PlatformAdminActivity::log($request->user('admin'), 'revoked_admin', $admin, [
            'email' => $admin->email,
        ]);

        $admin->delete();

        return back()->with('status', "{$admin->email}'s access has been revoked.");
    }
}
