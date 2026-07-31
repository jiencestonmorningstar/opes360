<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Contact · {{ config('opes.brand.name') }}</title>

    <script @cspNonce>
        (function () {
            try {
                var stored = localStorage.getItem('opes-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored === 'dark' || (stored !== 'light' && system));
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-full">

@include('marketing.partials.nav')

<main>
    <section class="mx-auto max-w-5xl px-5 py-16 sm:py-20">
        <div class="text-center">
            <h1 class="text-[30px] font-bold tracking-[-0.02em] text-ink sm:text-[36px]">Get in touch</h1>
            <p class="mt-3 text-[15px] text-muted">Questions about a plan, a feature, or anything else — send us a note, or reach us directly.</p>
        </div>

        <div class="mt-10 grid gap-6 lg:grid-cols-5">
        {{-- Direct contact details --}}
        <div class="space-y-3 lg:col-span-2 lg:order-2">
            <div class="card p-5">
                <span class="flex size-10 items-center justify-center rounded-lg bg-tint-blue">
                    <x-icon name="bell" class="size-[18px] text-brand" stroke-width="1.9" />
                </span>
                <p class="mt-3 text-[13px] font-semibold uppercase tracking-wide text-faint">Support</p>
                <a href="mailto:{{ config('opes.contact.support_email') }}" class="mt-1 block text-[14.5px] font-semibold text-ink hover:text-brand">
                    {{ config('opes.contact.support_email') }}
                </a>
            </div>

            <div class="card p-5">
                <span class="flex size-10 items-center justify-center rounded-lg bg-tint-green">
                    <x-icon name="banknotes" class="size-[18px] text-positive" stroke-width="1.9" />
                </span>
                <p class="mt-3 text-[13px] font-semibold uppercase tracking-wide text-faint">Sales</p>
                <a href="mailto:{{ config('opes.contact.sales_email') }}" class="mt-1 block text-[14.5px] font-semibold text-ink hover:text-brand">
                    {{ config('opes.contact.sales_email') }}
                </a>
            </div>

            <div class="card p-5">
                <span class="flex size-10 items-center justify-center rounded-lg bg-tint-orange">
                    <x-icon name="user" class="size-[18px] text-warning" stroke-width="1.9" />
                </span>
                <p class="mt-3 text-[13px] font-semibold uppercase tracking-wide text-faint">Call or WhatsApp</p>
                <a href="tel:{{ config('opes.contact.whatsapp') }}" class="mt-1 block text-[14.5px] font-semibold text-ink hover:text-brand">
                    {{ config('opes.contact.phone') }}
                </a>
                <a href="https://wa.me/{{ ltrim(config('opes.contact.whatsapp'), '+') }}" target="_blank" rel="noopener"
                   class="mt-0.5 block text-[13px] font-medium text-positive hover:underline">
                    Chat on WhatsApp
                </a>
            </div>

            <div class="card p-5">
                <span class="flex size-10 items-center justify-center rounded-lg bg-tint-purple">
                    <x-icon name="briefcase" class="size-[18px] text-accent-purple" stroke-width="1.9" />
                </span>
                <p class="mt-3 text-[13px] font-semibold uppercase tracking-wide text-faint">Office</p>
                <p class="mt-1 text-[14.5px] font-semibold leading-snug text-ink">{{ config('opes.contact.address') }}</p>
            </div>
        </div>

        <div class="card p-6 lg:col-span-3 lg:order-1">
            @if (session('status'))
                <div class="mb-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('marketing.contact.store') }}" class="space-y-4">
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
                </div>

                <div>
                    <label for="message" class="block text-[13.5px] font-semibold text-ink-2">Message</label>
                    <textarea id="message" name="message" required rows="5"
                              class="mt-1.5 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                              placeholder="How can we help?">{{ old('message') }}</textarea>
                </div>

                <button type="submit"
                        class="tap focusable mt-2 flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                    Send message
                </button>
            </form>
        </div>
        </div>

        <p class="mt-6 text-center text-[13.5px] text-muted">
            Ready to get started?
            <a href="{{ route('demo.request') }}" class="font-semibold text-brand hover:underline">Try a demo</a>
        </p>
    </section>
</main>

@include('marketing.partials.footer')

</body>
</html>
