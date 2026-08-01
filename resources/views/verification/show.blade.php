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

<x-layouts.public :title="$headline" robots="noindex" width="max-w-[440px]">
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

    {{-- Feedback from a staff action (ticket check-in) --}}
    @if (session('status'))
        <div class="card mt-4 border-positive/40 bg-tint-green px-5 py-4 text-[13.5px] font-semibold text-positive">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->has('ticket'))
        <div class="card mt-4 border-warning/40 bg-tint-orange px-5 py-4 text-[13.5px] font-semibold text-warning">
            {{ $errors->first('ticket') }}
        </div>
    @endif
    @if ($errors->has('points'))
        <div class="card mt-4 border-warning/40 bg-tint-orange px-5 py-4 text-[13.5px] font-semibold text-warning">
            {{ $errors->first('points') }}
        </div>
    @endif

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

    @if ($paper ?? null)
        <div class="card mt-4 p-6">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[16px] font-bold text-ink">{{ $paper->templateName() }}</h2>
                <span class="tnum text-[14px] font-semibold text-muted">{{ $paper->reference }}</span>
            </div>

            <dl class="mt-4 space-y-3">
                @foreach (array_filter([
                    'Document' => $paper->title,
                    'For' => $paper->recipient,
                    'Issued' => $paper->issued_at?->format('F j, Y'),
                ]) as $label => $value)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-[13.5px] text-muted">{{ $label }}</dt>
                        <dd class="text-right text-[13.5px] font-semibold text-ink-2">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            {{-- The wording matters: this page confirms the document is
                 genuine and unaltered, not that its contents are agreed. --}}
            <p class="mt-4 border-t border-border pt-4 text-[12.5px] leading-snug text-muted">
                This confirms the document was issued by {{ $company?->name }} and has not been altered since.
                It does not confirm that either party has signed it.
            </p>
        </div>
    @endif

    @if ($ticket ?? null)
        <div class="card mt-4 p-6">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[16px] font-bold text-ink">{{ $ticket->event?->title ?? 'Event ticket' }}</h2>
                <span class="tnum text-[14px] font-semibold text-muted">{{ $ticket->serial }}</span>
            </div>

            {{-- The second scan of the same ticket must not look like the
                 first: door staff act on this line, not the verdict above. --}}
            @if ($ticket->isCheckedIn())
                <div class="mt-4 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-semibold text-warning">
                    Already checked in {{ $ticket->checked_in_at?->format('M j · g:ia') }}
                </div>
            @endif

            <dl class="mt-4 space-y-3">
                @foreach (array_filter([
                    'Ticket' => $ticket->ticketType?->name,
                    'Holder' => $ticket->buyer_name,
                    'When' => $ticket->event?->starts_at?->format('D, F j, Y · g:ia'),
                    'Venue' => $ticket->event?->venue,
                    'Price' => (float) $ticket->price > 0 ? Money::format($ticket->price, $company?->currency) : 'Free',
                ]) as $label => $value)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-[13.5px] text-muted">{{ $label }}</dt>
                        <dd class="text-right text-[13.5px] font-semibold text-ink-2">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @auth
                @if (! $ticket->isCheckedIn() && ! $ticket->isVoid())
                    @can('checkIn', $ticket)
                        <form method="POST" action="{{ route('tickets.check-in', $ticket) }}" class="mt-5">
                            @csrf
                            <button type="submit"
                                    class="tap focusable flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                                <x-icon name="check-circle" class="size-[20px]" stroke-width="2" />
                                Check in
                            </button>
                        </form>
                    @endcan
                @endif
            @endauth
        </div>
    @endif

    @if ($loyaltyCard ?? null)
        <div class="card mt-4 p-6">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[16px] font-bold text-ink">Loyalty card</h2>
                <span class="tnum text-[14px] font-semibold text-muted">{{ $loyaltyCard->loyalty_card_number }}</span>
            </div>

            <dl class="mt-4 space-y-3">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-[13.5px] text-muted">Member</dt>
                    <dd class="text-right text-[13.5px] font-semibold text-ink-2">{{ $loyaltyCard->displayName() }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-[13.5px] text-muted">Points balance</dt>
                    <dd class="tnum text-[16px] font-bold text-ink">{{ $loyaltyCard->loyalty_points }}</dd>
                </div>
                @if ($company && $company->loyaltyPointValue() > 0)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-[13.5px] text-muted">Worth</dt>
                        <dd class="tnum text-[13.5px] font-semibold text-ink-2">{{ Money::format($loyaltyCard->loyalty_points * $company->loyaltyPointValue(), $company->currency) }}</dd>
                    </div>
                @endif
            </dl>

            @auth
                @can('loyalty.redeem')
                    <form method="POST" action="{{ route('loyalty.redeem', $loyaltyCard) }}" class="mt-5 flex items-end gap-2 border-t border-border pt-4">
                        @csrf
                        <div class="min-w-0 flex-1">
                            <label class="mb-1 block text-[12px] font-semibold text-ink-2">Redeem points</label>
                            <input type="number" name="points" min="1" max="{{ $loyaltyCard->loyalty_points }}" required
                                   class="h-11 w-full rounded-lg border border-border bg-surface px-3 text-[14px] text-ink focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand/20">
                        </div>
                        <button type="submit"
                                class="tap focusable h-11 shrink-0 rounded-lg bg-fill-brand px-4 text-[13.5px] font-semibold text-white transition-opacity hover:opacity-90">
                            Redeem
                        </button>
                    </form>
                @endcan
            @endauth
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
</x-layouts.public>
