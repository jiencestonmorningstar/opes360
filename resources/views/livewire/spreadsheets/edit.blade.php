<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('spreadsheets.index') }}" wire:navigate
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back to spreadsheets">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <div class="min-w-0 flex-1">
            <input type="text" wire:model="title"
                   class="w-full truncate border-0 bg-transparent p-0 text-[20px] font-bold leading-tight tracking-[-0.02em] text-ink focus:outline-none focus:ring-0"
                   aria-label="Spreadsheet title">
            @error('title') <p class="text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
        </div>
        <button type="button" wire:click="save"
                class="focusable shrink-0 rounded-xl bg-fill-brand px-4 py-2 text-[13px] font-semibold text-white hover:opacity-90">
            Save
        </button>
    </div>

    <p class="mt-2 text-[13px] text-muted">
        A cell starting with <code class="rounded bg-surface-2 px-1 py-0.5">=</code> is a formula — reference another
        cell (<code class="rounded bg-surface-2 px-1 py-0.5">=A1+B2</code>), or pull a live figure with
        <code class="rounded bg-surface-2 px-1 py-0.5">=OPES_SUM("invoices","total")</code> or
        <code class="rounded bg-surface-2 px-1 py-0.5">=OPES_LOOKUP("invoices","INV-00001","balance")</code>.
    </p>

    <div class="mt-4 overflow-x-auto rounded-xl border border-border">
        <table class="w-full border-collapse text-[13px]">
            <thead>
                <tr>
                    <th class="w-10 border-b border-r border-border bg-surface-2 p-1"></th>
                    @foreach ($columns as $column)
                        <th class="min-w-[9rem] border-b border-r border-border bg-surface-2 p-1 text-center font-semibold text-ink-2">
                            {{ $column }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="border-b border-r border-border bg-surface-2 p-1 text-center font-semibold text-faint">{{ $row }}</td>
                        @foreach ($columns as $column)
                            @php($ref = $column.$row)
                            <td class="border-b border-r border-border p-0">
                                <input type="text" wire:model.blur="cells.{{ $ref }}"
                                       placeholder="{{ data_get($results, $ref) !== null ? data_get($results, $ref) : '' }}"
                                       class="w-full border-0 bg-transparent px-2 py-1.5 text-ink focus:bg-tint-blue focus:outline-none focus:ring-0"
                                       title="{{ $ref }}">
                                @if (($cells[$ref] ?? '') !== '' && str_starts_with($cells[$ref], '='))
                                    <div class="truncate px-2 pb-1 text-[11px] text-faint">
                                        {{ data_get($results, $ref) }}
                                    </div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
