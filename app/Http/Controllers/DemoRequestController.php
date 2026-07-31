<?php

namespace App\Http\Controllers;

use App\Notifications\DemoAccountCreatedNotification;
use App\Services\DemoAccountProvisioner;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The public "Request a demo" page. Unlike registration, the visitor gets no
 * choice of password or business details beyond a name — the whole point is
 * zero friction between "I want to try this" and a working, populated
 * account landing in their inbox.
 */
class DemoRequestController extends Controller
{
    public function show()
    {
        return view('marketing.demo-request');
    }

    public function store(Request $request, DemoAccountProvisioner $provisioner)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'business_name' => ['required', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:60'],
            // Honeypot: a real visitor never fills this in.
            'website_url' => ['prohibited'],
        ], [
            'email.unique' => 'An account already exists for that email — sign in instead.',
        ]);

        $result = $provisioner->provision(
            $data['name'],
            $data['email'],
            $data['business_name'],
            $data['industry'] ?? null,
        );

        $result['user']->notify(new DemoAccountCreatedNotification($result['company'], $result['password']));

        return redirect()->route('demo.thanks')->with('email', $result['user']->email);
    }

    public function thanks()
    {
        return view('marketing.demo-thanks', ['email' => session('email')]);
    }
}
