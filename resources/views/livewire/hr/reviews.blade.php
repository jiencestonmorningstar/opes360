@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $areaClass = 'w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $tabClass = fn ($key) => $tab === $key
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[14px] font-semibold text-muted hover:text-ink';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Performance reviews</h1>
            <p class="mt-1 text-[14.5px] text-muted">What was said about a period of somebody's work, and their answer to it.</p>
        </div>

        @can('reviews.manage')
            <button type="button" wire:click="startWriting"
                    class="tap focusable shrink-0 rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white">
                Write a review
            </button>
        @endcan
    </div>

    <div class="mt-5 flex gap-1 border-b border-border pb-3">
        @can('reviews.view')
            <button type="button" wire:click="$set('tab', 'all')" class="{{ $tabClass('all') }}">Everybody's</button>
        @endcan
        <button type="button" wire:click="$set('tab', 'mine')" class="{{ $tabClass('mine') }}">Mine</button>
    </div>

    @error('share') <p class="mt-4 text-[14px] font-semibold text-negative">{{ $message }}</p> @enderror

    {{-- ───────────────────────────────────────────────────── write form ── --}}
    @can('reviews.manage')
        @if ($writing)
            <x-ui.panel class="mt-5" :title="$editing ? 'Edit review' : 'Write a review'">
                <form wire:submit="save">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="{{ $labelClass }}" for="rev-person">Person</label>
                            <select id="rev-person" wire:model="employeeId" class="{{ $inputClass }}">
                                <option value="">Choose somebody</option>
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name() }}</option>
                                @endforeach
                            </select>
                            @error('employeeId') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            {{-- The job held during the period, which is not necessarily the job held today. --}}
                            <label class="{{ $labelClass }}" for="rev-position">Position at the time</label>
                            <select id="rev-position" wire:model="positionId" class="{{ $inputClass }}">
                                <option value="">Not recorded</option>
                                @foreach ($positions as $position)
                                    <option value="{{ $position->id }}">{{ $position->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="rev-cycle">Cycle</label>
                            <select id="rev-cycle" wire:model="cycle" class="{{ $inputClass }}">
                                @foreach ($cycles as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="rev-from">Period from</label>
                            <input id="rev-from" type="date" wire:model="periodStartsOn" class="{{ $inputClass }}">
                            @error('periodStartsOn') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="rev-to">Period to</label>
                            <input id="rev-to" type="date" wire:model="periodEndsOn" class="{{ $inputClass }}">
                            @error('periodEndsOn') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}" for="rev-rating">Overall</label>
                            <select id="rev-rating" wire:model="overallRating" class="{{ $inputClass }}">
                                <option value="">Not yet decided</option>
                                @foreach ($ratings as $value => $label)
                                    <option value="{{ $value }}">{{ $value }} — {{ $label }}</option>
                                @endforeach
                            </select>
                            @error('overallRating') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        <div>
                            <label class="{{ $labelClass }}" for="rev-summary">Summary</label>
                            <textarea id="rev-summary" rows="4" wire:model="summary" class="{{ $areaClass }}"></textarea>
                            @error('summary') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="rev-strengths">Strengths</label>
                            <textarea id="rev-strengths" rows="4" wire:model="strengths" class="{{ $areaClass }}"></textarea>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="rev-improvements">To improve</label>
                            <textarea id="rev-improvements" rows="4" wire:model="improvements" class="{{ $areaClass }}"></textarea>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="rev-goals">Goals for next period</label>
                            <textarea id="rev-goals" rows="4" wire:model="goals" class="{{ $areaClass }}"></textarea>
                        </div>
                    </div>

                    <p class="mt-3 text-[13px] text-muted">A review needs a rating before it can be shared, and cannot be rewritten once the employee has acknowledged it.</p>

                    <div class="mt-4 flex gap-2">
                        <button type="submit"
                                class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                            {{ $editing ? 'Save changes' : 'Save draft' }}
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

    {{-- ──────────────────────────────────────────────────── everybody's ── --}}
    @if ($tab === 'all')
        @can('reviews.view')
            <div class="mt-5 overflow-hidden rounded-2xl border border-border">
                <table class="w-full text-left text-[14.5px]">
                    <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                        <tr>
                            <th class="px-4 py-3">Person</th>
                            <th class="px-4 py-3">Period</th>
                            <th class="px-4 py-3">Cycle</th>
                            <th class="px-4 py-3">Rating</th>
                            <th class="px-4 py-3">State</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($all as $review)
                            <tr wire:key="rev-{{ $review->id }}" class="border-t border-border">
                                <td class="px-4 py-3 font-semibold text-ink">
                                    {{ $review->employee?->name() ?? '—' }}
                                    @if ($review->position)
                                        <span class="block text-[13px] font-normal text-muted">{{ $review->position->title }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-ink-2">
                                    {{ $review->period_starts_on->format('j M Y') }} – {{ $review->period_ends_on->format('j M Y') }}
                                </td>
                                <td class="px-4 py-3 text-ink-2">{{ $cycles[$review->cycle] ?? $review->cycle }}</td>
                                <td class="px-4 py-3 text-ink-2">{{ $review->ratingLabel() ?? 'Not rated' }}</td>
                                <td class="px-4 py-3 text-ink-2">{{ $statuses[$review->status] ?? $review->status }}</td>
                                <td class="px-4 py-3 text-right">
                                    @can('reviews.manage')
                                        @unless ($review->isAcknowledged())
                                            <button type="button" wire:click="edit('{{ $review->id }}')"
                                                    class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                                Edit
                                            </button>
                                        @endunless

                                        @if ($review->status === 'draft')
                                            <button type="button" wire:click="share('{{ $review->id }}')"
                                                    class="tap focusable ml-2 rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                                Share with them
                                            </button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-[13.5px] text-muted">No reviews written yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endcan
    @endif

    {{-- ────────────────────────────────────────────────────────── mine ── --}}
    @if ($tab === 'mine')
        <div class="mt-5 space-y-4">
            @forelse ($mine as $review)
                <x-ui.panel wire:key="mine-{{ $review->id }}"
                            :title="$review->period_starts_on->format('j M Y').' – '.$review->period_ends_on->format('j M Y')">
                    <x-slot:actions>
                        <span class="text-[13px] font-semibold text-muted">{{ $statuses[$review->status] ?? $review->status }}</span>
                    </x-slot:actions>

                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-[13px] font-semibold text-ink-2">Overall</dt>
                            <dd class="text-[14.5px] text-ink">{{ $review->ratingLabel() ?? 'Not rated' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[13px] font-semibold text-ink-2">Reviewed by</dt>
                            <dd class="text-[14.5px] text-ink">{{ $review->reviewer?->name ?? '—' }}</dd>
                        </div>
                        @foreach (['summary' => 'Summary', 'strengths' => 'Strengths', 'improvements' => 'To improve', 'goals' => 'Goals'] as $field => $label)
                            @if ($review->{$field})
                                <div class="sm:col-span-2">
                                    <dt class="text-[13px] font-semibold text-ink-2">{{ $label }}</dt>
                                    <dd class="whitespace-pre-line text-[14.5px] text-ink">{{ $review->{$field} }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>

                    @if ($review->isAcknowledged())
                        <p class="mt-4 rounded-xl bg-surface-2 px-4 py-3 text-[13.5px] text-muted">
                            You acknowledged this on {{ $review->acknowledged_at?->format('j M Y') }}. What is written above is now fixed; your own comment is still yours to change.
                        </p>

                        @if ($commenting === $review->id)
                            <div class="mt-3">
                                <label class="{{ $labelClass }}" for="cmt-{{ $review->id }}">Your comment</label>
                                <textarea id="cmt-{{ $review->id }}" rows="3" wire:model="employeeComment" class="{{ $areaClass }}"></textarea>
                                <div class="mt-3 flex gap-2">
                                    <button type="button" wire:click="saveComment('{{ $review->id }}')"
                                            class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                                        Save my comment
                                    </button>
                                    <button type="button" wire:click="cancel"
                                            class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        @else
                            @if ($review->employee_comment)
                                <div class="mt-3">
                                    <p class="text-[13px] font-semibold text-ink-2">Your comment</p>
                                    <p class="whitespace-pre-line text-[14.5px] text-ink">{{ $review->employee_comment }}</p>
                                </div>
                            @endif

                            <button type="button" wire:click="startComment('{{ $review->id }}')"
                                    class="focusable mt-3 flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                {{ $review->employee_comment ? 'Edit my comment' : 'Add a comment' }}
                            </button>
                        @endif
                    @elseif ($acknowledging === $review->id)
                        {{--
                            Said before the button, not after it. An
                            acknowledgement of something that can still be
                            edited is worth nothing in the dispute this record
                            is kept for, so the employee has to be told that
                            pressing this closes the verdict for good.
                        --}}
                        <div class="mt-4 rounded-2xl border border-amber-300/60 bg-amber-50 px-4 py-4 text-[14.5px] text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                            <p class="font-semibold">This is final.</p>
                            <p class="mt-1">
                                Once you acknowledge it, the rating, the summary, the strengths, the points to improve, the goals, the period and the reviewer are fixed and nobody — including your manager — can change them. Your own comment below stays yours to edit afterwards.
                            </p>
                        </div>

                        @error('acknowledging') <p class="mt-3 text-[14px] font-semibold text-negative">{{ $message }}</p> @enderror

                        <div class="mt-3">
                            <label class="{{ $labelClass }}" for="ack-{{ $review->id }}">Anything you want on the record <span class="font-normal text-muted">optional</span></label>
                            <textarea id="ack-{{ $review->id }}" rows="3" wire:model="employeeComment" class="{{ $areaClass }}"></textarea>
                        </div>

                        <div class="mt-4 flex gap-2">
                            <button type="button" wire:click="acknowledge"
                                    class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                                I acknowledge this review
                            </button>
                            <button type="button" wire:click="cancel"
                                    class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                Not yet
                            </button>
                        </div>
                    @else
                        <button type="button" wire:click="startAcknowledging('{{ $review->id }}')"
                                class="focusable mt-4 flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            Acknowledge this review
                        </button>
                    @endif
                </x-ui.panel>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">You have no reviews to read.</p>
            @endforelse
        </div>
    @endif
</div>
