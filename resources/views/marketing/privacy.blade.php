<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Privacy Policy · {{ config('opes.brand.name') }}</title>

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

<main class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
    <h1 class="text-[30px] font-bold tracking-[-0.02em] text-ink sm:text-[36px]">Privacy Policy</h1>
    <p class="mt-2 text-[13.5px] text-faint">Last updated {{ now()->format('F Y') }}</p>

    <div class="mt-10 space-y-8 text-[15px] leading-relaxed text-ink-2">
        <section>
            <h2 class="text-[17px] font-bold text-ink">What we collect</h2>
            <p class="mt-2">
                When you request a demo, register a business, or use {{ config('opes.brand.name') }}, we collect the
                information you give us directly — your name, email, phone number, and business details — and the
                records your business creates while using the product: customers, sales documents, payments, and
                similar operational data. We do not collect more than the product needs to function.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">How we use it</h2>
            <p class="mt-2">
                Account and business data is used to run the product for you: authenticating sign-ins, generating
                documents, sending the notifications you or your team opted into, and — where you've enabled it —
                emailing receipts, verification links, and loyalty updates. We do not sell customer data to third
                parties, and we do not use your business's operational data to train anything.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Multi-tenancy</h2>
            <p class="mt-2">
                {{ config('opes.brand.name') }} is a multi-tenant system: each business's data is scoped and kept
                separate from every other business on the platform. Staff you invite into your business see only
                what their role permits; nothing is shared across unrelated businesses.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Public verification pages</h2>
            <p class="mt-2">
                Documents, receipts, tickets, and loyalty cards carry a QR code linking to a public verification
                page. That page shows only what is printed on the physical or digital artifact itself (e.g. an
                invoice's amount and status, a ticket holder's name) — never your full customer list or business
                financials.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Email</h2>
            <p class="mt-2">
                All outbound mail — account notifications, receipts, contact-form replies, demo credentials — is
                sent through standard SMTP. We do not route your data through third-party marketing or analytics
                platforms.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Your choices</h2>
            <p class="mt-2">
                You can request a copy of your data, ask us to correct it, or ask us to delete your account and its
                data, by writing to us via the <a href="{{ route('marketing.contact') }}" class="font-semibold text-brand hover:underline">contact page</a>.
                Demo accounts expire automatically after 14 days if not converted to a trial.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Questions</h2>
            <p class="mt-2">
                Reach {{ config('opes.brand.vendor') }} any time through the <a href="{{ route('marketing.contact') }}" class="font-semibold text-brand hover:underline">contact form</a>.
            </p>
        </section>
    </div>
</main>

@include('marketing.partials.footer')

</body>
</html>
