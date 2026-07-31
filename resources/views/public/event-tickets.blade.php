@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Your tickets · {{ $event->title }}</title>
    <meta name="robots" content="noindex">

    <script @cspNonce>
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

<body class="flex min-h-full flex-col items-center px-5 py-10">
<main class="w-full max-w-[440px]">

    <div class="text-center">
        <span class="inline-flex size-[60px] items-center justify-center rounded-full bg-tint-green">
            <x-icon name="check-circle" class="size-8 text-positive" stroke-width="1.8" />
        </span>
        <h1 class="mt-3 text-[24px] font-bold tracking-[-0.02em] text-ink">You're in!</h1>
        <p class="mt-1 text-[14px] text-muted">
            {{ count($tickets) }} {{ Str::plural('ticket', count($tickets)) }} for {{ $event->title }}.
            <strong class="font-semibold text-ink-2">Screenshot or save this page</strong> — the QR is the ticket.
        </p>
    </div>

    <div class="mt-6 space-y-4">
        @foreach ($tickets as $ticket)
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-dashed border-border px-5 py-4">
                    <div class="min-w-0">
                        <p class="truncate text-[15px] font-bold text-ink">{{ $event->title }}</p>
                        <p class="text-[12.5px] text-muted">
                            {{ $ticket->ticketType?->name }}
                            · {{ (float) $ticket->price > 0 ? Money::format($ticket->price, $company->currency) : 'Free' }}
                        </p>
                    </div>
                    <span class="tnum shrink-0 text-[12.5px] font-semibold text-muted">{{ $ticket->serial }}</span>
                </div>

                <div class="flex flex-col items-center px-5 py-6">
                    @if ($ticket->verificationToken)
                        <span class="block size-[190px] overflow-hidden rounded-xl bg-white p-2 [&>svg]:h-full [&>svg]:w-full">
                            {!! app(App\Services\QrCodes::class)->svg($ticket->verificationToken->publicUrl()) !!}
                        </span>
                        <a href="{{ $ticket->verificationToken->publicUrl() }}"
                           class="focusable mt-3 text-[12.5px] font-semibold text-brand hover:underline">
                            Open ticket link
                        </a>
                    @endif

                    <dl class="mt-4 w-full space-y-2 border-t border-border pt-4">
                        @foreach (array_filter([
                            'Holder' => $ticket->buyer_name,
                            'When' => $event->starts_at->format('D, M j · g:ia'),
                            'Venue' => $event->venue,
                        ]) as $label => $value)
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-[13px] text-muted">{{ $label }}</dt>
                                <dd class="text-right text-[13px] font-semibold text-ink-2">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        @endforeach
    </div>

    <p class="mt-7 text-center text-[12px] text-faint">
        Ticketed with <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
        · Show the QR at the door.
    </p>
</main>
</body>
</html>
