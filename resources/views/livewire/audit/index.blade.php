@php
    use App\Support\AuditSubjects;

    $inputClass = 'h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[12.5px] font-semibold text-ink-2';

    $tone = fn (string $event) => match ($event) {
        'created' => 'bg-tint-green text-positive',
        'deleted', 'trashed' => 'bg-tint-red text-negative',
        'accessed', 'exported' => 'bg-tint-amber text-warning',
        'permission-changed' => 'bg-tint-red text-negative',
        default => 'bg-tint-blue text-brand',
    };

    $filtered = $actorId || $event || $subjectType || $subjectId || $from || $to || $search;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="min-w-0">
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Audit trail</h1>
        <p class="mt-1 text-[14.5px] text-muted">
            Who did what, to which record, and when. Entries are never edited or removed — including for records that
            have since been deleted.
        </p>
    </div>

    <div class="card mt-5 p-4 lg:p-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="{{ $labelClass }}" for="audit-actor">Person</label>
                <select id="audit-actor" wire:model.live="actorId" class="{{ $inputClass }}">
                    <option value="">Everyone</option>
                    @foreach ($actors as $actor)
                        <option value="{{ $actor->id }}">{{ $actor->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="{{ $labelClass }}" for="audit-event">Action</label>
                <select id="audit-event" wire:model.live="event" class="{{ $inputClass }}">
                    <option value="">Any action</option>
                    @foreach ($events as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="{{ $labelClass }}" for="audit-type">Kind of record</label>
                <select id="audit-type" wire:model.live="subjectType" class="{{ $inputClass }}">
                    <option value="">Anything</option>
                    @foreach ($subjectTypes as $class => $label)
                        <option value="{{ $class }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="{{ $labelClass }}" for="audit-search">Record name</label>
                <input id="audit-search" type="search" wire:model.live.debounce.400ms="search"
                       class="{{ $inputClass }}" placeholder="Invoice number, customer…">
            </div>

            <div>
                <label class="{{ $labelClass }}" for="audit-from">From</label>
                <input id="audit-from" type="date" wire:model.live="from" class="{{ $inputClass }}">
            </div>

            <div>
                <label class="{{ $labelClass }}" for="audit-to">To</label>
                {{-- Inclusive: an entry made at 23:47 on this date is in the range. --}}
                <input id="audit-to" type="date" wire:model.live="to" class="{{ $inputClass }}">
            </div>

            @if ($subjectId)
                <div class="sm:col-span-2 lg:col-span-2 flex items-end">
                    <p class="text-[13px] text-muted">
                        Showing one record only.
                        <button type="button" wire:click="$set('subjectId', '')" class="font-semibold text-brand underline">Show all records</button>
                    </p>
                </div>
            @endif
        </div>

        @if ($filtered)
            <button type="button" wire:click="clearFilters"
                    class="tap focusable mt-4 text-[13.5px] font-semibold text-brand">Clear filters</button>
        @endif
    </div>

    <div class="card mt-5 divide-y divide-border">
        @forelse ($entries as $entry)
            @php $summary = AuditSubjects::summarise($entry); @endphp
            <div class="flex items-start gap-3 p-4">
                <span class="mt-0.5 shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-bold {{ $tone($entry->event) }}">
                    {{ AuditSubjects::eventLabel($entry->event) }}
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-[14.5px] font-semibold text-ink">
                        {{ $entry->subject_label ?? AuditSubjects::typeLabel($entry->subject_type) }}
                        <span class="font-normal text-muted">· {{ AuditSubjects::typeLabel($entry->subject_type) }}</span>
                    </p>

                    @if ($summary !== '')
                        <p class="mt-0.5 text-[13.5px] text-muted">{{ $summary }}</p>
                    @endif

                    <p class="mt-1 text-[12.5px] text-faint">
                        {{ $entry->user?->name ?? data_get($entry->properties, 'platform_admin', 'System') }}
                        · {{ $entry->created_at?->format('j M Y, H:i') }}
                        @if ($entry->ip) · {{ $entry->ip }} @endif
                    </p>
                </div>

                @if ($entry->subject_id)
                    <button type="button"
                            wire:click="$set('subjectType', @js($entry->subject_type)); $set('subjectId', @js($entry->subject_id))"
                            class="tap focusable shrink-0 text-[13px] font-semibold text-brand">This record</button>
                @endif
            </div>
        @empty
            <div class="p-8 text-center">
                <p class="text-[14.5px] font-semibold text-ink">Nothing to show</p>
                <p class="mt-1 text-[13.5px] text-muted">
                    @if ($filtered)
                        No entries match those filters.
                    @else
                        Changes to customers, products, invoices, payments and staff records will appear here as they happen.
                    @endif
                </p>
            </div>
        @endforelse
    </div>

    <div class="mt-5">{{ $entries->links() }}</div>

    <p class="mt-5 text-[12.5px] leading-relaxed text-faint">
        Opening a payslip or a restricted document is recorded as “Opened”, once per person per record per
        {{ $readWindow }} minutes. Ordinary reading — invoices, customers, stock — is not recorded, because a trail
        that logged every glance would be too noisy to search when it mattered.
    </p>
</div>
