<div class="px-5 pb-8 lg:px-6 lg:pt-6" wire:poll.30s="heartbeat">

    <div class="flex items-center gap-3">
        <button type="button" wire:click="close"
                class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back to document">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </button>
        <div class="min-w-0 flex-1">
            @if ($editable)
                <input type="text" wire:model.blur="title"
                       class="w-full truncate border-0 bg-transparent p-0 text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink focus:outline-none focus:ring-0 lg:text-[25px]"
                       aria-label="Document title">
            @else
                <h1 class="truncate text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
                    {{ $paper->title }}
                </h1>
            @endif
            <p class="mt-0.5 text-[13.5px] text-muted">
                {{ $paper->templateName() }} · Draft
                <span wire:loading.remove wire:target="save" class="text-faint">· Saved</span>
                <span wire:loading wire:target="save" class="text-faint">· Saving…</span>
            </p>
        </div>
        @error('title') <p class="text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
        @error('body') <p class="text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
    </div>

    {{-- Who is editing --}}
    @if (! $editable)
        <div class="mt-4 flex items-center justify-between rounded-xl bg-tint-blue px-4 py-3">
            <p class="text-[13.5px] font-medium text-brand">
                @if ($holder)
                    {{ $holder->name }} is editing — you are reading.
                @else
                    You are reading. Take over to edit.
                @endif
            </p>
            @can('update', $paper)
                <button type="button" wire:click="requestTakeover"
                        class="focusable rounded-lg bg-surface px-3 py-1.5 text-[13px] font-semibold text-ink-2 hover:text-brand">
                    {{ $holder ? 'Request takeover' : 'Take over' }}
                </button>
            @endcan
        </div>
    @endif

    <div class="mt-5 grid gap-4 lg:grid-cols-3">

        {{-- The editor island. wire:ignore: Tiptap owns this DOM; Livewire must never morph it. --}}
        <div class="lg:col-span-2">
            <div class="card overflow-hidden"
                 x-data="opesRichEditor({ content: @js($body), editable: @js($editable), availableTokens: @js($availableTokens) })"
                 x-on:editor-set-content.window="setContent($event.detail.html)">

                <div x-show="editable" class="flex flex-wrap items-center gap-1 border-b border-border px-3 py-2" role="toolbar" aria-label="Formatting">
                    <template x-for="button in buttons" :key="button.label">
                        <button type="button" x-on:click="run(button.action)"
                                :class="{ 'bg-tint-blue text-brand': isActive(button) }"
                                class="focusable rounded-lg px-2.5 py-1.5 text-[13px] font-semibold text-ink-2 hover:bg-surface-2"
                                :aria-label="button.label" x-text="button.text"></button>
                    </template>
                    <span class="mx-1 h-5 w-px bg-border" aria-hidden="true"></span>
                    <button type="button" x-on:click="commentOnCurrentBlock()"
                            class="focusable rounded-lg px-2.5 py-1.5 text-[13px] font-semibold text-ink-2 hover:bg-surface-2"
                            aria-label="Comment on this paragraph">
                        Comment here
                    </button>
                </div>

                <div wire:ignore>
                    <div x-ref="editor" class="prose-paper min-h-[420px] px-6 py-7 lg:px-8"></div>
                </div>
            </div>
        </div>

        {{-- Sidebar: presence, versions, comments --}}
        <div class="space-y-4">
            <x-ui.panel title="Editing">
                <div class="space-y-2 text-[14px]">
                    <div class="flex items-center justify-between">
                        <span class="text-muted">Currently editing</span>
                        <span class="font-medium text-ink">
                            {{ $editable ? 'You' : ($holder?->name ?? 'Nobody') }}
                        </span>
                    </div>
                    @if ($editable)
                        <button type="button" wire:click="releaseLock"
                                class="focusable mt-1 w-full rounded-xl bg-surface-2 py-2 text-[13px] font-semibold text-ink-2 hover:text-brand">
                            Stop editing (release lock)
                        </button>
                    @endif
                </div>
            </x-ui.panel>

            <x-ui.panel title="Versions">
                <ul class="space-y-2.5 text-[13.5px]">
                    @forelse ($versions as $version)
                        <li class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate text-ink-2">
                                <span class="tnum font-semibold">v{{ $version->version_number }}</span>
                                · {{ $version->creator?->name ?? 'System' }}
                                · {{ $version->created_at->diffForHumans(short: true) }}
                            </span>
                            @if ($editable && ! $loop->first)
                                <button type="button" wire:click="restoreVersion('{{ $version->id }}')"
                                        wire:confirm="Replace the current content with version {{ $version->version_number }}? The current content is kept as its own version."
                                        class="focusable shrink-0 rounded-lg px-2 py-1 text-[12.5px] font-semibold text-brand hover:bg-tint-blue">
                                    Restore
                                </button>
                            @endif
                        </li>
                    @empty
                        <li class="text-muted">No versions yet.</li>
                    @endforelse
                </ul>
            </x-ui.panel>

            <x-ui.panel title="Comments">
                <form wire:submit="postComment" class="mb-3">
                    @if ($commentAnchorId)
                        <div class="mb-2 flex items-center justify-between rounded-lg bg-tint-blue px-2.5 py-1.5 text-[12.5px] font-medium text-brand">
                            <span>Pinned to a specific paragraph</span>
                            <button type="button" wire:click="clearCommentAnchor" class="focusable font-semibold hover:underline">Clear</button>
                        </div>
                    @endif
                    <textarea wire:model="commentBody" rows="2" placeholder="Leave a remark…"
                              class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-[13.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                    @error('commentBody') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    <button type="submit"
                            class="focusable mt-2 w-full rounded-xl bg-fill-brand py-2 text-[13px] font-semibold text-white hover:opacity-90">
                        Comment
                    </button>
                </form>
                <ul class="space-y-3 text-[13.5px]">
                    @forelse ($comments as $comment)
                        <li>
                            <p class="font-semibold text-ink">{{ $comment->author?->name ?? 'Someone' }}
                                <span class="ml-1 font-normal text-faint">{{ $comment->created_at->diffForHumans(short: true) }}</span>
                                @if ($comment->anchor_id)
                                    <span class="ml-1 rounded bg-tint-blue px-1.5 py-0.5 text-[11px] font-semibold text-brand">on paragraph</span>
                                @endif
                            </p>
                            <p class="mt-0.5 whitespace-pre-line text-ink-2">{{ $comment->body }}</p>
                        </li>
                    @empty
                        <li class="text-muted">No comments yet.</li>
                    @endforelse
                </ul>
            </x-ui.panel>
        </div>
    </div>
</div>
