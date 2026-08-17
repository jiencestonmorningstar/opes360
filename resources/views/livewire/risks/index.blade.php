@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $tabClass = fn ($key) => $tab === $key
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[14px] font-semibold text-muted hover:text-ink';

    // The bands are the model's, not this view's — two screens disagreeing
    // about where "high" starts is a disagreement about what gets attention.
    $bandClass = fn ($band) => match ($band) {
        'severe' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        'high' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
        'moderate' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
        default => 'bg-fill-2 text-ink-2',
    };
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Risk register</h1>
            <p class="mt-1 text-[14.5px] text-muted">What could go wrong, and what is actually in place against it.</p>
        </div>

        @can('compliance.view')
            <a href="{{ route('compliance') }}" wire:navigate
               class="tap focusable shrink-0 rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
                Calendar
            </a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-4">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Open risks</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $summary['risks_open'] }}</p>
        </div>
        <div class="card p-4 {{ $summary['risks_severe'] > 0 ? 'border-rose-300/70 dark:border-rose-500/30' : '' }}">
            <p class="text-[12.5px] font-medium text-muted">Severe untreated</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] {{ $summary['risks_severe'] > 0 ? 'text-rose-600' : 'text-ink' }}">
                {{ $summary['risks_severe'] }}
            </p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Past review date</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $summary['risks_to_review'] }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Controls overdue</p>
            <p class="tnum mt-1 text-[19px] font-bold tracking-[-0.02em] text-ink">{{ $summary['controls_overdue'] }}</p>
        </div>
    </div>

    @error('risk') <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror
    @error('control') <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror

    <div class="mt-5 flex gap-1 border-b border-border pb-3">
        <button type="button" wire:click="$set('tab', 'register')" class="{{ $tabClass('register') }}">The register</button>
        <button type="button" wire:click="$set('tab', 'review')" class="{{ $tabClass('review') }}">Needs looking at</button>
    </div>

    {{-- ──────────────────────────────────────────────────── register ── --}}
    @if ($tab === 'register')
        @can('risks.manage')
            <div class="mt-5">
                @if (! $adding)
                    <button type="button" wire:click="startAdding"
                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                        Raise a risk
                    </button>
                @else
                    <div class="rounded-2xl border border-border p-4">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div class="lg:col-span-2">
                                <label class="{{ $labelClass }}">What could go wrong</label>
                                <input type="text" wire:model="title" placeholder="Generator fails during a power cut" class="{{ $inputClass }}">
                                @error('title') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Kind</label>
                                <select wire:model="category" class="{{ $inputClass }}">
                                    @foreach ($categories as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">How likely (1–5)</label>
                                <input type="number" min="1" max="5" wire:model="likelihood" class="{{ $inputClass }}">
                                @error('likelihood') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">How bad (1–5)</label>
                                <input type="number" min="1" max="5" wire:model="impact" class="{{ $inputClass }}">
                                @error('impact') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">What we intend to do</label>
                                <select wire:model="treatment" class="{{ $inputClass }}">
                                    @foreach ($treatments as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Whose risk it is</label>
                                <select wire:model="riskOwnerId" class="{{ $inputClass }}">
                                    <option value="">Me</option>
                                    @foreach ($people as $person)
                                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Look at it again every</label>
                                <input type="number" wire:model="reviewIntervalMonths" placeholder="months" class="{{ $inputClass }}">
                            </div>
                            <div class="lg:col-span-3">
                                <label class="{{ $labelClass }}">Detail</label>
                                <input type="text" wire:model="description" placeholder="Optional" class="{{ $inputClass }}">
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="raise"
                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                Add it
                            </button>
                            <button type="button" wire:click="cancel"
                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @endcan

        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Risk</th>
                        <th class="px-4 py-3">Untreated</th>
                        <th class="px-4 py-3">As it stands</th>
                        <th class="px-4 py-3">Review</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($risks as $risk)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $risk->title }}
                                <span class="block text-[13px] font-normal text-muted">
                                    {{ $risk->categoryLabel() }} · {{ $risk->owner?->name ?? 'unowned' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="tnum rounded-full px-2.5 py-1 text-[13px] font-semibold {{ $bandClass($risk->inherentBand()) }}">
                                    {{ $risk->inherentScore() }} · {{ $risk->inherentBand() }}
                                </span>
                                <span class="mt-1 block text-[12.5px] text-muted">{{ $risk->likelihood }} × {{ $risk->impact }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($risk->hasBeenReassessed())
                                    <span class="tnum rounded-full px-2.5 py-1 text-[13px] font-semibold {{ $bandClass($risk->residualBand()) }}">
                                        {{ $risk->residualScore() }} · {{ $risk->residualBand() }}
                                    </span>
                                @else
                                    {{-- No reassessment means no reduction. Saying so out loud
                                         is the point: controls on paper lower nothing. --}}
                                    <span class="text-[13.5px] text-muted">Not reassessed</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 {{ $risk->isDueForReview() ? 'font-semibold text-rose-600' : 'text-ink-2' }}">
                                {{ $risk->next_review_on?->toFormattedDateString() ?? 'Never' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="toggle('{{ $risk->id }}')"
                                        class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                    {{ $risk->controls->count() }} {{ Str::plural('control', $risk->controls->count()) }}
                                </button>
                            </td>
                        </tr>

                        @if ($open === $risk->id)
                            <tr class="border-t border-border bg-fill-2">
                                <td colspan="5" class="px-4 py-4">
                                    @if ($risk->controls->isEmpty())
                                        <p class="text-[14px] text-muted">Nothing is being done about this yet.</p>
                                    @else
                                        <ul class="space-y-2 text-[13.5px] text-ink-2">
                                            @foreach ($risk->controls as $control)
                                                <li class="flex flex-wrap items-center gap-2">
                                                    <span class="font-semibold text-ink">{{ $control->title }}</span>
                                                    <span class="text-muted">
                                                        {{ $control->kindLabel() }} · {{ $control->statusLabel() }}
                                                        @if ($control->owner) · {{ $control->owner->name }} @endif
                                                        @if ($control->due_on && ! $control->isInPlace())
                                                            · due {{ $control->due_on->toFormattedDateString() }}
                                                        @endif
                                                        @if ($control->effectiveness)
                                                            · works {{ $control->effectiveness }}/5
                                                        @endif
                                                    </span>
                                                    @can('risks.manage')
                                                        @unless ($control->isInPlace())
                                                            <button type="button" wire:click="markControlInPlace('{{ $control->id }}')"
                                                                    class="tap focusable rounded-full border border-border px-3 py-1 text-[13px] font-semibold text-ink-2">
                                                                It is in place
                                                            </button>
                                                        @endunless
                                                        @if ($control->status !== 'failed')
                                                            <button type="button" wire:click="markControlFailed('{{ $control->id }}')"
                                                                    wire:confirm="Mark this control as not working? The risk it treats deserves another look."
                                                                    class="tap focusable rounded-full border border-border px-3 py-1 text-[13px] font-semibold text-rose-600">
                                                                It is not working
                                                            </button>
                                                        @endif
                                                    @endcan
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <div class="mt-4 flex flex-wrap gap-2">
                                        @can('risks.manage')
                                            <button type="button" wire:click="startControl('{{ $risk->id }}')"
                                                    class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                                                Add a control
                                            </button>
                                        @endcan
                                        {{-- Reassessing sits behind risks.review, held apart from the
                                             person who owns the risk on purpose. --}}
                                        @can('risks.review')
                                            <button type="button" wire:click="startReassessing('{{ $risk->id }}')"
                                                    class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                                                Reassess
                                            </button>
                                            <button type="button" wire:click="startReview('{{ $risk->id }}')"
                                                    class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                                                Mark reviewed
                                            </button>
                                        @endcan
                                        @can('risks.manage')
                                            <button type="button" wire:click="startClosing('{{ $risk->id }}')"
                                                    class="tap focusable rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-rose-600">
                                                Close this risk
                                            </button>
                                        @endcan
                                    </div>

                                    @if ($closing === $risk->id)
                                        <div class="mt-4 rounded-xl border border-border bg-surface p-4">
                                            @error('closing')
                                                <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                                            @enderror

                                            <label class="{{ $labelClass }}">Why is this no longer on the register?</label>
                                            <input type="text" wire:model="closureReason"
                                                   placeholder="The supplier was replaced; the exposure is gone."
                                                   class="{{ $inputClass }}">
                                            @error('closureReason') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror

                                            <div class="mt-3 flex gap-2">
                                                <button type="button" wire:click="closeRisk"
                                                        class="tap focusable rounded-full bg-fill-red px-5 py-2 text-[14.5px] font-semibold text-white">
                                                    Close it
                                                </button>
                                                <button type="button" wire:click="cancel"
                                                        class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    @endif

                                    @if ($addingControlTo === $risk->id)
                                        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                            <div>
                                                <label class="{{ $labelClass }}">What is being done</label>
                                                <input type="text" wire:model="controlTitle" placeholder="Monthly load test" class="{{ $inputClass }}">
                                                @error('controlTitle') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                            </div>
                                            <div>
                                                <label class="{{ $labelClass }}">Kind</label>
                                                <select wire:model="controlKind" class="{{ $inputClass }}">
                                                    @foreach ($kinds as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="{{ $labelClass }}">In place by</label>
                                                <input type="date" wire:model="controlDueOn" class="{{ $inputClass }}">
                                            </div>
                                            <div>
                                                <label class="{{ $labelClass }}">Whose job</label>
                                                <select wire:model="controlOwnerId" class="{{ $inputClass }}">
                                                    <option value="">Same as the risk</option>
                                                    @foreach ($people as $person)
                                                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mt-3 flex gap-2">
                                            <button type="button" wire:click="addControl"
                                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                                Record it
                                            </button>
                                            <button type="button" wire:click="cancel"
                                                    class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                                Cancel
                                            </button>
                                        </div>
                                    @endif

                                    @if ($reassessing === $risk->id)
                                        <div class="mt-4 rounded-xl border border-border bg-surface p-4">
                                            @error('reassessing')
                                                <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                                            @enderror

                                            <p class="text-[13.5px] text-muted">
                                                Your judgement of where this stands now, given the controls that are
                                                genuinely in place. Nothing here is calculated from the list above.
                                            </p>

                                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <label class="{{ $labelClass }}">How likely now (1–5)</label>
                                                    <input type="number" min="1" max="5" wire:model="residualLikelihood" class="{{ $inputClass }}">
                                                </div>
                                                <div>
                                                    <label class="{{ $labelClass }}">How bad now (1–5)</label>
                                                    <input type="number" min="1" max="5" wire:model="residualImpact" class="{{ $inputClass }}">
                                                </div>
                                            </div>

                                            <div class="mt-3 flex gap-2">
                                                <button type="button" wire:click="reassess"
                                                        class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                                    Record the score
                                                </button>
                                                <button type="button" wire:click="cancel"
                                                        class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    @endif

                                    @if ($reviewing === $risk->id)
                                        <div class="mt-4 rounded-xl border border-border bg-surface p-4">
                                            @error('reviewing')
                                                <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p>
                                            @enderror

                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <label class="{{ $labelClass }}">Looked at on</label>
                                                    <input type="date" wire:model="reviewedOn" class="{{ $inputClass }}">
                                                    @error('reviewedOn') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                                </div>
                                                <div>
                                                    <label class="{{ $labelClass }}">What changed</label>
                                                    <input type="text" wire:model="reviewNotes" placeholder="Optional" class="{{ $inputClass }}">
                                                </div>
                                            </div>

                                            <div class="mt-3 flex gap-2">
                                                <button type="button" wire:click="review"
                                                        class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                                    Mark reviewed
                                                </button>
                                                <button type="button" wire:click="cancel"
                                                        class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-muted">
                                Nothing on the register. That is rarely because there is nothing to write down.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($closedRisks->isNotEmpty())
            {{-- A closure is visible and reversible, never a disappearance. --}}
            <p class="mt-7 text-[13px] font-semibold uppercase tracking-wide text-muted">Recently closed</p>
            <div class="mt-2 overflow-hidden rounded-2xl border border-border">
                <table class="w-full text-left text-[14.5px]">
                    <tbody>
                        @foreach ($closedRisks as $risk)
                            <tr class="border-b border-border last:border-b-0" wire:key="closed-{{ $risk->id }}">
                                <td class="px-4 py-3 font-semibold text-ink">
                                    {{ $risk->title }}
                                    <span class="block text-[13px] font-normal text-muted">
                                        Closed {{ $risk->closed_on?->toFormattedDateString() }}
                                        @if ($risk->closure_reason) — {{ $risk->closure_reason }} @endif
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @can('risks.manage')
                                        <button type="button" wire:click="reopenRisk('{{ $risk->id }}')"
                                                class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                            Reopen
                                        </button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    {{-- ────────────────────────────────────────────── needs looking at ── --}}
    @if ($tab === 'review')
        <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Past their review date</p>
        <div class="mt-2 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <tbody>
                    @forelse ($toReview as $risk)
                        <tr class="border-b border-border last:border-b-0">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $risk->title }}
                                <span class="block text-[13px] font-normal text-muted">{{ $risk->owner?->name ?? 'unowned' }}</span>
                            </td>
                            <td class="px-4 py-3 font-semibold text-rose-600">
                                Due {{ $risk->next_review_on->toFormattedDateString() }}
                            </td>
                            <td class="px-4 py-3 text-ink-2">
                                Last looked at {{ $risk->last_reviewed_on?->toFormattedDateString() ?? 'never' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @can('risks.review')
                                    <button type="button" wire:click="startReview('{{ $risk->id }}')"
                                            class="tap focusable rounded-full bg-fill-brand px-3.5 py-1.5 text-[13.5px] font-semibold text-white">
                                        Review it
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-muted">Nothing is stale.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="mt-6 text-[13px] font-semibold uppercase tracking-wide text-muted">Controls promised and not put in</p>
        <div class="mt-2 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <tbody>
                    @forelse ($overdueControls as $control)
                        <tr class="border-b border-border last:border-b-0">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $control->title }}
                                <span class="block text-[13px] font-normal text-muted">{{ $control->risk?->title }}</span>
                            </td>
                            <td class="px-4 py-3 font-semibold text-rose-600">
                                Was due {{ $control->due_on->toFormattedDateString() }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @can('risks.manage')
                                    <button type="button" wire:click="markControlInPlace('{{ $control->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                        It is in place
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-muted">Everything agreed on has been put in.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
