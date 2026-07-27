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
        $user = $request->user();

        if ($user === null) {
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

        $this->current->set($company);

        return $next($request);
    }
}
