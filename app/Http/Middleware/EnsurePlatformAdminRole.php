<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the two actions with real financial/security weight — changing a
 * plan, managing other admins — behind the full 'admin' role. Everything
 * else (suspend/reactivate, member management, and all reading) stays
 * available to 'support' too, per PlatformAdmin::ROLE_SUPPORT's docblock.
 */
class EnsurePlatformAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('admin')?->role !== PlatformAdmin::ROLE_ADMIN) {
            abort(403, "Your admin role doesn't include this action.");
        }

        return $next($request);
    }
}
