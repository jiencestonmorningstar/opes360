<?php

namespace App\Livewire\Settings;

use App\Models\SubscriptionPayment;
use App\Services\Billing\MobileMoneyGateways;
use App\Services\Billing\SubscriptionBiller;
use App\Support\CurrentCompany;
use App\Support\PlanEntitlements;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Billing extends Component
{
    use AuthorizesRequests;

    public string $plan = 'basic';

    public string $billingCycle = 'monthly';

    public string $provider = '';

    public string $phone = '';

    public ?string $pendingPaymentId = null;

    public function mount(): void
    {
        $company = app(CurrentCompany::class)->get();
        $this->plan = $company?->plan ?? 'basic';

        $providers = MobileMoneyGateways::available();
        $this->provider = $providers[0]['key'] ?? '';

        $pending = $company?->subscriptionPayments()->where('status', 'pending')->latest()->first();
        $this->pendingPaymentId = $pending?->id;
    }

    public function pay(SubscriptionBiller $biller)
    {
        $this->authorize('business.update');

        $company = app(CurrentCompany::class)->get();

        $this->validate([
            'plan' => ['required', Rule::in(PlanEntitlements::PLANS)],
            'billingCycle' => ['required', Rule::in(['monthly', 'annual'])],
            'provider' => ['required', Rule::in(array_column(MobileMoneyGateways::available(), 'key'))],
            'phone' => [Rule::requiredIf($this->provider === 'mtn_momo'), 'nullable', 'string', 'min:8', 'max:20'],
        ]);

        $result = $biller->start(
            $company,
            auth()->user(),
            $this->plan,
            $this->billingCycle,
            $this->provider,
            $this->phone ?: null,
        );

        if ($result['redirect_url']) {
            return redirect()->away($result['redirect_url']);
        }

        $this->pendingPaymentId = $result['payment']->id;
        session()->flash('billingStatus', $result['message'] ?? 'Payment started.');
    }

    /** Polled by wire:poll while a payment is pending; a no-op otherwise. */
    public function checkStatus(SubscriptionBiller $biller): void
    {
        if (! $this->pendingPaymentId) {
            return;
        }

        $payment = SubscriptionPayment::find($this->pendingPaymentId);

        if (! $payment) {
            $this->pendingPaymentId = null;

            return;
        }

        $payment = $biller->refresh($payment);

        if (! $payment->isPending()) {
            $this->pendingPaymentId = null;

            session()->flash('billingStatus', match ($payment->status) {
                'successful' => 'Payment received — your plan is now active.',
                'failed' => 'That payment failed. You can try again below.',
                'expired' => 'That payment request expired before it was approved.',
                default => 'That payment was cancelled.',
            });
        }
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        return view('livewire.settings.billing', [
            'company' => $company,
            'providers' => MobileMoneyGateways::available(),
            'prices' => collect(PlanEntitlements::PLANS)->mapWithKeys(fn ($p) => [
                $p => [
                    'monthly' => PlanEntitlements::priceFor($p, 'monthly'),
                    'annual' => PlanEntitlements::priceFor($p, 'annual'),
                ],
            ]),
            'pendingPayment' => $this->pendingPaymentId ? SubscriptionPayment::find($this->pendingPaymentId) : null,
            'history' => $company
                ? $company->subscriptionPayments()->latest()->limit(10)->get()
                : collect(),
        ])->layout('components.layouts.app', ['title' => 'Billing', 'active' => 'settings']);
    }
}
