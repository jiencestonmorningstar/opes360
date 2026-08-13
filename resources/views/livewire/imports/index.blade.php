@php
    use App\Services\RecordImporter;

    // Built here rather than with @if inside the <code> block: Blade only
    // recognises a directive when it is not preceded by a word character, so
    // `…,length@else` silently stops being a directive and the template breaks.
    $sample = $type === 'products'
        ? "name,sku,price,cost,unit\nCiment 50kg,CIM-50,6500,5800,bag\nFer à béton 12mm,FER-12,3850,3400,length"
        : "name,phone,email,city\nBoulangerie Nkolbisson,+237670000000,contact@boulangerie.cm,Yaoundé\nGarage Akwa,+237699000000,,Douala";
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="mx-auto max-w-3xl">

        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Import records</h1>
        <p class="mt-1 text-[14.5px] text-muted">
            Bring your existing customers and products in from a spreadsheet, so you do not retype them.
        </p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
                {{ session('status') }}
            </div>
        @endif

        <div class="no-scrollbar mt-5 flex gap-2" role="group" aria-label="What to import">
            @foreach (RecordImporter::TYPES as $key => $label)
                @php $isActive = $type === $key; @endphp
                <button type="button" wire:click="setType('{{ $key }}')"
                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                        class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                               {{ $isActive ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- What the file should look like. Shown before the upload rather than
             in a help page, because the moment somebody needs it is the moment
             they are staring at the file picker. --}}
        <div class="card mt-4 p-5">
            <p class="text-[13.5px] font-semibold text-ink">What your file needs</p>
            <p class="mt-1.5 text-[13.5px] leading-relaxed text-muted">
                An Excel (.xlsx) or CSV file with a header row. The only column that must be there is the
                {{ $type === 'products' ? 'product name' : 'customer name' }} — everything else is optional.
                Column names are matched loosely, in English or French.
            </p>

            <div class="mt-3 overflow-x-auto rounded-lg border border-border bg-surface-2 p-3">
                <code class="whitespace-pre text-[12.5px] text-ink-2">{{ $sample }}</code>
            </div>

            <p class="mt-3 text-[12.5px] text-faint">
                Excel files import directly — no need to convert them first.
                Up to {{ number_format(RecordImporter::MAX_ROWS) }} rows and 5 MB per import.
            </p>
        </div>

        <div class="card mt-4 p-5">
            <label for="import-file" class="block text-[13.5px] font-semibold text-ink-2">Choose your file</label>
            <input id="import-file" type="file" wire:model="file" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                   class="mt-2 block w-full text-[14px] text-ink-2 file:mr-3 file:rounded-lg file:border-0 file:bg-fill-brand file:px-4 file:py-2.5 file:text-[13.5px] file:font-semibold file:text-white hover:file:opacity-90">

            <div wire:loading wire:target="file" class="mt-3 text-[13.5px] font-medium text-muted">Reading the file…</div>

            @error('file') <p class="mt-2 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror

            @if ($error)
                <div class="mt-3 rounded-xl bg-tint-red px-4 py-3 text-[13.5px] font-medium text-negative">{{ $error }}</div>
            @endif
        </div>

        @if ($previewed)
            <div class="card mt-4 p-5">
                <p class="text-[15.5px] font-bold text-ink">
                    {{ count($rows) }} {{ Str::plural('row', count($rows)) }} ready to import
                </p>

                <div class="mt-3 space-y-2 text-[13px]">
                    <p class="text-muted">
                        <span class="font-semibold text-ink-2">Using these columns:</span>
                        {{ implode(', ', $matched) }}
                    </p>

                    @if ($unmatched !== [])
                        {{-- Said out loud rather than silently dropped: a column
                             the importer ignored is exactly the thing somebody
                             expected it to read. --}}
                        <p class="text-muted">
                            <span class="font-semibold text-warning">Ignored:</span>
                            {{ implode(', ', $unmatched) }}
                        </p>
                    @endif
                </div>

                @if ($skipped !== [])
                    <div class="mt-4 rounded-xl bg-tint-orange px-4 py-3">
                        <p class="text-[13px] font-semibold text-warning">
                            {{ count($skipped) }} {{ Str::plural('row', count($skipped)) }} will be skipped
                        </p>
                        <ul class="mt-1.5 space-y-1 text-[12.5px] text-ink-2">
                            @foreach (array_slice($skipped, 0, 8) as $skip)
                                <li>Line {{ $skip['line'] }} — {{ $skip['reason'] }}</li>
                            @endforeach
                            @if (count($skipped) > 8)
                                <li class="text-faint">…and {{ count($skipped) - 8 }} more.</li>
                            @endif
                        </ul>
                    </div>
                @endif

                @if ($rows !== [])
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-[13px]">
                            <thead>
                                <tr class="border-b border-border text-[12px] font-semibold uppercase tracking-wide text-faint">
                                    @foreach (array_keys(Arr::except($rows[0], ['line'])) as $field)
                                        <th class="whitespace-nowrap px-2 py-2">{{ str_replace('_', ' ', $field) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_slice($rows, 0, 5) as $row)
                                    <tr class="border-b border-border/60 text-ink-2">
                                        @foreach (Arr::except($row, ['line']) as $value)
                                            <td class="whitespace-nowrap px-2 py-2">{{ $value !== '' ? $value : '—' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if (count($rows) > 5)
                            <p class="mt-2 px-2 text-[12.5px] text-faint">Showing the first 5 of {{ count($rows) }}.</p>
                        @endif
                    </div>

                    <button type="button" wire:click="commit" wire:loading.attr="disabled" wire:target="commit"
                            class="tap focusable mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
                        <span wire:loading.remove wire:target="commit">Import {{ count($rows) }} {{ Str::plural('record', count($rows)) }}</span>
                        <span wire:loading wire:target="commit">Importing…</span>
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>
