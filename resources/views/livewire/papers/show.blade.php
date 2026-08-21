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
                       class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-fill-brand text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
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

                        {{-- Route::has: the editor route line ships in docs/handoff/rich-editor.md
                             and is added separately; the button appears the moment it exists. --}}
                        @can('update', $paper)
                            @if (Route::has('papers.editor'))
                                <a href="{{ route('papers.editor', $paper) }}"
                                   class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                                    <x-icon name="document" class="size-[18px]" stroke-width="2" />
                                    Open editor
                                </a>
                            @endif
                        @endcan

                        @can('update', $paper)
                            @if ($paper->isAwaitingApproval())
                                <p class="rounded-xl bg-tint-warning px-3 py-2 text-center text-[13px] font-semibold text-warning">
                                    Awaiting approval
                                </p>
                            @else
                                <button type="button" wire:click="submitForApproval" wire:loading.attr="disabled"
                                        class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                                    <x-icon name="clipboard" class="size-[18px]" stroke-width="2" />
                                    Send for approval
                                </button>
                            @endif
                        @endcan

                        @can('papers.issue')
                            <button type="button" wire:click="issue" wire:loading.attr="disabled"
                                    class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                                <x-icon name="check-circle" class="size-[18px]" stroke-width="2" />
                                Issue
                            </button>
                        @endcan
                    @endif

                    {{-- §5 item 1 of the Documents completion plan: works on a draft or an
                         issued document alike — duplicating is reading the content, never
                         editing the original, so it needs no isDraft() gate of its own. --}}
                    @can('papers.create')
                        <button type="button" wire:click="duplicate" wire:loading.attr="disabled"
                                class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                            <x-icon name="document-plus" class="size-[18px]" stroke-width="2" />
                            Duplicate
                        </button>
                    @endcan

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

            {{-- §53 side panel: activity, versions, comments, shares, signatures, legal hold. --}}
            <x-ui.panel title="Details">
                <div class="space-y-3 text-[14px]">
                    <div class="flex items-center justify-between">
                        <span class="text-muted">Versions</span>
                        <span class="tnum font-semibold text-ink">{{ $versionCount }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted">Comments</span>
                        <span class="tnum font-semibold text-ink">{{ $commentCount }}</span>
                    </div>
                    @if ($paper->isUnderLegalHold())
                        <div class="flex items-center justify-between">
                            <span class="text-muted">Legal hold</span>
                            <x-ui.status-badge label="On hold" tone="warning" />
                        </div>
                    @endif
                    @if ($shares->isNotEmpty())
                        <div class="flex items-center justify-between">
                            <span class="text-muted">Shares</span>
                            <span class="tnum font-semibold text-ink">{{ $shares->count() }} active</span>
                        </div>
                    @endif
                    @if ($signatures->isNotEmpty())
                        <div class="flex items-center justify-between">
                            <span class="text-muted">Signatures</span>
                            <span class="text-ink-2">
                                {{ $signatures->where('status', 'signed')->count() }}/{{ $signatures->count() }} signed
                            </span>
                        </div>
                    @endif
                </div>
            </x-ui.panel>

            @can('share', $paper)
                <x-ui.panel title="Signatures">
                    @if ($signatureStatus['status'] === 'not_requested' || $signatureStatus['status'] === 'declined')
                        @if (! $signatureFormOpen)
                            <button type="button" wire:click="openSignatureForm"
                                    class="focusable w-full rounded-xl border border-border bg-surface py-2.5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                Request signatures
                            </button>
                        @else
                            <div class="space-y-3">
                                <div class="flex gap-2">
                                    <button type="button" wire:click="$set('signatureMode', 'parallel')"
                                            class="focusable flex-1 rounded-lg py-2 text-[12.5px] font-semibold {{ $signatureMode === 'parallel' ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2' }}">
                                        Anyone, any order
                                    </button>
                                    <button type="button" wire:click="$set('signatureMode', 'sequential')"
                                            class="focusable flex-1 rounded-lg py-2 text-[12.5px] font-semibold {{ $signatureMode === 'sequential' ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2' }}">
                                        In order
                                    </button>
                                </div>

                                @foreach ($signers as $index => $signer)
                                    <div class="flex items-start gap-2">
                                        <div class="flex-1 space-y-1.5">
                                            <input type="text" wire:model="signers.{{ $index }}.name" placeholder="Name"
                                                   class="w-full rounded-lg border border-border bg-surface px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                            <input type="email" wire:model="signers.{{ $index }}.email" placeholder="Email"
                                                   class="w-full rounded-lg border border-border bg-surface px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                        </div>
                                        @if (count($signers) > 1)
                                            <button type="button" wire:click="removeSigner({{ $index }})"
                                                    class="focusable mt-1.5 flex size-7 items-center justify-center rounded-lg text-[16px] text-faint hover:text-warning" aria-label="Remove signer">
                                                &times;
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                                @error('signers') <p class="text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                                @error('signers.*.name') <p class="text-[12.5px] font-medium text-warning">Every signer needs a name.</p> @enderror
                                @error('signers.*.email') <p class="text-[12.5px] font-medium text-warning">Every signer needs a valid email.</p> @enderror

                                <button type="button" wire:click="addSigner"
                                        class="focusable text-[12.5px] font-semibold text-brand hover:underline">
                                    + Add another signer
                                </button>

                                <div class="flex gap-2 pt-1">
                                    <button type="button" wire:click="requestSignatures"
                                            class="focusable flex-1 rounded-xl bg-fill-brand py-2.5 text-[13.5px] font-semibold text-white hover:opacity-90">
                                        Send request
                                    </button>
                                    <button type="button" wire:click="closeSignatureForm"
                                            class="focusable rounded-xl border border-border bg-surface px-4 py-2.5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @endif
                    @else
                        <ul class="space-y-2 text-[13.5px]">
                            @foreach ($signatures as $signature)
                                <li class="flex items-center justify-between gap-2">
                                    <span class="min-w-0 truncate text-ink-2">{{ $signature->signer_name }}</span>
                                    <x-ui.status-badge
                                        :label="ucfirst($signature->status)"
                                        :tone="match ($signature->status) {
                                            'signed' => 'positive',
                                            'declined' => 'negative',
                                            default => 'neutral',
                                        }" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.panel>
            @endcan

            @can('papers.manage')
                @if ($activity->isNotEmpty())
                    <x-ui.panel title="Recent activity">
                        <ul class="space-y-3 text-[13.5px]">
                            @foreach ($activity as $event)
                                <li class="flex items-start justify-between gap-3">
                                    <span class="text-ink-2">{{ $event['summary'] }}</span>
                                    <span class="shrink-0 text-[12px] text-faint">{{ $event['at']?->diffForHumans() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.panel>
                @endif
            @endcan

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
                                class="focusable h-11 flex-1 rounded-xl bg-fill-negative text-[14px] font-semibold text-white hover:opacity-90">
                            Void
                        </button>
                    </div>
                </x-ui.panel>
            @endif
        </div>
    </div>
</div>
