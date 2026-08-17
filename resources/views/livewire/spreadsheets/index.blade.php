<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">Spreadsheets</h1>
        <button type="button" wire:click="create"
                class="focusable rounded-xl bg-fill-brand px-4 py-2 text-[13px] font-semibold text-white hover:opacity-90">
            New spreadsheet
        </button>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($sheets as $sheet)
            <a href="{{ route('spreadsheets.edit', $sheet) }}" wire:navigate
               class="card block p-4 hover:border-brand/40">
                <p class="truncate font-semibold text-ink">{{ $sheet->title }}</p>
                <p class="mt-1 text-[13px] text-muted">
                    {{ $sheet->creator?->name ?? 'Someone' }}
                    · {{ $sheet->updated_at->diffForHumans(short: true) }}
                </p>
            </a>
        @empty
            <p class="col-span-full text-muted">No spreadsheets yet — create one to pull live figures into a grid.</p>
        @endforelse
    </div>
</div>
