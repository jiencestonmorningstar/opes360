@php
    $title = match ($verdict) {
        'found' => $document->title,
        'locked' => 'Password required',
        'expired' => 'Link expired',
        'revoked' => 'Link no longer available',
        default => 'Unknown link',
    };
@endphp

<x-layouts.public :title="$title" :brand-company="$company ?? null" robots="noindex" width="max-w-[560px]">
    @if (in_array($verdict, ['unknown', 'expired', 'revoked']))
        <div class="card flex flex-col items-center p-7 text-center">
            <span class="flex size-[74px] items-center justify-center rounded-full bg-surface-2">
                <x-icon name="alert" class="size-9 text-faint" stroke-width="1.8" />
            </span>
            <h1 class="mt-4 text-[23px] font-bold tracking-[-0.02em] text-faint">{{ $title }}</h1>
            <p class="mt-1.5 max-w-[320px] text-[14px] leading-snug text-muted">
                @if ($verdict === 'expired')
                    This link's expiry date has passed. Ask whoever sent it for a new one.
                @elseif ($verdict === 'revoked')
                    Whoever shared this has withdrawn access to it.
                @else
                    This link does not match a document anyone has shared.
                @endif
            </p>
        </div>
    @elseif ($verdict === 'locked')
        <div class="card p-7">
            <h1 class="text-[19px] font-bold text-ink">This document is password-protected</h1>
            <p class="mt-1 text-[13.5px] text-muted">Ask whoever shared it with you for the password.</p>

            @if ($errors->any())
                <p class="mt-3 text-[13.5px] font-semibold text-negative">{{ $errors->first() }}</p>
            @endif

            <form method="POST" action="{{ route('shares.unlock', $share->share_token) }}" class="mt-4">
                @csrf
                <label for="password" class="sr-only">Password</label>
                <input id="password" name="password" type="password" required autofocus
                       class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                <button type="submit"
                        class="focusable mt-3 flex h-12 w-full items-center justify-center rounded-full bg-fill-brand text-[14.5px] font-semibold text-white hover:opacity-90">
                    View document
                </button>
            </form>
        </div>
    @else
        @php
            /*
             * §2.17 on the external copy. The status mark comes from the
             * document itself; an issued one is marked COPY because whatever a
             * share link prints is a copy, never the original. The footer
             * names the share link — not any user, and only this share's own
             * company, so a token-based page can never carry another tenant's
             * name.
             */
            $watermark = \App\Support\Watermarks::statusMark($document, isCopy: true);
            $confidentialFooter = \App\Support\Watermarks::confidentialFooter(
                $document,
                $company,
                \App\Support\Watermarks::shareIdentity($share),
            );
        @endphp
        @include('print.partials.watermark')

        <div class="card p-7">
            <p class="text-[11.5px] font-medium uppercase tracking-wide text-faint">{{ $company->name }}</p>
            <h1 class="mt-1 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $document->title }}</h1>
            @if ($document->recipient)
                <p class="mt-1 text-[13.5px] text-muted">For {{ $document->recipient }}</p>
            @endif

            <div class="prose prose-sm mt-6 max-w-none text-[14.5px] leading-relaxed text-ink-2">
                {!! $bodyHtml !!}
            </div>

            @if ($share->allow_download)
                <div class="mt-6 border-t border-border pt-5 print:hidden">
                    <button type="button" onclick="window.print()"
                            class="focusable flex h-11 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                        <x-icon name="printer" class="size-[16px]" />
                        Print or save as PDF
                    </button>
                </div>
            @endif
        </div>
    @endif
</x-layouts.public>
