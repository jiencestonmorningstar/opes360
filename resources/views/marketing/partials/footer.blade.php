{{-- Shared marketing footer, matching the "Built by …" line on auth pages. --}}
<footer class="border-t border-border">
    <div class="mx-auto max-w-6xl px-5 py-10">
        <div class="flex flex-col items-center gap-6 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="text-[20px] font-bold leading-none tracking-[-0.02em]">
                    <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                        class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
                </div>
                <p class="mt-2 text-[13.5px] text-muted">{{ config('opes.brand.tagline') }}</p>
            </div>

            <nav class="flex flex-wrap justify-center gap-x-6 gap-y-2 text-[13.5px] font-medium text-ink-2">
                <a href="{{ route('marketing.features') }}" class="hover:text-brand">Features</a>
                <a href="{{ route('marketing.pricing') }}" class="hover:text-brand">Pricing</a>
                <a href="{{ route('marketing.about') }}" class="hover:text-brand">About</a>
                <a href="{{ route('marketing.contact') }}" class="hover:text-brand">Contact</a>
                <a href="{{ route('login') }}" class="hover:text-brand">Sign in</a>
                <a href="{{ route('register') }}" class="hover:text-brand">Create your business</a>
            </nav>
        </div>

        <p class="mt-8 text-center text-[12.5px] text-muted sm:text-left">
            Built by <a href="{{ config('opes.brand.vendor_url') }}" class="font-medium text-brand hover:underline">{{ config('opes.brand.vendor') }}</a>
            &middot; &copy; {{ now()->year }}
        </p>
    </div>
</footer>
