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
                <nav class="mt-2 flex flex-col text-[13.5px] font-medium text-ink-2">
                    <a href="{{ route('marketing.features') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Features</a>
                    <a href="{{ route('marketing.pricing') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Pricing</a>
                    <a href="{{ route('marketing.partners') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">For secretariats</a>
                    <a href="{{ route('demo.request') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Try a demo</a>
                    <a href="{{ route('login') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Sign in</a>
                </nav>
            </div>

            <div>
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Company</p>
                <nav class="mt-2 flex flex-col text-[13.5px] font-medium text-ink-2">
                    <a href="{{ route('marketing.about') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">About</a>
                    <a href="{{ route('marketing.blog') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Blog</a>
                    <a href="{{ route('marketing.contact') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Contact</a>
                    <a href="{{ route('register') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Create your business</a>
                </nav>
            </div>

            <div>
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Legal</p>
                <nav class="mt-2 flex flex-col text-[13.5px] font-medium text-ink-2">
                    <a href="{{ route('marketing.privacy') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Privacy</a>
                    <a href="{{ route('marketing.terms') }}" class="focusable -mx-1 rounded px-1 py-1.5 hover:text-brand">Terms</a>
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
