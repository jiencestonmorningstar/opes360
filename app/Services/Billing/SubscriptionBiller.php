<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Notifications\SubscriptionPaymentSucceededNotification;
use App\Support\PlanEntitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Orchestrates a plan purchase: creates the SubscriptionPayment record, asks
 * the chosen gateway to collect it, and — once a status comes back confirmed,
 * whether from a webhook or a manual status check — activates the plan on
 * the company exactly once.
 */
class SubscriptionBiller
{
    /** @return array{payment: SubscriptionPayment, redirect_url: ?string, message: ?string} */
    public function start(Company $company, User $initiator, string $plan, string $billingCycle, string $provider, ?string $phone = null): array
    {
        if (! in_array($plan, PlanEntitlements::PLANS, true)) {
            throw new InvalidArgumentException("Unknown plan [{$plan}].");
        }

        if (! in_array($billingCycle, ['monthly', 'annual'], true)) {
            throw new InvalidArgumentException("Unknown billing cycle [{$billingCycle}].");
        }

        $payment = SubscriptionPayment::create([
            'company_id' => $company->id,
            'initiated_by' => $initiator->id,
            'plan' => $plan,
            'billing_cycle' => $billingCycle,
            'amount' => PlanEntitlements::priceFor($plan, $billingCycle),
            'currency' => 'XAF',
            'provider' => $provider,
            'phone' => $phone,
            'external_id' => (string) Str::uuid(),
            'status' => 'pending',
        ]);

        $result = MobileMoneyGateways::resolve($provider)->initiate($payment);

        $payment->update([
            'status' => $result['status'],
            'provider_reference' => $result['provider_reference'] ?? null,
            'failure_reason' => $result['status'] === 'failed' ? ($result['message'] ?? null) : null,
            'payload' => $result['raw'] ?? [],
        ]);

        return [
            'payment' => $payment,
            'redirect_url' => $result['redirect_url'] ?? null,
            'message' => $result['message'] ?? null,
        ];
    }

    /** Ask the provider directly for a fresh status and apply it. A no-op once the payment has already settled. */
    public function refresh(SubscriptionPayment $payment): SubscriptionPayment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        $result = MobileMoneyGateways::resolve($payment->provider)->checkStatus($payment);

        $this->apply($payment, $result['status'], $result['provider_reference'] ?? null, $result['raw'] ?? []);

        return $payment->fresh();
    }

    protected function apply(SubscriptionPayment $payment, string $status, ?string $providerReference, array $raw): void
    {
        if (! in_array($status, SubscriptionPayment::STATUSES, true)) {
            $status = 'pending';
        }

        DB::transaction(function () use ($payment, $status, $providerReference, $raw) {
            // Re-read under a row lock: a webhook and a manual status check
            // racing each other must not both pass the "still pending" test
            // and activate the plan twice.
            $payment = SubscriptionPayment::query()->acrossAllCompanies()->lockForUpdate()->find($payment->id);

            if (! $payment || ! $payment->isPending()) {
                return;
            }

            $payment->update([
                'status' => $status,
                'provider_reference' => $providerReference ?: $payment->provider_reference,
                'payload' => [...(array) $payment->payload, 'latest' => $raw],
                'paid_at' => $status === 'successful' ? now() : null,
            ]);

            if ($status === 'successful') {
                $this->activatePlan($payment);
            }
        });
    }

    protected function activatePlan(SubscriptionPayment $payment): void
    {
        $company = Company::query()->find($payment->company_id);

        if (! $company) {
            return;
        }

        $company->forceFill([
            'plan' => $payment->plan,
            'account_type' => 'active',
            'plan_renews_at' => $payment->billing_cycle === 'annual' ? now()->addYear() : now()->addMonth(),
        ])->save();

        $company->owner?->notify(new SubscriptionPaymentSucceededNotification($payment));
    }
}
