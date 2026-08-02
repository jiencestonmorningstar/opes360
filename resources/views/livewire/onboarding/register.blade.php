@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13.5px] font-semibold text-ink-2';
@endphp

<div class="w-full max-w-[420px]">

    <div class="text-center">
        <div class="text-[30px] font-bold leading-none tracking-[-0.02em]">
            <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
        </div>
        <p class="mt-2 text-[14px] text-muted">{{ config('opes.brand.tagline') }}</p>
    </div>

    {{-- Who sent them here. Without this an invite link looks identical to the
         plain signup page, and the client has no way to tell their secretariat's
         link worked — nor the secretariat any way to demonstrate that it did. --}}
    @if ($referrerName !== '')
        <div class="card mt-6 flex items-center gap-3 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tint-purple">
                <x-icon name="spark" class="size-[17px] text-accent-purple" stroke-width="2" />
            </span>
            <p class="text-[13.5px] leading-relaxed text-ink-2">
                Invited by <span class="font-semibold text-ink">{{ $referrerName }}</span>
            </p>
        </div>
    @endif

    {{-- Step indicator --}}
    <div class="mt-7 flex items-center gap-2">
        @foreach ([1 => 'Account', 2 => 'Business'] as $index => $label)
            <div class="flex-1">
                <div class="h-1.5 rounded-full {{ $step >= $index ? 'bg-fill-brand' : 'bg-border' }}"></div>
                <p class="mt-1.5 text-[11.5px] font-semibold {{ $step >= $index ? 'text-brand' : 'text-faint' }}">
                    {{ $label }}
                </p>
            </div>
        @endforeach
    </div>

    <div class="card mt-4 p-6">
        @if ($step === 1)
            <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">Create your account</h1>
            <p class="mt-1 text-[14px] text-muted">Two minutes and you can issue your first invoice.</p>

            <div class="mt-6 space-y-4">
                <label class="block">
                    <span class="{{ $labelClass }}">Your name</span>
                    <input type="text" wire:model="name" autocomplete="name" class="{{ $inputClass }}">
                    @error('name') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Email</span>
                    <input type="email" wire:model="email" autocomplete="email" placeholder="you@business.com" class="{{ $inputClass }}">
                    @error('email') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Password</span>
                    <input type="password" wire:model="password" autocomplete="new-password" class="{{ $inputClass }}">
                    @error('password') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Confirm password</span>
                    <input type="password" wire:model="passwordConfirmation" autocomplete="new-password" class="{{ $inputClass }}">
                </label>
            </div>

            <button type="button" wire:click="continueToBusiness"
                    class="tap focusable mt-6 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                Continue
            </button>

            <p class="mt-5 text-center text-[13.5px] text-muted">
                Already have an account?
                <a href="{{ route('login') }}" class="font-semibold text-brand hover:underline">Sign in</a>
            </p>
        @else
            <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">
                {{ $this->isSecretariatSignup() ? 'Tell us about your secretariat' : 'Tell us about your business' }}
            </h1>
            <p class="mt-1 text-[14px] text-muted">This appears on every document you issue.</p>

            {{-- The partner programme was previously reachable only by knowing to
                 add ?type=secretariat to the URL. It is a product decision, so it
                 belongs on the form. --}}
            <div class="mt-6">
                <span class="{{ $labelClass }}">What kind of account is this?</span>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ([
                        ['' , 'A business', 'Sells goods or services.'],
                        ['secretariat', 'A secretariat', 'Prints cards for other businesses.'],
                    ] as [$value, $label, $detail])
                        @php $chosen = $type === $value; @endphp
                        <button type="button" wire:click="$set('type', '{{ $value }}')"
                                aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                                class="focusable rounded-xl border p-3 text-left transition-colors
                                       {{ $chosen ? 'border-brand bg-tint-blue ring-1 ring-brand/40' : 'border-border bg-surface hover:bg-surface-2' }}">
                            <span class="block text-[14px] font-semibold {{ $chosen ? 'text-brand' : 'text-ink' }}">{{ $label }}</span>
                            <span class="mt-0.5 block text-[12.5px] leading-snug text-muted">{{ $detail }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($this->isSecretariatSignup())
                    <p class="mt-2.5 text-[12.5px] leading-relaxed text-muted">
                        You get a client book and a partner code on top of everything a business
                        account does.
                        <a href="{{ route('marketing.partners') }}" target="_blank" class="font-semibold text-brand hover:underline">How it works</a>
                    </p>
                @endif
            </div>

            <div class="mt-5 space-y-4">
                <label class="block">
                    <span class="{{ $labelClass }}">Business name</span>
                    <input type="text" wire:model="businessName" class="{{ $inputClass }}">
                    @error('businessName') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Industry</span>
                    <select wire:model="industry" class="{{ $inputClass }}">
                        <option value="">Choose…</option>
                        @foreach (config('opes.industries') as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Motto <span class="font-normal text-faint">(optional)</span></span>
                    <input type="text" wire:model="motto" placeholder="Business made simple" class="{{ $inputClass }}">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="{{ $labelClass }}">Currency</span>
                        <select wire:model="currency" class="{{ $inputClass }}">
                            @foreach (config('opes.currencies') as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="{{ $labelClass }}">Country</span>
                        <input type="text" wire:model="country" maxlength="2" placeholder="NG" class="{{ $inputClass }} uppercase">
                    </label>
                </div>
                <label class="block">
                    <span class="{{ $labelClass }}">Phone <span class="font-normal text-faint">(optional)</span></span>
                    <input type="tel" wire:model="phone" class="{{ $inputClass }}">
                </label>
            </div>

            @error('password')
                <p class="mt-3 text-[12.5px] font-medium text-warning">{{ $message }}</p>
            @enderror
            @error('email')
                <p class="mt-3 text-[12.5px] font-medium text-warning">{{ $message }}</p>
            @enderror

            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="back"
                        class="focusable h-12 flex-1 rounded-xl bg-surface-2 text-[15px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                    Back
                </button>
                <button type="button" wire:click="finish" wire:loading.attr="disabled"
                        class="tap focusable h-12 flex-[1.6] rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                    <span wire:loading.remove wire:target="finish">Create Business</span>
                    <span wire:loading wire:target="finish">Setting up…</span>
                </button>
            </div>
        @endif
    </div>

    <p class="mt-6 text-center text-[12.5px] text-muted">
        Built by <a href="{{ config('opes.brand.vendor_url') }}" class="font-medium text-brand hover:underline">{{ config('opes.brand.vendor') }}</a>
    </p>
</div>
