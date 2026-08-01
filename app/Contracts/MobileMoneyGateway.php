<?php

namespace App\Contracts;

use App\Models\SubscriptionPayment;

/**
 * A mobile money provider that can collect a subscription payment. MTN Mobile
 * Money and Orange Money implement this the same way even though their APIs
 * differ (MTN's is an async debit prompt, Orange's is a redirect checkout) —
 * SubscriptionBiller and the billing UI never need to know which one they hold.
 */
interface MobileMoneyGateway
{
    /** The value stored in subscription_payments.provider for this gateway. */
    public static function key(): string;

    /** Human label for the payment picker, e.g. "MTN Mobile Money". */
    public static function label(): string;

    /**
     * Start collecting the payment. Returns:
     *  - status: 'pending' | 'failed'
     *  - redirect_url: where to send the payer to finish paying, or null when
     *    nothing further is needed from them right now (e.g. MTN's prompt goes
     *    straight to their phone)
     *  - provider_reference: the provider's own transaction id, if it gave one
     *    up front
     *  - message: a payer-facing status line
     *  - raw: the provider's response, kept for support/audit
     *
     * @return array{status: string, redirect_url: ?string, provider_reference: ?string, message: ?string, raw: array}
     */
    public function initiate(SubscriptionPayment $payment): array;

    /**
     * Ask the provider directly for the current status, for when a webhook
     * never arrives or the payer wants an up-to-date answer while waiting.
     *
     * @return array{status: string, provider_reference: ?string, raw: array}
     */
    public function checkStatus(SubscriptionPayment $payment): array;
}
