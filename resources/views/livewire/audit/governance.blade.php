@php
    $tabClass = fn (bool $on) => $on
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[13.5px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[13.5px] font-semibold text-ink-2 hover:bg-tint-neutral';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="min-w-0">
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Governance</h1>
        <p class="mt-1 text-[14.5px] text-muted">
            Who holds which permissions, and where one person can complete a whole money cycle alone.
        </p>
    </div>

    <div class="mt-5 flex gap-1.5">
        <button type="button" wire:click="$set('tab', 'conflicts')" class="tap focusable {{ $tabClass($tab === 'conflicts') }}">
            Conflicts
            @if ($findings->isNotEmpty())
                <span class="ml-1 rounded-full bg-white/25 px-1.5 text-[12px]">{{ $findings->flatten(1)->count() }}</span>
            @endif
        </button>
        <button type="button" wire:click="$set('tab', 'matrix')" class="tap focusable {{ $tabClass($tab === 'matrix') }}">Who holds what</button>
        <button type="button" wire:click="$set('tab', 'retention')" class="tap focusable {{ $tabClass($tab === 'retention') }}">Retention</button>
    </div>

    @if ($tab === 'retention')

        <div class="card mt-5 max-w-2xl p-5">
            <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">How long the trail is kept</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                Entries older than this are removed by a nightly sweep, and each sweep leaves its own entry in the
                trail saying what it removed. Some entries stay longer whatever is chosen here: records of who read a
                confidential document are kept at least {{ \App\Support\AuditRetention::FLOOR_ACCESS_MONTHS }} months,
                ordinary changes at least {{ \App\Support\AuditRetention::FLOOR_GENERAL_MONTHS }} months, and anything
                touching money, exports or permissions at least
                {{ \App\Support\AuditRetention::FLOOR_FINANCIAL_MONTHS / 12 }} years. Documents under legal hold keep
                their entire history for as long as the hold stands.
            </p>

            <form wire:submit="saveRetention" class="mt-4 flex items-end gap-3">
                <div>
                    <label for="retention-months" class="block text-[12.5px] font-semibold text-ink-2">Months kept</label>
                    <input id="retention-months" type="number" wire:model="retentionMonths"
                        min="{{ \App\Support\AuditRetention::FLOOR_ACCESS_MONTHS }}" max="600"
                        class="focusable mt-1 w-28 rounded-lg border border-border bg-transparent px-3 py-2 text-[14px] text-ink" />
                </div>
                <button type="submit" class="tap focusable rounded-full bg-fill-brand px-4 py-2 text-[13.5px] font-semibold text-white">Save</button>
                <span wire:loading.remove x-data x-show="false" x-on:saved.window="$el.style.display=''; setTimeout(() => $el.style.display='none', 2000)"
                    class="text-[13px] font-semibold text-positive">Saved</span>
            </form>
            @error('retentionMonths')
                <p class="mt-2 text-[13px] text-negative">{{ $message }}</p>
            @enderror
        </div>

    @elseif ($tab === 'conflicts')

        @if ($findings->isEmpty())
            <div class="card mt-5 p-8 text-center">
                <p class="text-[15px] font-semibold text-positive">No conflicting permissions</p>
                <p class="mx-auto mt-1 max-w-lg text-[13.5px] leading-relaxed text-muted">
                    Nobody outside the owners and administrators can both authorise and carry out the same act.
                </p>
            </div>
        @else
            <div class="mt-5 space-y-4">
                @foreach ($findings as $key => $group)
                    @php $rule = $group->first()['rule']; @endphp
                    <div class="card p-5">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-bold {{ $rule['severity'] === 'high' ? 'bg-tint-red text-negative' : 'bg-tint-amber text-warning' }}">
                                {{ $rule['severity'] === 'high' ? 'Serious' : 'Worth reviewing' }}
                            </span>
                            <div class="min-w-0">
                                <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">{{ $rule['name'] }}</h2>
                                <p class="mt-1 text-[13.5px] leading-relaxed text-muted">{{ $rule['why'] }}</p>
                            </div>
                        </div>

                        <ul class="mt-4 space-y-2 border-t border-border pt-4">
                            @foreach ($group as $finding)
                                <li class="flex items-center justify-between gap-3">
                                    <span class="text-[14px] font-semibold text-ink">{{ $finding['user']->name }}</span>
                                    <span class="text-[13px] text-muted">{{ $finding['role']?->name ?? 'No role' }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <p class="mt-4 text-[12.5px] text-faint">
                            Holds {{ implode(' and ', $rule['abilities']) }}. Fix it by moving one of the two to
                            somebody else in Team &rarr; permissions.
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($unavoidable->isNotEmpty())
            <div class="card mt-5 p-5">
                <h2 class="text-[15px] font-bold tracking-[-0.02em] text-ink">Owners and administrators</h2>
                <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                    {{ $unavoidable->pluck('name')->join(', ', ' and ') }}
                    hold every permission by design, so every separation above is absent for them. That is not a
                    mistake to fix — it is the price of being the account's final authority — but it is worth knowing
                    who those people are.
                </p>
            </div>
        @endif

    @else

        <div class="card mt-5 overflow-x-auto">
            <table class="w-full min-w-[640px] text-left">
                <thead>
                    <tr class="border-b border-border">
                        <th class="p-4 text-[12.5px] font-semibold text-ink-2">Person</th>
                        <th class="p-4 text-[12.5px] font-semibold text-ink-2">Role</th>
                        <th class="p-4 text-[12.5px] font-semibold text-ink-2">Sensitive permissions held</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($holdings as $row)
                        <tr>
                            <td class="p-4 text-[14px] font-semibold text-ink">{{ $row['user']->name }}</td>
                            <td class="p-4 text-[13.5px] text-muted">{{ $row['role']?->name ?? 'No role' }}</td>
                            <td class="p-4 text-[13px] text-muted">
                                {{ $row['abilities'] === [] ? 'None' : implode(', ', $row['abilities']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-[12.5px] leading-relaxed text-faint">
            Only the permissions that take part in a conflict rule are listed — the full catalogue would be a wall of
            text nobody reads. Every entry is the live answer, so a one-off grant or revoke made by hand shows here
            exactly as it will behave.
        </p>

    @endif
</div>
