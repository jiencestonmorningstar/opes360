<?php

namespace App\Support;

/**
 * How a receiver knows a webhook came from us.
 *
 * The body alone proves nothing — anybody who learns the URL can post to it,
 * and a URL leaks the moment it appears in a proxy log or a screenshot. So
 * every delivery carries an HMAC-SHA256 over the payload, keyed on the secret
 * only the two ends hold.
 *
 * ── Why the timestamp is inside the signed material ─────────────────────────
 *
 * Signing the body by itself would produce a signature that stays valid
 * forever. Somebody who captured one delivery — from a log, a mirror, a
 * misconfigured proxy — could replay it a month later, byte for byte, and the
 * receiver would verify it perfectly, because it *is* perfectly genuine. It
 * just is not now.
 *
 * Putting the timestamp in the signature makes the age of a delivery part of
 * what is signed and therefore impossible to change without the secret. A
 * receiver rejects anything older than its tolerance and a replay stops
 * working the moment that window closes.
 *
 * The header format is Stripe's, on purpose:
 *
 *     Opes-Signature: t=1755000000,v1=9f86d0818…
 *
 * Not because it is clever but because it is the one shape a developer
 * integrating with this has probably already written code against, and `v1=`
 * leaves room to add a second scheme later without breaking the first.
 */
class WebhookSignature
{
    /** The signed material: the timestamp, a dot, and the exact body sent. */
    public static function payload(int $timestamp, string $body): string
    {
        return $timestamp.'.'.$body;
    }

    public static function compute(int $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', self::payload($timestamp, $body), $secret);
    }

    public static function header(int $timestamp, string $body, string $secret): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, self::compute($timestamp, $body, $secret));
    }

    /**
     * Verify a header the way a receiver would. Used by the tests, and it is
     * the reference the documented example is written against.
     *
     * @param  int  $tolerance  How old a delivery may be, in seconds. Five
     *                          minutes is generous enough for clock drift on a
     *                          server nobody has run NTP on, and short enough
     *                          that a captured delivery is worthless by the
     *                          time anybody notices they have it.
     */
    public static function verify(string $header, string $body, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[$key] = $value;
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit((string) $parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        // Constant time: a comparison that returns early leaks how much of a
        // guess was right, which is enough to forge one byte at a time.
        return hash_equals(self::compute($timestamp, $body, $secret), (string) $parts['v1']);
    }
}
