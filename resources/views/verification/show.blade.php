@php
    use App\Support\Money;

    // The verdict is the page: one large unambiguous state, details after.
    [$tone, $icon, $headline, $line] = match ($verdict) {
        'valid' => ['positive', 'check-circle', 'Verified authentic', 'This record was issued through OPES360 and has not been altered.'],
        'voided' => ['muted', 'alert', 'Voided', 'This record was cancelled by the business that issued it.'],
        'tampered' => ['warning', 'alert', 'Content mismatch', 'This record does not match what was originally issued. Treat it with caution.'],
        default => ['warning', 'alert', 'Unknown code', 'This code does not match any record issued through OPES360.'],
    };

    $toneText = ['positive' => 'text-positive', 'warning' => 'text-warning', 'muted' => 'text-faint'][$tone];
    $toneTint = ['positive' => 'bg-tint-green', 'warning' => 'bg-tint-orange', 'muted' => 'bg-surface-2'][$tone];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $headline }} · {{ config('opes.brand.name') }}</title>
    <meta name="robots" content="noindex">

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

{{-- No app shell: the scanner is a customer, not a user. One column, no nav. --}}
<body class="flex min-h-full flex-col items-center px-5 py-10">
<main class="w-full max-w-[440px]">

    {{-- Verdict --}}
    <div class="card flex flex-col items-center p-7 text-center">
        <span class="flex size-[74px] items-center justify-center rounded-full {{ $toneTint }}">
            <x-icon :name="$icon" class="size-9 {{ $toneText }}" stroke-width="1.8" />
        </span>

        <h1 class="mt-4 text-[23px] font-bold tracking-[-0.02em] {{ $toneText }}">{{ $headline }}</h1>
        <p class="mt-1.5 max-w-[320px] text-[14px] leading-snug text-muted">{{ $line }}</p>

        @if ($company)
            <div class="mt-5 w-full border-t border-border pt-5">
                <p class="text-[11.5px] font-medium uppercase tracking-wide text-faint">Issued by</p>
                <p class="mt-1 text-[17px] font-bold text-ink">{{ $company->name }}</p>
                @if ($company->motto)
                    <p class="text-[13px] text-muted">{{ $company->motto }}</p>
                @endif
            </div>
        @endif
    </div>

    {{-- Record details --}}
    @if ($document)
        <div class="card mt-4 p-6">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[16px] font-bold text-ink">{{ $document->type->label() }}</h2>
                <span class="tnum text-[14px] font-semibold text-muted">{{ $document->number }}</span>
            </div>

            <dl class="mt-4 space-y-3">
                @foreach ([
                    ['Amount', Money::format($document->total, $document->currency), true],
                    ['Customer', $document->contact?->displayName() ?? '—', false],
                    ['Issued', $document->issue_date?->format('F j, Y') ?? '—', false],
                    ['Status', $document->paymentState()['label'], false],
                ] as [$label, $value, $strong])
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-[13.5px] text-muted">{{ $label }}</dt>
                        <dd class="{{ $strong ? 'tnum text-[16px] font-bold text-ink' : 'text-[13.5px] font-semibold text-ink-2' }}">
                            {{ $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    @if ($receipt)
        <div class="card mt-4 p-6">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[16px] font-bold text-ink">Receipt</h2>
                <span class="tnum text-[14px] font-semibold text-muted">{{ $receipt->number }}</span>
            </div>

            <dl class="mt-4 space-y-3">
                @foreach ([
                    ['Amount', Money::format($receipt->total, $receipt->currency), true],
                    ['Customer', $receipt->contact?->displayName() ?? '—', false],
                    ['Payment method', $receipt->payment?->method->label() ?? '—', false],
                    ['Date', $receipt->issued_at?->format('F j, Y · g:ia') ?? '—', false],
                    ['Status', ucfirst($receipt->status), false],
                ] as [$label, $value, $strong])
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-[13.5px] text-muted">{{ $label }}</dt>
                        <dd class="{{ $strong ? 'tnum text-[16px] font-bold text-ink' : 'text-[13.5px] font-semibold text-ink-2' }}">
                            {{ $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    @if ($subjectKind === 'company' && $company)
        <div class="card mt-4 p-6">
            <h2 class="text-[16px] font-bold text-ink">Business details</h2>
            <dl class="mt-4 space-y-3">
                @foreach (array_filter([
                    'Industry' => $company->industry,
                    'Registration' => $company->registration_number,
                    'Email' => $company->email,
                    'Website' => $company->website,
                    'Location' => trim(($company->city ? $company->city.', ' : '').($company->country ?? ''), ', ') ?: null,
                ]) as $label => $value)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-[13.5px] text-muted">{{ $label }}</dt>
                        <dd class="text-right text-[13.5px] font-semibold text-ink-2">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    <p class="mt-7 text-center text-[12px] text-faint">
        Verified by <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
        · <a href="{{ config('opes.brand.vendor_url') }}" class="hover:underline">{{ config('opes.brand.vendor') }}</a>
    </p>
</main>
</body>
</html>
