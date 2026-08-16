@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $pill = 'tap focusable rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2';
    $primary = 'tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Service promises</h1>
            <p class="mt-1 text-[14.5px] text-muted">How long we have to answer, how long to fix, and when we are open.</p>
        </div>

        <div class="flex shrink-0 gap-2">
            <a href="{{ route('service') }}" wire:navigate class="{{ $pill }}">Queue</a>
            <button type="button" wire:click="startNew" class="{{ $primary }}">Add a policy</button>
        </div>
    </div>

    @if ($creating)
        <div class="mt-5 rounded-2xl border border-border p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="{{ $labelClass }}">Name</label>
                    <input type="text" wire:model="name" placeholder="Hospital contract" class="{{ $inputClass }}">
                    @error('name') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">Counted in</label>
                    <select wire:model="clock" class="{{ $inputClass }}">
                        <option value="business">Working hours</option>
                        <option value="calendar">Round the clock</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Timezone</label>
                    <input type="text" wire:model="timezone" placeholder="Africa/Douala" class="{{ $inputClass }}">
                    @error('timezone') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">Use by default</label>
                    <select wire:model="isDefault" class="{{ $inputClass }}">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>
            </div>

            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="create" class="{{ $primary }}">Add it</button>
                <button type="button" wire:click="$set('creating', false)" class="{{ $pill }}">Cancel</button>
            </div>
        </div>
    @endif

    <div class="mt-5 grid gap-4 lg:grid-cols-4">

        {{-- ──────────────────────────────────────────────── the policies ── --}}
        <div class="space-y-2">
            @forelse ($policies as $each)
                <button type="button" wire:click="select('{{ $each->id }}')"
                        class="tap focusable w-full rounded-2xl border px-4 py-3 text-left {{ $policyId === $each->id ? 'border-brand bg-fill-2' : 'border-border' }}">
                    <span class="block text-[15px] font-semibold text-ink">{{ $each->name }}</span>
                    <span class="block text-[13px] text-muted">
                        {{ $each->clock === 'calendar' ? 'Round the clock' : 'Working hours' }}
                        · {{ $each->tickets_count }} {{ Str::plural('ticket', $each->tickets_count) }}
                        @if ($each->is_default) · default @endif
                    </span>
                </button>
            @empty
                <p class="rounded-2xl border border-border px-4 py-8 text-center text-muted">
                    Nothing promised yet.
                </p>
            @endforelse
        </div>

        <div class="space-y-4 lg:col-span-3">
            @if ($policy === null)
                <p class="rounded-2xl border border-border px-4 py-10 text-center text-muted">
                    Pick a policy, or add one.
                </p>
            @else
                {{-- ──────────────────────────────────────────── targets ── --}}
                <div class="rounded-2xl border border-border p-4">
                    <p class="text-[13px] font-semibold uppercase tracking-wide text-muted">What we promise, per priority</p>
                    <p class="mt-1 text-[13.5px] text-muted">
                        In minutes of working time. Leave a box empty where nothing was promised —
                        an empty box is not zero.
                    </p>

                    <div class="mt-3 overflow-hidden rounded-xl border border-border">
                        <table class="w-full text-left text-[14.5px]">
                            <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                                <tr>
                                    <th class="px-4 py-3">Priority</th>
                                    <th class="px-4 py-3">Answer within</th>
                                    <th class="px-4 py-3">Fix within</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($priorities as $value => $label)
                                    <tr class="border-t border-border">
                                        <td class="px-4 py-3 font-semibold text-ink">{{ $label }}</td>
                                        <td class="px-4 py-2">
                                            <input type="number" min="1" wire:model="targets.{{ $value }}.response"
                                                   placeholder="Nothing promised" class="{{ $inputClass }}">
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="number" min="1" wire:model="targets.{{ $value }}.resolution"
                                                   placeholder="Nothing promised" class="{{ $inputClass }}">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <button type="button" wire:click="saveTargets" class="{{ $primary }} mt-3">Save targets</button>
                </div>

                {{-- ─────────────────────────────────────────── calendar ── --}}
                <div class="rounded-2xl border border-border p-4">
                    <p class="text-[13px] font-semibold uppercase tracking-wide text-muted">When we are open</p>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="{{ $labelClass }}">Name</label>
                            <input type="text" wire:model="name" class="{{ $inputClass }}">
                            @error('name') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Counted in</label>
                            <select wire:model="clock" class="{{ $inputClass }}">
                                <option value="business">Working hours</option>
                                <option value="calendar">Round the clock</option>
                            </select>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Timezone</label>
                            {{-- The weekday is read here, not in UTC: asking "is it
                                 Saturday" of a UTC instant is wrong by up to a day
                                 for anyone off Greenwich. --}}
                            <input type="text" wire:model="timezone" class="{{ $inputClass }}">
                            @error('timezone') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Use by default</label>
                            <select wire:model="isDefault" class="{{ $inputClass }}">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 space-y-2">
                        @foreach ($days as $key => $label)
                            <div class="grid items-center gap-3 sm:grid-cols-3">
                                <p class="text-[14.5px] font-semibold text-ink-2">{{ $label }}</p>
                                <input type="time" wire:model="hours.{{ $key }}.0" class="{{ $inputClass }}">
                                <input type="time" wire:model="hours.{{ $key }}.1" class="{{ $inputClass }}">
                            </div>
                        @endforeach
                        <p class="text-[13px] text-muted">Leave a day blank and it counts as closed.</p>
                    </div>

                    <div class="mt-4">
                        <label class="{{ $labelClass }}">Public holidays</label>
                        <textarea wire:model="holidays" rows="4" placeholder="2026-01-01&#10;2026-05-20"
                                  class="w-full rounded-xl border border-border bg-surface px-3.5 py-2.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                        <p class="mt-1 text-[13px] text-muted">One date per line.</p>
                    </div>

                    <button type="button" wire:click="saveCalendar" class="{{ $primary }} mt-3">Save calendar</button>

                    {{-- Named on the screen rather than left to be discovered by
                         somebody wondering why a deadline did not move. --}}
                    <p class="mt-3 text-[13.5px] text-muted">
                        Changing this does not move the deadlines on tickets already open.
                        They keep the promise that was in force when they were raised.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
