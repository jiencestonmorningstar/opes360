@php
    use App\Support\Accent;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Artisans</h1>
            <p class="mt-1 text-[14.5px] text-muted">Each one gets a public, verified profile page.</p>
        </div>

        @unless ($editing)
            <button type="button" wire:click="startCreating"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                <x-icon name="user-plus" class="size-[18px]" stroke-width="2" />
                <span class="hidden min-[420px]:inline">Add Artisan</span>
                <span class="min-[420px]:hidden">Add</span>
            </button>
        @endunless
    </div>

    @include('partials.business-tabs')

    <div class="mt-5 grid gap-4 {{ $editing ? 'lg:grid-cols-2' : '' }}">

        @if ($editing)
            <x-ui.panel :title="$editingId ? 'Edit artisan' : 'New artisan'">
                <x-slot:actions>
                    <button type="button" wire:click="cancel"
                            class="focusable text-[14px] font-semibold text-muted hover:text-ink">Cancel</button>
                </x-slot:actions>

                <div class="flex items-center gap-4">
                    @if ($photo)
                        <img src="{{ $photo->temporaryUrl() }}" alt="" class="size-[62px] rounded-full object-cover">
                    @else
                        <span class="flex size-[62px] items-center justify-center rounded-full bg-tint-blue text-[18px] font-bold text-accent-blue">
                            {{ strtoupper(mb_substr($form['full_name'] ?: 'A', 0, 1)) }}
                        </span>
                    @endif
                    <label class="focusable inline-flex h-10 cursor-pointer items-center rounded-full border border-border bg-surface px-4 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                        Upload photo
                        <input type="file" wire:model="photo" accept="image/*" class="hidden">
                    </label>
                </div>
                @error('photo') <p class="mt-2 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror

                <div class="mt-5 space-y-4">
                    <div class="grid gap-4 min-[560px]:grid-cols-2">
                        <label class="block">
                            <span class="{{ $labelClass }}">Full name</span>
                            <input type="text" wire:model="form.full_name" class="{{ $inputClass }}">
                            @error('form.full_name') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">Occupation</span>
                            <input type="text" wire:model="form.occupation" placeholder="Electrician" class="{{ $inputClass }}">
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">Trade category</span>
                            <input type="text" wire:model="form.trade_category" placeholder="Construction" class="{{ $inputClass }}">
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">Years of experience</span>
                            <input type="number" min="0" max="80" wire:model="form.years_experience" class="tnum {{ $inputClass }}">
                        </label>
                    </div>

                    <label class="block">
                        <span class="{{ $labelClass }}">Skills <span class="font-normal text-faint">(comma separated)</span></span>
                        <input type="text" wire:model="form.skills" placeholder="Wiring, Solar install, Repairs" class="{{ $inputClass }}">
                    </label>

                    <label class="block">
                        <span class="{{ $labelClass }}">Biography</span>
                        <textarea wire:model="form.biography" rows="3"
                                  class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                    </label>

                    <div class="grid gap-4 min-[560px]:grid-cols-2">
                        <label class="block">
                            <span class="{{ $labelClass }}">Phone</span>
                            <input type="tel" wire:model="form.phone" class="{{ $inputClass }}">
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">WhatsApp</span>
                            <input type="tel" wire:model="form.whatsapp" class="{{ $inputClass }}">
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">Email</span>
                            <input type="email" wire:model="form.email" class="{{ $inputClass }}">
                            @error('form.email') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">City</span>
                            <input type="text" wire:model="form.city" class="{{ $inputClass }}">
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">Service areas</span>
                            <input type="text" wire:model="form.coverage" placeholder="Lagos, Ikeja" class="{{ $inputClass }}">
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">Languages</span>
                            <input type="text" wire:model="form.languages" placeholder="English, Yoruba" class="{{ $inputClass }}">
                        </label>
                    </div>

                    <label class="flex items-center justify-between gap-4 rounded-xl bg-surface-2 px-4 py-3">
                        <span>
                            <span class="block text-[14px] font-semibold text-ink">Published</span>
                            <span class="block text-[12.5px] text-muted">Unpublished profiles are hidden from the public URL.</span>
                        </span>
                        <input type="checkbox" wire:model="form.is_published"
                               class="size-6 rounded border-border-strong text-brand focus:ring-brand/30">
                    </label>
                </div>

                <button type="button" wire:click="save" wire:loading.attr="disabled"
                        class="focusable mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                    <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save Changes' : 'Add Artisan' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </x-ui.panel>
        @endif

        <div>
            <div class="card p-2">
                @forelse ($artisans as $index => $artisan)
                    <div wire:key="ar-{{ $artisan->id }}"
                         class="flex items-center gap-3.5 rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }}">
                        @if ($artisan->photoUrl())
                            <img src="{{ $artisan->photoUrl() }}" alt="" class="size-[46px] shrink-0 rounded-full object-cover">
                        @else
                            <span class="flex size-[46px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($artisan->accent()) }} text-[14px] font-bold {{ Accent::text($artisan->accent()) }}">
                                {{ $artisan->initials() }}
                            </span>
                        @endif

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-semibold text-ink">{{ $artisan->full_name }}</span>
                            <span class="block truncate text-[13px] text-muted">
                                {{ implode(' · ', array_filter([$artisan->occupation, $artisan->artisan_number])) }}
                                @unless ($artisan->is_published) · <span class="text-faint">unpublished</span> @endunless
                            </span>
                        </span>

                        <a href="{{ route('profile.artisan', $artisan) }}" target="_blank"
                           class="tap focusable flex shrink-0 items-center justify-center rounded-lg text-muted hover:text-brand"
                           aria-label="View public profile">
                            <x-icon name="qr-code" class="size-[19px]" />
                        </a>
                        <button type="button" wire:click="edit('{{ $artisan->id }}')"
                                class="focusable shrink-0 text-[13px] font-semibold text-brand hover:underline">
                            Edit
                        </button>
                    </div>
                @empty
                    <div class="flex flex-col items-center px-6 py-14 text-center">
                        <span class="flex size-[58px] items-center justify-center rounded-full bg-tint-teal">
                            <x-icon name="users" class="size-7 text-accent-teal" />
                        </span>
                        <p class="mt-4 text-[16px] font-semibold text-ink">No artisans yet</p>
                        <p class="mt-1 max-w-xs text-[13.5px] text-muted">
                            Add the people who do the work. Each gets a shareable, verified profile.
                        </p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
