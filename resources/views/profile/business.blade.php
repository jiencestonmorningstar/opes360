@php
    use App\Support\Accent;
    use App\Support\Money;
    use Illuminate\Support\Facades\Storage;

    $phones = collect($company->phones ?? []);
    $whatsapp = data_get($company->socials, 'whatsapp');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $company->name }} · {{ config('opes.brand.name') }}</title>
    <meta name="description" content="{{ $company->motto ?? $company->name }}">

    <script>
        (function () {
            try {
                var stored = localStorage.getItem('opes-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored === 'dark' || (stored !== 'light' && system));
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css'])
</head>

<body class="flex min-h-full flex-col items-center px-5 py-8">
<main class="w-full max-w-[480px]">

    {{-- Identity card --}}
    <div class="card flex flex-col items-center p-7 text-center">
        @if ($company->logo_path)
            <img src="{{ Storage::disk('public')->url($company->logo_path) }}" alt=""
                 class="size-[84px] rounded-2xl object-cover">
        @else
            <span class="flex size-[84px] items-center justify-center rounded-2xl bg-tint-blue text-[26px] font-bold text-accent-blue">
                {{ $company->initials() }}
            </span>
        @endif

        <h1 class="mt-4 text-[24px] font-bold tracking-[-0.02em] text-ink">{{ $company->name }}</h1>

        @if ($company->motto)
            <p class="mt-1 text-[14.5px] text-muted">{{ $company->motto }}</p>
        @endif

        <div class="mt-3 flex items-center gap-1.5 rounded-full bg-tint-green px-3.5 py-1.5">
            <x-icon name="check-circle" class="size-[16px] text-positive" stroke-width="2.2" />
            <span class="text-[12.5px] font-semibold text-positive">Verified business</span>
            @if ($businessToken)
                <a href="{{ route('verification.show', $businessToken->token) }}" class="sr-only">Verification details</a>
            @endif
        </div>

        @if ($company->description)
            <p class="mt-4 text-[14px] leading-relaxed text-ink-2">{{ $company->description }}</p>
        @endif

        {{-- Instant actions --}}
        <div class="mt-6 grid w-full grid-cols-4 gap-2">
            @if ($phones->isNotEmpty())
                <a href="tel:{{ $phones->first() }}" class="focusable flex flex-col items-center gap-1.5 rounded-xl py-3 hover:bg-surface-2">
                    <span class="flex size-[46px] items-center justify-center rounded-full bg-tint-blue"><x-icon name="bell" class="size-[21px] text-accent-blue" /></span>
                    <span class="text-[11.5px] font-medium text-ink-2">Call</span>
                </a>
            @endif
            @if ($whatsapp)
                <a href="https://wa.me/{{ preg_replace('/\D+/', '', $whatsapp) }}" class="focusable flex flex-col items-center gap-1.5 rounded-xl py-3 hover:bg-surface-2">
                    <span class="flex size-[46px] items-center justify-center rounded-full bg-tint-green"><x-icon name="users" class="size-[21px] text-accent-green" /></span>
                    <span class="text-[11.5px] font-medium text-ink-2">WhatsApp</span>
                </a>
            @endif
            @if ($company->email)
                <a href="mailto:{{ $company->email }}" class="focusable flex flex-col items-center gap-1.5 rounded-xl py-3 hover:bg-surface-2">
                    <span class="flex size-[46px] items-center justify-center rounded-full bg-tint-purple"><x-icon name="document" class="size-[21px] text-accent-purple" /></span>
                    <span class="text-[11.5px] font-medium text-ink-2">Email</span>
                </a>
            @endif
            <a href="{{ route('profile.vcard', $company) }}" class="focusable flex flex-col items-center gap-1.5 rounded-xl py-3 hover:bg-surface-2">
                <span class="flex size-[46px] items-center justify-center rounded-full bg-tint-orange"><x-icon name="user-plus" class="size-[21px] text-accent-orange" /></span>
                <span class="text-[11.5px] font-medium text-ink-2">Save</span>
            </a>
        </div>
    </div>

    {{-- Details --}}
    <div class="card mt-4 p-6">
        <h2 class="text-[16px] font-bold text-ink">Details</h2>
        <dl class="mt-4 space-y-3">
            @foreach (array_filter([
                'Industry' => $company->industry,
                'Registration' => $company->registration_number,
                'Phone' => $phones->first(),
                'Email' => $company->email,
                'Website' => $company->website,
                'Address' => trim(implode(', ', array_filter([$company->address_line1, $company->city, $company->region])), ', ') ?: null,
            ]) as $label => $value)
                <div class="flex items-start justify-between gap-4">
                    <dt class="shrink-0 text-[13.5px] text-muted">{{ $label }}</dt>
                    <dd class="text-right text-[13.5px] font-semibold text-ink-2 break-all">
                        @if ($label === 'Website')
                            <a href="{{ str_starts_with($value, 'http') ? $value : 'https://'.$value }}"
                               class="text-brand hover:underline">{{ $value }}</a>
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- Products & services --}}
    @if ($items->isNotEmpty())
        <div class="card mt-4 p-6">
            <h2 class="text-[16px] font-bold text-ink">Products &amp; Services</h2>
            <div class="mt-4 space-y-1">
                @foreach ($items as $index => $item)
                    <div class="flex items-center gap-3 rounded-lg py-2">
                        <span class="flex size-[36px] shrink-0 items-center justify-center rounded-lg {{ Accent::tint(Accent::byIndex($index)) }}">
                            <x-icon name="cube" class="size-[18px] {{ Accent::text(Accent::byIndex($index)) }}" />
                        </span>
                        <span class="min-w-0 flex-1 truncate text-[14px] font-medium text-ink">{{ $item->name }}</span>
                        <span class="tnum shrink-0 text-[14px] font-semibold text-ink-2">{{ Money::format($item->price, $company->currency) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <p class="mt-7 text-center text-[12px] text-faint">
        Powered by <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
        · <a href="{{ config('opes.brand.vendor_url') }}" class="hover:underline">{{ config('opes.brand.vendor') }}</a>
    </p>
</main>
</body>
</html>
