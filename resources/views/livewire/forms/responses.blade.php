<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('forms') }}" class="focusable inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand hover:underline">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        All forms
    </a>

    <div class="mt-4 flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="truncate text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $form->title }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $total }} {{ Str::plural('response', $total) }}
            </p>
        </div>

        @if ($total > 0)
            <a href="{{ route('forms.responses.csv', $form) }}"
               class="focusable flex h-11 shrink-0 items-center gap-2 rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-ink-2 hover:bg-surface-2">
                Export CSV
            </a>
        @endif
    </div>

    {{-- Per-option tallies for choice fields --}}
    @if ($summaries !== [])
        <div class="mt-5 grid gap-3 min-[640px]:grid-cols-2">
            @foreach ($summaries as $fieldId => $summary)
                @php $max = max(1, max($summary['counts'] ?: [0])); @endphp
                <div wire:key="sum-{{ $fieldId }}" class="card p-5">
                    <p class="text-[14px] font-semibold text-ink">{{ $summary['label'] ?: 'Untitled' }}</p>
                    <div class="mt-3 space-y-2.5">
                        @foreach ($summary['counts'] as $option => $count)
                            <div>
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-[13px] text-ink-2">{{ $option }}</span>
                                    <span class="tnum text-[13px] font-semibold text-muted">{{ $count }}</span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-2">
                                    <div class="h-full rounded-full bg-fill-brand" style="width: {{ round($count / $max * 100) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Individual responses, expandable --}}
    <div class="mt-5 space-y-2.5">
        @forelse ($responses as $response)
            <div wire:key="resp-{{ $response->id }}" x-data="{ open: false }" class="card">
                <button type="button" @click="open = !open"
                        class="focusable flex w-full items-center justify-between gap-3 p-4 text-left">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[14.5px] font-semibold text-ink">
                            {{ collect($response->answers)->first(fn ($v) => is_string($v) && trim($v) !== '') ?? 'Response' }}
                        </span>
                        <span class="block text-[12.5px] text-muted">{{ $response->created_at->format('D, M j · g:ia') }}</span>
                    </span>
                    <x-icon name="chevron-down" class="size-[17px] shrink-0 text-faint transition-transform" ::class="open && 'rotate-180'" stroke-width="2" />
                </button>

                <div x-show="open" x-cloak class="border-t border-border px-4 py-4">
                    <dl class="space-y-3">
                        @foreach ($fields as $field)
                            <div>
                                <dt class="text-[12.5px] font-medium text-muted">{{ $field['label'] ?: 'Untitled' }}</dt>
                                <dd class="mt-0.5 whitespace-pre-line text-[14px] text-ink">
                                    {{ $response->answerFor($field['id']) !== '' ? $response->answerFor($field['id']) : '—' }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        @empty
            <div class="card px-5 py-12 text-center">
                <p class="text-[15px] font-semibold text-ink">No responses yet.</p>
                <p class="mt-1.5 text-[13.5px] text-muted">Share the form's link — responses appear here the moment they arrive.</p>
            </div>
        @endforelse
    </div>

    @if ($responses->hasPages())
        <div class="mt-5">{{ $responses->links() }}</div>
    @endif
</div>
