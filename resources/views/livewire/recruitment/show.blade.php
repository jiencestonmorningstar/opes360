<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            @if (Route::has('recruitment'))
                <a href="{{ route('recruitment') }}" class="focusable text-[13px] font-semibold text-brand hover:underline">← Recruitment</a>
            @endif
            <h1 class="mt-1 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">
                {{ $application->candidate->name() }}
            </h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $application->vacancy->title() }} · {{ $application->stageLabel() }}
                @if ($application->candidate->email) · {{ $application->candidate->email }} @endif
                @if ($application->candidate->phone) · {{ $application->candidate->phone }} @endif
            </p>
        </div>

        @can('recruitment.manage')
            @if ($application->isActive())
                <div class="flex shrink-0 gap-2">
                    @if ($application->stage === 'applied')
                        <button type="button" wire:click="moveStage('screening')"
                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                            Move to screening
                        </button>
                    @endif
                </div>
            @endif
        @endcan
    </div>

    @error('action')
        <div class="mt-4 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-semibold text-warning">{{ $message }}</div>
    @enderror

    <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] lg:items-start">
        <div class="space-y-4">

            @if ($application->cover_note)
                <x-ui.panel title="Their application">
                    <p class="whitespace-pre-line text-[14.5px] leading-relaxed text-ink-2">{{ $application->cover_note }}</p>
                    @if ($application->hasCv())
                        <p class="mt-3 text-[13px] text-muted">
                            CV on file: <span class="font-semibold text-ink-2">{{ $application->cv_name }}</span>
                            <button type="button" wire:click="downloadCv"
                                    class="focusable ml-2 font-semibold text-brand hover:underline">Download</button>
                        </p>
                    @endif
                </x-ui.panel>
            @elseif ($application->hasCv())
                <x-ui.panel title="Their application">
                    <p class="text-[13px] text-muted">
                        CV on file: <span class="font-semibold text-ink-2">{{ $application->cv_name }}</span>
                        <button type="button" wire:click="downloadCv"
                                class="focusable ml-2 font-semibold text-brand hover:underline">Download</button>
                    </p>
                </x-ui.panel>
            @endif

            <x-ui.panel title="Interviews">
                @forelse ($application->interviews as $interview)
                    <div wire:key="int-{{ $interview->id }}" class="{{ $loop->first ? '' : 'mt-3' }} rounded-xl bg-surface-2 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-[14px] font-semibold text-ink">{{ $interview->scheduled_at->format('D j M Y, H:i') }}</p>
                            @if ($interview->averageRating() !== null)
                                <p class="text-[13px] font-semibold text-ink-2">{{ $interview->averageRating() }} / 5</p>
                            @endif
                        </div>
                        @if ($interview->location)
                            <p class="mt-0.5 text-[12.5px] text-muted">{{ $interview->location }}</p>
                        @endif

                        <div class="mt-2 space-y-2">
                            @foreach ($interview->feedback as $card)
                                <div wire:key="fb-{{ $card->id }}" class="rounded-lg bg-surface p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[13px] font-semibold text-ink-2">{{ $card->interviewer->name }}</p>
                                        <p class="text-[13px] text-muted">
                                            {{ $card->isSubmitted() ? ($card->rating !== null ? $card->rating.' / 5' : 'No rating') : 'Not yet scored' }}
                                        </p>
                                    </div>
                                    @if ($card->notes)
                                        <p class="mt-1 whitespace-pre-line text-[13px] text-muted">{{ $card->notes }}</p>
                                    @endif

                                    @can('recruitment.interview')
                                        @if ($card->interviewer_id === auth()->id() && $feedbackInterviewId !== $interview->id)
                                            <button type="button" wire:click="startFeedback('{{ $interview->id }}')"
                                                    class="focusable mt-1 text-[12.5px] font-semibold text-brand hover:underline">
                                                {{ $card->isSubmitted() ? 'Edit my scorecard' : 'Score this interview' }}
                                            </button>
                                        @endif
                                    @endcan
                                </div>
                            @endforeach
                        </div>

                        @if ($feedbackInterviewId === $interview->id)
                            <form wire:submit="saveFeedback" class="mt-3 space-y-3 rounded-lg border border-border bg-surface p-3">
                                <div>
                                    <label class="text-[13px] font-semibold text-ink-2">Rating (1–5)</label>
                                    <input type="number" min="1" max="5" wire:model="feedbackRating"
                                           class="focusable mt-1 h-10 w-24 rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                    @error('feedbackRating') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="text-[13px] font-semibold text-ink-2">Notes</label>
                                    <textarea wire:model="feedbackNotes" rows="3"
                                              class="focusable mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2 text-[14px] text-ink"></textarea>
                                </div>
                                <button type="submit"
                                        class="focusable flex h-9 items-center rounded-full bg-fill-brand px-4 text-[13px] font-semibold text-white hover:opacity-90">
                                    Save scorecard
                                </button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="py-4 text-center text-[13.5px] text-muted">No interviews yet.</p>
                @endforelse

                @can('recruitment.manage')
                    @if ($application->isActive())
                        <form wire:submit="scheduleInterview" class="mt-4 space-y-3 border-t border-border pt-4">
                            <p class="text-[13.5px] font-semibold text-ink">Schedule an interview</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="text-[13px] font-semibold text-ink-2">When</label>
                                    <input type="datetime-local" wire:model="interviewAt"
                                           class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                    @error('interviewAt') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="text-[13px] font-semibold text-ink-2">Where</label>
                                    <input type="text" wire:model="interviewLocation" placeholder="Office, phone, video call…"
                                           class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                </div>
                            </div>
                            <div>
                                <label class="text-[13px] font-semibold text-ink-2">Panel</label>
                                <div class="mt-1 space-y-1.5">
                                    @foreach ($team as $member)
                                        <label class="flex items-center gap-2 text-[13.5px] text-ink-2">
                                            <input type="checkbox" wire:model="interviewerIds" value="{{ $member->id }}"
                                                   class="focusable rounded border-border">
                                            {{ $member->name }}
                                        </label>
                                    @endforeach
                                </div>
                                @error('interviewerIds') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit"
                                    class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                                Schedule
                            </button>
                        </form>
                    @endif
                @endcan
            </x-ui.panel>

            <x-ui.panel title="Offer">
                @forelse ($application->offers as $offer)
                    <div wire:key="offer-{{ $offer->id }}" class="{{ $loop->first ? '' : 'mt-3' }} rounded-xl bg-surface-2 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-[14.5px] font-semibold text-ink">
                                {{ number_format((float) $offer->amount) }} {{ $offer->currency }} / month
                            </p>
                            <p class="text-[13px] font-semibold text-ink-2">{{ $offer->statusLabel() }}</p>
                        </div>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            Starts {{ $offer->starts_on->format('j F Y') }}
                            @if ($offer->letter) · Letter: {{ $offer->letter->reference }} @endif
                        </p>

                        @can('recruitment.offer')
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($offer->status === 'draft')
                                    <button type="button" wire:click="submitOffer('{{ $offer->id }}')"
                                            class="focusable flex h-9 items-center rounded-full bg-fill-brand px-4 text-[13px] font-semibold text-white hover:opacity-90">
                                        Submit for approval
                                    </button>
                                @endif
                                @if ($offer->status === 'approved')
                                    <button type="button" wire:click="acceptOffer('{{ $offer->id }}')"
                                            class="focusable flex h-9 items-center rounded-full bg-fill-brand px-4 text-[13px] font-semibold text-white hover:opacity-90">
                                        Candidate accepted — hire
                                    </button>
                                    <button type="button" wire:click="declineOffer('{{ $offer->id }}')"
                                            class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                        Candidate declined
                                    </button>
                                @endif
                                @if ($offer->status === 'pending')
                                    <p class="text-[13px] text-muted">Awaiting approval — the decision happens on the Approvals screen.</p>
                                @endif
                                @if (in_array($offer->status, ['draft', 'pending', 'approved'], true))
                                    <button type="button" wire:click="withdrawOffer('{{ $offer->id }}')"
                                            wire:confirm="Withdraw this offer? The candidate can then be offered different terms."
                                            class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-negative hover:bg-surface-2">
                                        Withdraw offer
                                    </button>
                                @endif
                            </div>
                        @endcan
                    </div>
                @empty
                    <p class="py-4 text-center text-[13.5px] text-muted">No offer has been made.</p>
                @endforelse

                @can('recruitment.offer')
                    @if ($application->isActive() && $application->currentOffer() === null)
                        <form wire:submit="makeOffer" class="mt-4 space-y-3 border-t border-border pt-4">
                            <p class="text-[13.5px] font-semibold text-ink">Make an offer</p>
                            <p class="text-[12.5px] text-muted">The offer letter is generated from the document library's Offer Letter template and goes for approval before anyone can be hired on it.</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="text-[13px] font-semibold text-ink-2">Monthly salary</label>
                                    <input type="number" step="0.01" wire:model="offerAmount"
                                           class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                    @error('offerAmount') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="text-[13px] font-semibold text-ink-2">Start date</label>
                                    <input type="date" wire:model="offerStartsOn"
                                           class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                    @error('offerStartsOn') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <button type="submit"
                                    class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                                Draft offer and letter
                            </button>
                        </form>
                    @endif
                @endcan
            </x-ui.panel>
        </div>

        <div class="space-y-4">
            <x-ui.panel title="Timeline">
                @foreach ($application->stageMoves as $move)
                    <div wire:key="move-{{ $move->id }}" class="{{ $loop->first ? '' : 'mt-3' }} flex items-start gap-3">
                        <div class="mt-1.5 size-2 shrink-0 rounded-full bg-brand"></div>
                        <div class="min-w-0">
                            <p class="text-[13.5px] font-semibold text-ink">
                                {{ $stages[$move->to_stage] ?? ucfirst($move->to_stage) }}
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                {{ $move->created_at->format('j M Y, H:i') }}
                                · {{ $move->mover?->name ?? 'The applicant' }}
                                @if ($move->reason) · {{ $move->reason }} @endif
                            </p>
                        </div>
                    </div>
                @endforeach
            </x-ui.panel>

            @can('recruitment.manage')
                @if ($application->isActive())
                    <x-ui.panel title="Reject">
                        <form wire:submit="reject" class="space-y-3">
                            <div>
                                <label class="text-[13px] font-semibold text-ink-2">Reason</label>
                                <input type="text" wire:model="rejectionReason"
                                       class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                @error('rejectionReason') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit"
                                    class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-negative hover:bg-surface-2">
                                Reject this application
                            </button>
                        </form>
                    </x-ui.panel>
                @endif
            @endcan
        </div>
    </div>
</div>
