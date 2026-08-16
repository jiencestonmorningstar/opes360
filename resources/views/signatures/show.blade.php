@php
    $isUnknown = $verdict === 'unknown';
    $isSigned = ! $isUnknown && $signature->isSigned();
    $isDeclined = ! $isUnknown && $signature->status === 'declined';
    $isPending = ! $isUnknown && $signature->isPending();
@endphp

<x-layouts.public
    :title="$isUnknown ? 'Unknown signing link' : 'Sign — '.$document->title"
    :brand-company="$company ?? null"
    robots="noindex"
    width="max-w-[440px]"
>
    @if ($isUnknown)
        <div class="card flex flex-col items-center p-7 text-center">
            <span class="flex size-[74px] items-center justify-center rounded-full bg-surface-2">
                <x-icon name="alert" class="size-9 text-faint" stroke-width="1.8" />
            </span>
            <h1 class="mt-4 text-[23px] font-bold tracking-[-0.02em] text-faint">Unknown signing link</h1>
            <p class="mt-1.5 max-w-[320px] text-[14px] leading-snug text-muted">
                This link does not match anything waiting to be signed. It may already have been used, or the request may have been withdrawn.
            </p>
        </div>
    @else
        <div class="card p-7">
            <p class="text-[11.5px] font-medium uppercase tracking-wide text-faint">{{ $company->name }}</p>
            <h1 class="mt-1 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $document->title }}</h1>
            <p class="mt-1 text-[13.5px] text-muted">For {{ $signature->signer_name }}</p>

            @if ($status['total'] > 1)
                <p class="mt-3 text-[12.5px] text-faint">
                    {{ $status['signed'] }} of {{ $status['total'] }} signed
                    @if ($document->signature_mode === 'sequential') · signed in order @endif
                </p>
            @endif
        </div>

        @if (session('status'))
            <div class="card mt-4 border-positive/40 bg-tint-green px-5 py-4 text-[13.5px] font-semibold text-positive">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="card mt-4 border-warning/40 bg-tint-orange px-5 py-4 text-[13.5px] font-semibold text-warning">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($isSigned)
            <div class="card mt-4 flex items-center gap-3 p-5">
                <x-icon name="check-circle" class="size-6 shrink-0 text-positive" stroke-width="1.8" />
                <p class="text-[14px] text-ink">
                    Signed {{ $signature->signed_at->format('j M Y, g:ia') }}.
                </p>
            </div>
            {{-- Phase 5: a signer keeps a copy of what they executed. --}}
            <a href="{{ route('signatures.show', ['token' => $signature->signing_token, 'format' => 'pdf']) }}"
               class="focusable mt-4 flex h-12 w-full items-center justify-center gap-2 rounded-full border border-border bg-surface text-[14px] font-semibold text-ink-2 hover:bg-surface-2">
                <x-icon name="download" class="size-[16px]" />
                Download PDF
            </a>
        @elseif ($isDeclined)
            <div class="card mt-4 p-5">
                <p class="text-[14px] font-semibold text-ink">Declined</p>
                @if ($signature->declined_reason)
                    <p class="mt-1 text-[13.5px] text-muted">{{ $signature->declined_reason }}</p>
                @endif
            </div>
        @elseif ($isPending)
            <div class="card mt-4 p-6">
                <form method="POST" action="{{ route('signatures.sign', $signature->signing_token) }}">
                    @csrf
                    <label for="typed_name" class="text-[13px] font-semibold text-ink-2">
                        Type your name to sign
                    </label>
                    <input id="typed_name" name="typed_name" type="text" required
                           value="{{ old('typed_name', $signature->signer_name) }}"
                           class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    <p class="mt-1.5 text-[12px] text-faint">
                        Typing your name and submitting is your signature. The link, the time and your IP address are recorded with it.
                    </p>
                    <button type="submit"
                            class="focusable mt-4 flex h-12 w-full items-center justify-center rounded-full bg-fill-brand text-[14.5px] font-semibold text-white hover:opacity-90">
                        Sign
                    </button>
                </form>

                <details class="mt-4">
                    <summary class="cursor-pointer text-[13px] font-semibold text-muted">I need to decline instead</summary>
                    <form method="POST" action="{{ route('signatures.decline', $signature->signing_token) }}" class="mt-3">
                        @csrf
                        <label for="reason" class="text-[13px] font-semibold text-ink-2">Why?</label>
                        <textarea id="reason" name="reason" required rows="3"
                                  class="mt-1.5 w-full rounded-xl border border-border bg-surface p-3 text-[14px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                        <button type="submit"
                                class="focusable mt-3 flex h-11 w-full items-center justify-center rounded-full border border-border bg-surface text-[13.5px] font-semibold text-negative hover:bg-surface-2">
                            Decline to sign
                        </button>
                    </form>
                </details>
            </div>
        @endif
    @endif
</x-layouts.public>
