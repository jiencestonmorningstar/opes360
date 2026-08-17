<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the company this request acts on and hands it to CurrentCompany, which
 * every tenant-owned model reads through its global scope.
 *
 * Membership is re-checked on every request rather than trusted from the session,
 * so revoking a user's access takes effect immediately.
 */
class SetCurrentCompany
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Token requests carry no session, so the web guard resolves nobody on
        // them. Without the sanctum fallback the tenant scope would fail closed
        // for every API call and turn the whole API into a wall of 404s.
        $user = $request->user('web') ?? $request->user('sanctum');

        if ($user === null) {
            // Fail closed: a guest request must never inherit a tenant left in
            // the singleton by a previous request. Under php-fpm the container
            // is fresh anyway, but under a long-lived worker (Octane, a shared
            // queue container) a stale company here would let every public
            // page resolve scoped relations as an unrelated tenant.
            $this->current->set(null);

            return $next($request);
        }

        $company = $user->current_company_id
            ? Company::find($user->current_company_id)
            : null;

        // Fall back to any company the user still belongs to — covers a revoked
        // membership, a deleted company, and the first login after signup.
        if ($company === null || ! $user->belongsToCompany($company)) {
            $company = $user->companies()->wherePivot('status', 'active')->first();

            if ($company !== null && $user->current_company_id !== $company->id) {
                $user->forceFill(['current_company_id' => $company->id])->saveQuietly();
            }
        }

        // A platform admin suspended this business. Locked out with an
        // explanation rather than a silent 403 on every screen — the tenant
        // scope failing closed on a null current company already denies
        // everything; this just tells the user why.
        if ($company !== null && $company->isSuspended()) {
            $this->current->set(null);

            // An API client has no screen to be redirected to, and a 302 to an
            // HTML page is a worse answer than saying plainly what happened.
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'This business account is suspended.',
                ], 403);
            }

            if (! $request->routeIs('account-suspended', 'logout')) {
                return redirect()->route('account-suspended');
            }

            return $next($request);
        }

        $this->current->set($company);

        return $next($request);
    }
}
