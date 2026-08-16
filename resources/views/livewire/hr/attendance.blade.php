@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    $hours = fn (?int $minutes) => $minutes === null || $minutes === 0 ? '—' : number_format($minutes / 60, 2).' h';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Attendance</h1>
            <p class="mt-1 text-[14.5px] text-muted">Who was in, and for how long. One record per person per day — entering a day again replaces what was there.</p>
        </div>

        @can('attendance.record')
            <button type="button" wire:click="startRecording"
                    class="tap focusable shrink-0 rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white">
                Record a day
            </button>
        @endcan
    </div>

    {{--
        Said plainly on the screen, not just in the docs: somebody will
        otherwise read the hours below as a wage calculation and ask why the
        payslip disagrees.
    --}}
    <div class="mt-5 rounded-2xl border border-border bg-surface-2 px-4 py-3 text-[14px] text-ink-2">
        These hours are a record of who turned up. They are not a pay calculation —
        payroll reads the employment contract, so any absence deduction is entered
        onto a payroll run by hand, where somebody can see it.
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-3">
        <div>
            <label class="{{ $labelClass }}" for="att-from">From</label>
            <input id="att-from" type="date" wire:model.live="from" class="{{ $inputClass }}">
        </div>
        <div>
            <label class="{{ $labelClass }}" for="att-to">To</label>
            <input id="att-to" type="date" wire:model.live="to" class="{{ $inputClass }}">
        </div>
        <div>
            <label class="{{ $labelClass }}" for="att-who">Person</label>
            <select id="att-who" wire:model.live="employeeFilter" class="{{ $inputClass }}">
                <option value="">Everybody</option>
                @foreach ($people as $person)
                    <option value="{{ $person->id }}">{{ $person->name() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($open->isNotEmpty())
        <div class="mt-4 rounded-2xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-[14.5px] text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <strong>{{ $open->count() }}</strong>
            {{ Str::plural('day', $open->count()) }} clocked in with no clock-out. Those hours are missing, not zero.
        </div>
    @endif

    {{-- ───────────────────────────────────────────────────── entry form ── --}}
    @can('attendance.record')
        @if ($recording)
            <x-ui.panel class="mt-5" title="Record a day">
                <form wire:submit="save">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="{{ $labelClass }}" for="att-person">Person</label>
                            <select id="att-person" wire:model="employeeId" class="{{ $inputClass }}">
                                <option value="">Choose somebody</option>
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name() }}</option>
                                @endforeach
                            </select>
                            @error('employeeId') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="att-day">Day</label>
                            <input id="att-day" type="date" wire:model="workedOn" class="{{ $inputClass }}">
                            @error('workedOn') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="att-status">Status</label>
                            <select id="att-status" wire:model="status" class="{{ $inputClass }}">
                                @foreach ($statuses as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="att-in">Clocked in</label>
                            <input id="att-in" type="time" wire:model="checkedInAt" class="{{ $inputClass }}">
                            @error('checkedInAt') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="att-out">Clocked out</label>
                            <input id="att-out" type="time" wire:model="checkedOutAt" class="{{ $inputClass }}">
                            @error('checkedOutAt') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="att-minutes">Minutes worked <span class="font-normal text-muted">optional</span></label>
                            <input id="att-minutes" type="number" min="0" max="1440" wire:model="minutesWorked" placeholder="Worked out from the times"
                                   class="{{ $inputClass }}">
                            @error('minutesWorked') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="{{ $labelClass }}" for="att-notes">Note <span class="font-normal text-muted">optional</span></label>
                        <input id="att-notes" type="text" wire:model="notes" class="{{ $inputClass }}">
                        @error('notes') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <p class="mt-3 text-[13px] text-muted">If this person already has this day, that record is replaced.</p>

                    <div class="mt-4 flex gap-2">
                        <button type="submit"
                                class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                            Save the day
                        </button>
                        <button type="button" wire:click="cancel"
                                class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            Cancel
                        </button>
                    </div>
                </form>
            </x-ui.panel>
        @endif
    @endcan

    {{-- ──────────────────────────────────────────────────────── totals ── --}}
    <x-ui.panel class="mt-5" title="Over this period">
        @forelse ($totals as $row)
            <div wire:key="tot-{{ $row['employee']?->id }}"
                 class="{{ $loop->first ? '' : 'mt-3' }} flex items-center justify-between gap-4 rounded-xl bg-surface-2 p-4">
                <div class="min-w-0">
                    <p class="truncate text-[14.5px] font-semibold text-ink">{{ $row['employee']?->name() ?? '—' }}</p>
                    <p class="mt-0.5 text-[12.5px] text-muted">
                        {{ $row['days'] }} {{ Str::plural('day', $row['days']) }} recorded ·
                        {{ $row['present'] }} in ·
                        {{ $row['absent'] }} absent
                    </p>
                </div>
                <p class="shrink-0 text-[14.5px] font-semibold text-ink">{{ $hours($row['minutes']) }}</p>
            </div>
        @empty
            <p class="py-6 text-center text-[13.5px] text-muted">Nothing recorded between those dates.</p>
        @endforelse
    </x-ui.panel>

    {{-- ───────────────────────────────────────────────────────── days ── --}}
    <div class="mt-5 overflow-hidden rounded-2xl border border-border">
        <table class="w-full text-left text-[14.5px]">
            <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                <tr>
                    <th class="px-4 py-3">Day</th>
                    <th class="px-4 py-3">Person</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">In</th>
                    <th class="px-4 py-3">Out</th>
                    <th class="px-4 py-3">Hours</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr wire:key="rec-{{ $record->id }}" class="border-t border-border">
                        <td class="px-4 py-3 font-semibold text-ink">{{ $record->worked_on->format('D j M') }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $record->employee?->name() ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $statuses[$record->status] ?? $record->status }}</td>
                        <td class="px-4 py-3 text-ink-2">{{ $record->checked_in_at?->format('H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-2">
                            {{ $record->checked_out_at?->format('H:i') ?? ($record->isOpen() ? 'Still in' : '—') }}
                        </td>
                        <td class="px-4 py-3 text-ink-2">{{ $hours($record->minutes_worked) }}</td>
                        <td class="px-4 py-3 text-right">
                            @can('attendance.record')
                                <button type="button" wire:click="edit('{{ $record->id }}')"
                                        class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                    Edit
                                </button>
                                <button type="button" wire:click="remove('{{ $record->id }}')"
                                        class="tap focusable ml-2 rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                    Remove
                                </button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[13.5px] text-muted">No days in this range.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
