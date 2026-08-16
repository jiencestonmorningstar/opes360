@php
    use App\Support\AuditSubjects;
@endphp

<div class="card p-5">
    <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">History</h2>
    <p class="mt-1 text-[13.5px] text-muted">Everything that has happened to this record, newest first.</p>

    @if ($entries->isEmpty())
        <p class="mt-4 text-[13.5px] text-muted">Nothing has been recorded against this record yet.</p>
    @else
        <ol class="mt-4 space-y-3">
            @foreach ($entries as $entry)
                @php $summary = AuditSubjects::summarise($entry); @endphp
                <li class="flex items-start gap-3">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full bg-brand"></span>
                    <div class="min-w-0">
                        <p class="text-[14px] text-ink">
                            <span class="font-semibold">{{ $entry->user?->name ?? data_get($entry->properties, 'platform_admin', 'System') }}</span>
                            {{ \Illuminate\Support\Str::lower(AuditSubjects::eventLabel($entry->event)) }}
                            @if ($summary !== '') <span class="text-muted">— {{ $summary }}</span> @endif
                        </p>
                        <p class="mt-0.5 text-[12.5px] text-faint">{{ $entry->created_at?->format('j M Y, H:i') }}</p>
                    </div>
                </li>
            @endforeach
        </ol>

        @if (! $expanded && $entries->count() >= $limit)
            <button type="button" wire:click="showAll"
                    class="tap focusable mt-4 text-[13.5px] font-semibold text-brand">Show the full history</button>
        @endif
    @endif
</div>
