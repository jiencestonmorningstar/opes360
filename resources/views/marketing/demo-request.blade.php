<x-layouts.marketing title="Try a demo" variant="column">
<div class="w-full max-w-[440px]">

    <div class="text-center">
        <div class="text-[30px] font-bold leading-none tracking-[-0.02em]">
            <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
        </div>
        <p class="mt-2 text-[14px] text-muted">See it running with your own login, in about a minute.</p>
    </div>

    <div class="card mt-7 p-6">
        <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">Try a demo</h1>
        <p class="mt-1 text-[14px] text-muted">
            We'll set up a working account with one sample of everything — a customer, a product, an invoice,
            a document, a form and an event — and email you the login. It runs for 14 days, then quietly
            becomes a free trial; nothing you build is lost.
        </p>

        @if ($errors->any())
            <div class="mt-5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('demo.store') }}" class="mt-6 space-y-4">
            @csrf

            {{-- Honeypot: hidden from real visitors, irresistible to bots. --}}
            <div class="hidden" aria-hidden="true">
                <label for="website_url">Website</label>
                <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
            </div>

            <div>
                <label for="name" class="block text-[13.5px] font-semibold text-ink-2">Your name</label>
                <input id="name" name="name" type="text" required autofocus value="{{ old('name') }}"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                       placeholder="Ada Obi">
            </div>

            <div>
                <label for="email" class="block text-[13.5px] font-semibold text-ink-2">Email</label>
                <input id="email" name="email" type="email" required value="{{ old('email') }}"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                       placeholder="you@business.com">
                <p class="mt-1 text-[12px] text-faint">Your login goes here — check it after submitting.</p>
            </div>

            <div>
                <label for="business_name" class="block text-[13.5px] font-semibold text-ink-2">Business name</label>
                <input id="business_name" name="business_name" type="text" required value="{{ old('business_name') }}"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                       placeholder="Your Business Ltd">
            </div>

            <div>
                <label for="industry" class="block text-[13.5px] font-semibold text-ink-2">Industry <span class="font-normal text-faint">(optional)</span></label>
                <select id="industry" name="industry"
                        class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    <option value="">Choose…</option>
                    @foreach (config('opes.industries') as $industry)
                        <option value="{{ $industry }}" @selected(old('industry') === $industry)>{{ $industry }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit"
                    class="tap focusable mt-2 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Create my demo
            </button>
        </form>

        <p class="mt-5 text-center text-[13.5px] text-muted">
            Already have an account?
            <a href="{{ route('login') }}" class="font-semibold text-brand hover:underline">Sign in</a>
        </p>
    </div>

    <p class="mt-6 text-center text-[12.5px] text-muted">
        Built by <a href="{{ config('opes.brand.vendor_url') }}" class="font-medium text-brand hover:underline">{{ config('opes.brand.vendor') }}</a>
    </p>
</div>
</x-layouts.marketing>
