@php
    $toneFor = fn (string $status) => match ($status) {
        'successful' => 'positive',
        'pending' => 'warning',
        default => 'negative', // failed|cancelled|expired
    };
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('settings') }}" class="focusable flex size-9 shrink-0 items-center justify-center rounded-xl bg-surface-2 text-ink-2 hover:bg-tint-blue hover:text-brand">
            <x-icon name="chevron-left" class="size-[18px]" />
        </a>
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Billing</h1>
            <p class="mt-1 text-[14.5px] text-muted">Pay for your plan with MTN Mobile Money or Orange Money.</p>
        </div>
    </div>

    @if (session('billingStatus'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
            {{ session('billingStatus') }}
        </div>
    @endif

    <div class="mt-5 grid gap-4 lg:grid-cols-2">

        <div class="space-y-4">
            {{-- Current plan --}}
            <x-ui.panel title="Current plan">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[16px] font-bold text-ink">{{ ucfirst($company->plan) }}</p>
                        @if ($company->plan_renews_at?->isPast())
                            <p class="mt-0.5 text-[12.5px] font-semibold text-warning">
                                Billing period ended {{ $company->plan_renews_at->format('d M Y') }} — renew below.
                            </p>
                        @elseif ($company->plan_renews_at)
                            <p class="mt-0.5 text-[12.5px] text-muted">Renews {{ $company->plan_renews_at->format('d M Y') }}</p>
                        @else
                            <p class="mt-0.5 text-[12.5px] text-muted">No active subscription payment on file yet.</p>
                        @endif
                    </div>
                    <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-tint-blue">
                        <x-icon name="credit-card" class="size-[20px] text-brand" />
                    </span>
                </div>
            </x-ui.panel>

            {{-- Pending payment --}}
            @if ($pendingPayment)
                <x-ui.panel title="Payment in progress" wire:poll.3s="checkStatus">
                    <div class="flex items-start gap-3">
                        <span class="flex size-[38px] shrink-0 items-center justify-center rounded-xl bg-tint-orange">
                            <x-icon name="clock" class="size-[18px] text-warning" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[14px] font-semibold text-ink">
                                {{ ucfirst($pendingPayment->plan) }} plan · {{ \App\Support\Money::format($pendingPayment->amount, $pendingPayment->currency, false) }}
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                @if ($pendingPayment->provider === 'mtn_momo')
                                    Approve the prompt sent to {{ $pendingPayment->phone }}.
                                @else
                                    Waiting for confirmation from Orange Money.
                                @endif
                            </p>
                        </div>
                        <button type="button" wire:click="checkStatus" wire:loading.attr="disabled"
                                class="focusable shrink-0 rounded-lg bg-surface-2 px-3 py-2 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                            Check now
                        </button>
                    </div>
                </x-ui.panel>
            @endif

            {{-- Pay --}}
            @can('business.update')
                <x-ui.panel title="Pay for a plan">
                    <div class="space-y-4">
                        <div>
                            <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Plan</span>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach (\App\Support\PlanEntitlements::PLANS as $p)
                                    <label wire:key="plan-{{ $p }}"
                                           class="cursor-pointer rounded-xl border p-3 text-center transition-colors {{ $plan === $p ? 'border-brand bg-tint-blue' : 'border-border hover:bg-surface-2' }}">
                                        <input type="radio" wire:model.live="plan" value="{{ $p }}" class="sr-only">
                                        <span class="block text-[13.5px] font-bold {{ $plan === $p ? 'text-brand' : 'text-ink' }}">{{ ucfirst($p) }}</span>
                                        <span class="mt-0.5 block text-[11.5px] text-muted">{{ \App\Support\Money::format($prices[$p]['monthly'], 'XAF', false) }}/mo</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Billing cycle</span>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="cursor-pointer rounded-xl border p-3 text-center transition-colors {{ $billingCycle === 'monthly' ? 'border-brand bg-tint-blue' : 'border-border hover:bg-surface-2' }}">
                                    <input type="radio" wire:model.live="billingCycle" value="monthly" class="sr-only">
                                    <span class="block text-[13.5px] font-bold {{ $billingCycle === 'monthly' ? 'text-brand' : 'text-ink' }}">Monthly</span>
                                    <span class="mt-0.5 block text-[11.5px] text-muted">{{ \App\Support\Money::format($prices[$plan]['monthly'], 'XAF', false) }}</span>
                                </label>
                                <label class="cursor-pointer rounded-xl border p-3 text-center transition-colors {{ $billingCycle === 'annual' ? 'border-brand bg-tint-blue' : 'border-border hover:bg-surface-2' }}">
                                    <input type="radio" wire:model.live="billingCycle" value="annual" class="sr-only">
                                    <span class="block text-[13.5px] font-bold {{ $billingCycle === 'annual' ? 'text-brand' : 'text-ink' }}">Annual</span>
                                    <span class="mt-0.5 block text-[11.5px] text-muted">{{ \App\Support\Money::format($prices[$plan]['annual'], 'XAF', false) }} · 2 months free</span>
                                </label>
                            </div>
                        </div>

                        @if (empty($providers))
                            <p class="rounded-xl bg-tint-orange px-4 py-2.5 text-[13px] font-semibold text-warning">
                                No mobile money provider is configured yet. Ask an administrator to add MTN Mobile Money
                                or Orange Money credentials.
                            </p>
                        @else
                            <div>
                                <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Pay with</span>
                                <div class="grid grid-cols-{{ count($providers) }} gap-2">
                                    @foreach ($providers as $p)
                                        <label wire:key="provider-{{ $p['key'] }}"
                                               class="cursor-pointer rounded-xl border p-3 text-center transition-colors {{ $provider === $p['key'] ? 'border-brand bg-tint-blue' : 'border-border hover:bg-surface-2' }}">
                                            <input type="radio" wire:model.live="provider" value="{{ $p['key'] }}" class="sr-only">
                                            <span class="block text-[13.5px] font-bold {{ $provider === $p['key'] ? 'text-brand' : 'text-ink' }}">{{ $p['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            @if ($provider === 'mtn_momo')
                                <label class="block">
                                    <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">MTN phone number</span>
                                    <input type="tel" wire:model="phone" placeholder="670 41 62 38"
                                           class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                    @error('phone') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                                    <span class="mt-1 block text-[12px] text-muted">You'll get a payment prompt on this number to approve.</span>
                                </label>
                            @endif

                            <button type="button" wire:click="pay" wire:loading.attr="disabled" wire:target="pay"
                                    class="focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[14.5px] font-semibold text-white hover:opacity-90 disabled:opacity-60">
                                <span wire:loading.remove wire:target="pay">
                                    Pay {{ \App\Support\Money::format($prices[$plan][$billingCycle], 'XAF', false) }}
                                </span>
                                <span wire:loading wire:target="pay">Starting payment…</span>
                            </button>
                        @endif
                    </div>
                </x-ui.panel>
            @endcan
        </div>

        {{-- History --}}
        <x-ui.panel title="Payment history" body-class="-mx-1.5">
            @forelse ($history as $payment)
                <div wire:key="payment-{{ $payment->id }}"
                     class="flex items-center gap-3 rounded-lg px-1.5 py-2.5 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                    <span class="flex size-[38px] shrink-0 items-center justify-center rounded-xl bg-surface-2">
                        <x-icon name="banknotes" class="size-[18px] text-muted" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[14px] font-semibold text-ink">
                            {{ ucfirst($payment->plan) }} · {{ ucfirst($payment->billing_cycle) }}
                        </span>
                        <span class="block truncate text-[12px] text-muted">
                            {{ $payment->provider === 'mtn_momo' ? 'MTN Mobile Money' : 'Orange Money' }}
                            · {{ $payment->created_at->format('d M Y') }}
                        </span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="tnum block text-[13.5px] font-bold text-ink">
                            {{ \App\Support\Money::format($payment->amount, $payment->currency, false) }}
                        </span>
                        <x-ui.status-badge :label="ucfirst($payment->status)" :tone="$toneFor($payment->status)" />
                    </span>
                </div>
            @empty
                <div class="px-1.5 py-8 text-center">
                    <p class="text-[14px] font-semibold text-ink">No payments yet</p>
                    <p class="mt-1 text-[13px] text-muted">Your subscription payments will show up here.</p>
                </div>
            @endforelse
        </x-ui.panel>
    </div>
</div>
