<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Recruitment</h1>
            <p class="mt-1 text-[14.5px] text-muted">What you are hiring for, and everyone who has applied. Share a vacancy's public link and applications arrive here by themselves.</p>
        </div>

        @can('recruitment.manage')
            @unless ($adding)
                <button type="button" wire:click="startAdding"
                        class="focusable flex h-10 shrink-0 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                    New vacancy
                </button>
            @endunless
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-semibold text-positive">{{ session('status') }}</div>
    @endif

    @if ($adding)
        <x-ui.panel title="New vacancy" class="mt-5">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <label for="vac-position" class="text-[13px] font-semibold text-ink-2">Position</label>
                    <select id="vac-position" wire:model="positionId"
                            class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                        <option value="">Choose the position being hired for…</option>
                        @foreach ($positions as $position)
                            <option value="{{ $position->id }}">{{ $position->label() }}</option>
                        @endforeach
                    </select>
                    @error('positionId') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="vac-desc" class="text-[13px] font-semibold text-ink-2">Description <span class="font-normal text-muted">what applicants read</span></label>
                    <textarea id="vac-desc" wire:model="description" rows="4"
                              class="focusable mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-[14.5px] text-ink"></textarea>
                    @error('description') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="vac-openings" class="text-[13px] font-semibold text-ink-2">Openings</label>
                    <input id="vac-openings" wire:model="openings" type="number" min="1"
                           class="focusable mt-1 h-11 w-32 rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                    @error('openings') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                            class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                        Create vacancy
                    </button>
                    <button type="button" wire:click="cancel"
                            class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                        Cancel
                    </button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    <x-ui.panel title="Vacancies" class="mt-5">
        @forelse ($vacancies as $vacancy)
            <div wire:key="vac-{{ $vacancy->id }}"
                 class="{{ $loop->first ? '' : 'mt-3' }} flex flex-wrap items-center justify-between gap-4 rounded-xl bg-surface-2 p-4">
                <div class="min-w-0">
                    <p class="truncate text-[14.5px] font-semibold text-ink">{{ $vacancy->title() }}</p>
                    <p class="mt-0.5 text-[12.5px] text-muted">
                        {{ $vacancy->state()['label'] }}
                        · {{ $vacancy->applications_count }} {{ Str::plural('application', $vacancy->applications_count) }}
                        · {{ $vacancy->openings }} {{ Str::plural('opening', $vacancy->openings) }}
                    </p>
                    @if ($vacancy->isOpen())
                        <p class="mt-1 select-all break-all text-[12px] text-faint">{{ $vacancy->publicUrl() }}</p>
                    @endif
                </div>

                @can('recruitment.manage')
                    <div class="flex shrink-0 gap-2">
                        @if ($vacancy->isOpen())
                            <button type="button" wire:click="close('{{ $vacancy->id }}')"
                                    class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                Close
                            </button>
                        @else
                            <button type="button" wire:click="open('{{ $vacancy->id }}')"
                                    class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                Open
                            </button>
                        @endif
                    </div>
                @endcan
            </div>
        @empty
            <p class="py-6 text-center text-[13.5px] text-muted">Nothing is being hired for. Create the first vacancy.</p>
        @endforelse
    </x-ui.panel>

    <x-ui.panel title="Pipeline" class="mt-5">
        <x-slot:actions>
            <select wire:model.live="vacancyFilter"
                    class="focusable h-9 rounded-xl border border-border bg-surface px-3 text-[13px] text-ink">
                <option value="">All vacancies</option>
                @foreach ($vacancies as $vacancy)
                    <option value="{{ $vacancy->id }}">{{ $vacancy->title() }}</option>
                @endforeach
            </select>
        </x-slot:actions>

        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
            @foreach ($stages as $stage => $label)
                <div wire:key="col-{{ $stage }}">
                    <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $label }}</p>

                    <div class="mt-2 space-y-2">
                        @foreach ($applications->get($stage, collect()) as $application)
                            <div wire:key="app-{{ $application->id }}" class="rounded-xl bg-surface-2 p-3">
                                @if (Route::has('recruitment.show'))
                                    <a href="{{ route('recruitment.show', $application) }}"
                                       class="focusable text-[13.5px] font-semibold text-ink hover:underline">
                                        {{ $application->candidate->name() }}
                                    </a>
                                @else
                                    <p class="text-[13.5px] font-semibold text-ink">{{ $application->candidate->name() }}</p>
                                @endif
                                <p class="mt-0.5 truncate text-[12px] text-muted">{{ $application->vacancy->title() }}</p>

                                @can('recruitment.manage')
                                    @if (in_array($application->stage, ['applied', 'screening'], true))
                                        <button type="button"
                                                wire:click="moveStage('{{ $application->id }}', '{{ $application->stage === 'applied' ? 'screening' : 'interview' }}')"
                                                class="focusable mt-2 text-[12px] font-semibold text-brand hover:underline">
                                            Move to {{ $application->stage === 'applied' ? 'Screening' : 'Interview' }} →
                                        </button>
                                    @endif
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.panel>
</div>
