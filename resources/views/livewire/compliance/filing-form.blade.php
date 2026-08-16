{{-- Recording that an obligation was met. Shown inline against the row it
     belongs to, so nobody files the wrong quarter from a modal. --}}
@error('filing')
    <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
@enderror

<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div>
        <label class="{{ $labelClass }}">Filed on</label>
        <input type="date" wire:model="completedOn" class="{{ $inputClass }}">
        @error('completedOn') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="{{ $labelClass }}">Period</label>
        <input type="text" wire:model="periodLabel" placeholder="2026 Q2" class="{{ $inputClass }}">
    </div>
    <div>
        <label class="{{ $labelClass }}">Their reference</label>
        <input type="text" wire:model="reference" placeholder="Receipt number" class="{{ $inputClass }}">
    </div>
    <div>
        <label class="{{ $labelClass }}">What it cost</label>
        <input type="number" step="0.01" wire:model="amount" placeholder="Optional" class="{{ $inputClass }}">
        @error('amount') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="{{ $labelClass }}">Evidence</label>
        <select wire:model="evidenceId" class="{{ $inputClass }}">
            <option value="">No document yet</option>
            @foreach ($documents as $document)
                <option value="{{ $document->id }}">{{ $document->title }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-[12.5px] text-muted">An ordinary document — upload it in Documents first.</p>
    </div>
    <div>
        <label class="{{ $labelClass }}">Notes</label>
        <input type="text" wire:model="filingNotes" placeholder="Optional" class="{{ $inputClass }}">
    </div>
</div>

@if ($obligation->requires_approval)
    <p class="mt-3 text-[13.5px] text-muted">This one needs signing off, so it counts as filed once it is approved.</p>
@elseif ($obligation->repeats())
    <p class="mt-3 text-[13.5px] text-muted">
        The next one will then be due
        {{ $obligation->nextDueAfter($obligation->next_due_on, \Illuminate\Support\Carbon::parse($completedOn ?: now()))?->toFormattedDateString() }}.
    </p>
@endif

<div class="mt-3 flex gap-2">
    <button type="button" wire:click="file"
            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
        Record it as filed
    </button>
    <button type="button" wire:click="cancel"
            class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
        Cancel
    </button>
</div>
