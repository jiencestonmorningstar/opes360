<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a retry safe on the endpoints where retrying twice costs money.
 *
 * A client that POSTs a payment and loses the connection cannot know whether
 * the money was taken. Retrying risks charging twice; not retrying risks a
 * payment the business never recorded. On the networks this product runs on
 * that is a routine situation, not an exotic one.
 *
 * So: send an `Idempotency-Key` header you generated, and a second request
 * carrying the same key gets the first one's response back rather than doing
 * the work again.
 *
 * Three cases, deliberately answered differently:
 *
 *   Same key, same body, first request finished  → replay the stored response.
 *   Same key, same body, first still in flight   → 409, try again shortly.
 *   Same key, different body                     → 422. That is a client bug
 *     (a key reused for a genuinely different payment), and replaying the
 *     first response would hide it.
 *
 * The header is optional. Making it mandatory would break every caller that
 * has not adopted it yet, and the endpoints remain correct without it — just
 * not retry-safe, which is the caller's risk to take knowingly.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || ! $request->isMethod('POST')) {
            return $next($request);
        }

        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return $next($request);
        }

        $tokenId = $request->user()?->currentAccessToken()?->getKey();
        $hash = IdempotencyKey::hashPayload($request->all());

        /*
         * Claim the key inside a transaction so two retries racing each other
         * cannot both decide they are the first. The unique index is what
         * actually decides it; this just turns the collision into a lookup.
         */
        $existing = null;

        try {
            DB::transaction(function () use ($company, $key, $tokenId, $hash, $request) {
                IdempotencyKey::create([
                    'company_id' => $company->id,
                    'key' => $key,
                    'token_id' => $tokenId,
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'request_hash' => $hash,
                ]);
            });
        } catch (\Illuminate\Database\QueryException) {
            $existing = IdempotencyKey::query()
                ->where('key', $key)
                ->where('token_id', $tokenId)
                ->first();
        }

        if ($existing !== null) {
            if ($existing->request_hash !== $hash) {
                return response()->json([
                    'message' => 'This Idempotency-Key was already used for a different request.',
                ], 422);
            }

            if (! $existing->isComplete()) {
                return response()->json([
                    'message' => 'A request with this Idempotency-Key is still being processed.',
                ], 409);
            }

            return response()->json($existing->response, $existing->status)
                ->header('Idempotent-Replay', 'true');
        }

        $response = $next($request);

        // Only successful work is worth replaying. A failed request should be
        // retryable with the same key — the caller fixed nothing, the server
        // did nothing, and refusing the retry would strand them.
        if ($response->getStatusCode() < 400) {
            IdempotencyKey::query()
                ->where('key', $key)
                ->where('token_id', $tokenId)
                ->update([
                    'status' => $response->getStatusCode(),
                    'response' => json_decode($response->getContent(), true),
                    'completed_at' => now(),
                ]);
        } else {
            // Release the key so the caller can genuinely retry.
            IdempotencyKey::query()
                ->where('key', $key)
                ->where('token_id', $tokenId)
                ->whereNull('completed_at')
                ->delete();
        }

        return $response;
    }
}
