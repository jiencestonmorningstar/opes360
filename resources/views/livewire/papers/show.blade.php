@php
    use App\Support\Accent;

    $state = $paper->state();
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('papers') }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back to documents">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <div class="min-w-0">
            <h1 class="truncate text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
                {{ $paper->title }}
            </h1>
            <p class="mt-0.5 text-[13.5px] text-muted">
                {{ $paper->templateName() }}@if ($paper->reference) · {{ $paper->reference }}@endif
            </p>
        </div>
    </div>

    @if (session('paperError'))
        <p class="mt-4 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
            {{ session('paperError') }}
        </p>
    @endif

    @if ($paper->isTampered())
        <p class="mt-4 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
            This document no longer matches the copy that was issued. Treat any printed version with caution.
        </p>
    @endif

    <div class="mt-5 grid gap-4 lg:grid-cols-3">

        {{-- The document itself --}}
        <div class="lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="prose-paper px-6 py-7 lg:px-8">
                    {!! $bodyHtml !!}
                </div>

                @if ($notice)
                    <p class="border-t border-border bg-tint-orange/60 px-5 py-3 text-[12px] leading-snug text-warning">
                        {{ $notice }}
                    </p>
                @endif
            </div>
        </div>

        {{-- Meta + actions --}}
        <div class="space-y-4">
            <x-ui.panel title="Status">
                <div class="space-y-3 text-[14px]">
                    <div class="flex items-center justify-between">
                        <span class="text-muted">State</span>
                        <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" />
                    </div>
                    @if ($paper->reference)
                        <div class="flex items-center justify-between">
                            <span class="text-muted">Reference</span>
                            <span class="tnum font-semibold text-ink">{{ $paper->reference }}</span>
                        </div>
                    @endif
                    @if ($paper->recipient)
                        <div class="flex items-center justify-between gap-3">
                            <span class="shrink-0 text-muted">For</span>
                            <span class="truncate font-medium text-ink">{{ $paper->recipient }}</span>
                        </div>
                    @endif
                    <div class="flex items-center justify-between">
                        <span class="text-muted">Created</span>
                        <span class="text-ink-2">{{ $paper->created_at->format('j M Y') }}</span>
                    </div>
                    @if ($paper->issued_at)
                        <div class="flex items-center justify-between">
                            <span class="text-muted">Issued</span>
                            <span class="text-ink-2">{{ $paper->issued_at->format('j M Y') }}</span>
                        </div>
                    @endif
                </div>

                <div class="mt-5 space-y-2.5">
                    <a href="{{ route('papers.print', $paper) }}" target="_blank"
                       class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-brand text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
                        <x-icon name="printer" class="size-[18px]" stroke-width="2" />
                        Print / PDF
                    </a>

                    @if ($paper->isDraft())
                        @can('update', $paper)
                            <a href="{{ route('papers.edit', $paper) }}"
                               class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                                <x-icon name="document" class="size-[18px]" stroke-width="2" />
                                Edit
                            </a>
                        @endcan

                        @can('papers.issue')
                            <button type="button" wire:click="issue" wire:loading.attr="disabled"
                                    class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                                <x-icon name="check-circle" class="size-[18px]" stroke-width="2" />
                                Issue
                            </button>
                        @endcan
                    @endif

                    @if ($paper->verificationToken)
                        <a href="{{ route('verification.show', $paper->verificationToken->token) }}" target="_blank"
                           class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                            <x-icon name="qr-code" class="size-[18px]" stroke-width="2" />
                            Share / Verify
                        </a>
                    @endif

                    @if ($paper->isIssued() && auth()->user()->can('void', $paper))
                        <button type="button" wire:click="openVoid"
                                class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl text-[14px] font-semibold text-negative transition-colors hover:bg-tint-orange">
                            <x-icon name="alert" class="size-[18px]" stroke-width="2" />
                            Void Document
                        </button>
                    @endif
                </div>
            </x-ui.panel>

            @if ($voidingOpen)
                <x-ui.panel title="Void this document">
                    <p class="text-[13.5px] text-muted">
                        The document stays on record and its QR code will report it as void to anyone who scans a printed copy.
                    </p>
                    <label class="mt-3 block">
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Reason <span class="font-normal text-faint">(optional)</span></span>
                        <input type="text" wire:model="voidReason"
                               class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @error('voidReason') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>
                    <div class="mt-4 flex gap-3">
                        <button type="button" wire:click="closeVoid"
                                class="focusable h-11 flex-1 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 hover:bg-surface">
                            Cancel
                        </button>
                        <button type="button" wire:click="voidPaper"
                                class="focusable h-11 flex-1 rounded-xl bg-negative text-[14px] font-semibold text-white hover:opacity-90">
                            Void
                        </button>
                    </div>
                </x-ui.panel>
            @endif
        </div>
    </div>
</div>
