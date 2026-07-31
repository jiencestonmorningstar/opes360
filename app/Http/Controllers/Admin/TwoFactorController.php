<?php

namespace App\Http\Controllers\Admin;

use App\Services\QrCodes;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * A platform admin managing their own two-factor enrolment. Deliberately no
 * session-held "enrolling" flag like the business Livewire settings page
 * uses — TwoFactor::startEnrolment() already persists the unconfirmed
 * secret to the database, so "are we mid-enrolment" is just "is there a
 * secret with no confirmed_at yet", read fresh from the model on each
 * request.
 */
class TwoFactorController extends Controller
{
    public function show(Request $request)
    {
        return view('admin.settings.index', ['admin' => $request->user('admin')]);
    }

    public function qr(Request $request, TwoFactor $twoFactor, QrCodes $qr)
    {
        $admin = $request->user('admin');
        $secret = $admin->twoFactorSecret();

        abort_if($secret === null, 404);

        return response($qr->svg($twoFactor->provisioningUri($admin, $secret), 340), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function start(Request $request, TwoFactor $twoFactor)
    {
        $twoFactor->startEnrolment($request->user('admin'));

        return back();
    }

    public function confirm(Request $request, TwoFactor $twoFactor)
    {
        $request->validate(['code' => ['required', 'string']]);

        if (! $twoFactor->confirm($request->user('admin'), $request->string('code')->toString())) {
            return back()->withErrors(['code' => 'That code is not valid. Check your authenticator app.']);
        }

        return back()->with('status', 'Two-factor authentication is on. Save your recovery codes.');
    }

    public function cancel(Request $request, TwoFactor $twoFactor)
    {
        $twoFactor->disable($request->user('admin'));

        return back();
    }

    public function disable(Request $request, TwoFactor $twoFactor)
    {
        $twoFactor->disable($request->user('admin'));

        return back()->with('status', 'Two-factor authentication is off.');
    }
}
