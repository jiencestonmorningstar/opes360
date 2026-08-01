{{-- Shared marketing footer: brand + three link columns, standard for a SaaS site. --}}
<footer class="border-t border-border">
    <div class="mx-auto max-w-6xl px-5 py-14">
        <div class="grid grid-cols-2 gap-8 sm:grid-cols-4">
            <div class="col-span-2 sm:col-span-1">
                <div class="text-[20px] font-bold leading-none tracking-[-0.02em]">
                    <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                        class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
                </div>
                <p class="mt-2.5 max-w-[220px] text-[13.5px] leading-relaxed text-muted">{{ config('opes.brand.tagline') }}</p>
            </div>

            <div>
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Product</p>
                <nav class="mt-3.5 flex flex-col gap-2.5 text-[13.5px] font-medium text-ink-2">
                    <a href="{{ route('marketing.features') }}" class="hover:text-brand">Features</a>
                    <a href="{{ route('marketing.pricing') }}" class="hover:text-brand">Pricing</a>
                    <a href="{{ route('marketing.partners') }}" class="hover:text-brand">For secretariats</a>
                    <a href="{{ route('demo.request') }}" class="hover:text-brand">Try a demo</a>
                    <a href="{{ route('login') }}" class="hover:text-brand">Sign in</a>
                </nav>
            </div>

            <div>
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Company</p>
                <nav class="mt-3.5 flex flex-col gap-2.5 text-[13.5px] font-medium text-ink-2">
                    <a href="{{ route('marketing.about') }}" class="hover:text-brand">About</a>
                    <a href="{{ route('marketing.blog') }}" class="hover:text-brand">Blog</a>
                    <a href="{{ route('marketing.contact') }}" class="hover:text-brand">Contact</a>
                    <a href="{{ route('register') }}" class="hover:text-brand">Create your business</a>
                </nav>
            </div>

            <div>
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Legal</p>
                <nav class="mt-3.5 flex flex-col gap-2.5 text-[13.5px] font-medium text-ink-2">
                    <a href="{{ route('marketing.privacy') }}" class="hover:text-brand">Privacy</a>
                    <a href="{{ route('marketing.terms') }}" class="hover:text-brand">Terms</a>
                </nav>
            </div>
        </div>

        <div class="mt-12 flex flex-col items-center justify-between gap-3 border-t border-border pt-6 sm:flex-row">
            <p class="text-[12.5px] text-muted">
                Built by <a href="{{ config('opes.brand.vendor_url') }}" class="font-medium text-brand hover:underline">{{ config('opes.brand.vendor') }}</a>
                &middot; &copy; {{ now()->year }}
            </p>
        </div>
    </div>
</footer>
