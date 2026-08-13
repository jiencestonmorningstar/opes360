<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A URL this server may be told to POST to.
 *
 * Without this, a webhook endpoint is a server-side request forgery hole with
 * a management screen. A tenant registers `https://169.254.169.254/…` and our
 * server fetches the cloud provider's instance metadata — credentials
 * included — on their behalf. Or `https://10.0.0.5/admin`, and they have a
 * port scanner pointed at our private network that reports back through the
 * delivery log's status codes and response bodies.
 *
 * `url:https` does not help with any of that: those are all perfectly valid
 * https URLs.
 *
 * So the host is resolved and every address it answers with must be public.
 * Resolving here rather than trusting the literal matters, because
 * `internal.attacker.com` is a public-looking name that can point anywhere.
 *
 * ── What this does not solve ────────────────────────────────────────────────
 *
 * DNS rebinding: a name that resolves publicly now and privately at delivery
 * time. That is why DeliverWebhook checks again immediately before it sends,
 * rather than trusting that this ran once at registration.
 */
class PublicHttpsUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isAllowed($value, $reason)) {
            $fail($reason ?? 'That address cannot be used for a webhook.');
        }
    }

    /**
     * Whether this URL is one the server may call.
     *
     * Shared with the delivery job, which re-checks at send time.
     */
    public static function isAllowed(string $url, ?string &$reason = null): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            $reason = 'That does not look like a web address.';

            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            // A webhook carries business data and a signature. Plain http puts
            // both in the clear for anybody on the path.
            $reason = 'A webhook address must use https.';

            return false;
        }

        $host = trim($parts['host'], '[]');

        // A literal address needs no lookup; a name may answer with several.
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                gethostbynamel($host) ?: [],
                self::resolveV6($host),
            );

        /*
         * A name that does not resolve is not refused.
         *
         * It is not an SSRF risk — there is nothing behind it to reach — and
         * treating DNS failure as a permanent refusal would be wrong twice
         * over: a customer whose DNS has not propagated yet could not register
         * their endpoint, and a momentary resolver blip at delivery time would
         * kill a delivery outright instead of letting it retry. Let the HTTP
         * client fail it naturally, where the backoff already handles it.
         *
         * The rebinding case stays covered: the moment the name does resolve,
         * it resolves to something, and that something is checked below.
         */
        if ($addresses === []) {
            return true;
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                // Deliberately vague: naming which internal range answered
                // would make this endpoint a network scanner in itself.
                $reason = 'That address is not reachable from the public internet.';

                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    protected static function resolveV6(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA);

        return collect($records ?: [])->pluck('ipv6')->filter()->all();
    }

    /**
     * Loopback, private and reserved ranges are all rejected — the filter
     * covers 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16 (cloud metadata),
     * ::1 and the IPv6 unique-local and link-local blocks.
     */
    protected static function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
