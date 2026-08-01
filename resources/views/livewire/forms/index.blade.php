<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Forms</h1>
            <p class="mt-1 text-[14.5px] text-muted">Build a form, share the link, watch the responses come in.</p>
        </div>

        @can('forms.create')
            <button type="button" wire:click="createForm"
                    class="tap focusable flex h-11 shrink-0 items-center gap-2 rounded-xl bg-fill-brand px-4 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.2" />
                New form
            </button>
        @endcan
    </div>

    <div class="relative mt-6">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.250ms="search"
               placeholder="Search your forms…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="mt-4 space-y-2.5">
        @forelse ($forms as $form)
            @php $state = $form->state(); @endphp
            <div wire:key="form-{{ $form->id }}" class="card flex items-center gap-3.5 p-4">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-tint-purple">
                    <x-icon name="clipboard" class="size-[21px] text-accent-purple" stroke-width="1.9" />
                </span>

                <a href="{{ route('forms.build', $form) }}" class="focusable min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold text-ink">{{ $form->title }}</span>
                    <span class="block truncate text-[13px] text-muted">
                        {{ count($form->fields ?? []) }} {{ Str::plural('question', count($form->fields ?? [])) }}
                        · {{ $form->responses_count }} {{ Str::plural('response', $form->responses_count) }}
                    </span>
                </a>

                <span class="flex shrink-0 items-center gap-2.5">
                    @can('responses', $form)
                        <a href="{{ route('forms.responses', $form) }}"
                           class="focusable rounded-lg border border-border px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            Responses
                        </a>
                    @endcan
                    <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" />
                </span>
            </div>
        @empty
            <div class="card px-5 py-12 text-center">
                <p class="text-[15px] font-semibold text-ink">
                    {{ $search !== '' ? 'Nothing matches that search.' : 'No forms yet.' }}
                </p>
                <p class="mt-1.5 text-[13.5px] text-muted">
                    {{ $search !== '' ? 'Try a different name.' : 'Create one and share the link — registrations, feedback, orders, anything.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($forms->hasPages())
        <div class="mt-5">{{ $forms->links() }}</div>
    @endif
</div>
