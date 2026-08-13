<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base for JSON API controllers.
 *
 * Exists for the authorisation trait: Laravel's bare base controller no longer
 * carries it, and an API endpoint that silently skipped `authorize()` would be
 * a way past the policy layer the whole app relies on. Kept off the shared
 * base controller so the web side is untouched.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;
}
