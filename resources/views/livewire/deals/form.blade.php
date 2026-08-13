@php
    use App\Models\Deal;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="mx-auto max-w-2xl">
        <a href="{{ route('deals') }}" wire:navigate
           class="focusable -ml-1 inline-flex items-center gap-1.5 rounded-lg p-1 text-[14px] font-semibold text-muted hover:text-ink">
            <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
            Pipeline
        </a>

        <h1 class="mt-3 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">
            {{ $deal ? 'Edit deal' : 'New deal' }}
        </h1>

        <form wire:submit="save" class="mt-6 space-y-5">

            <div>
                <label for="title" class="block text-[13.5px] font-semibold text-ink-2">What is the deal?</label>
                <input id="title" type="text" wire:model="title" autocomplete="off"
                       placeholder="Supply 200 bags of cement"
                       class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                @error('title') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
            </div>

            {{-- Either an existing customer or a bare name. A lead is often a
                 name and a number long before it is worth a customer record,
                 and forcing one is how the customer book fills with junk. --}}
            <div>
                <label for="contact_id" class="block text-[13.5px] font-semibold text-ink-2">Customer</label>
                <select id="contact_id" wire:model="contact_id"
                        class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    <option value="">Not a customer yet…</option>
                    @foreach ($contacts as $contact)
                        <option value="{{ $contact->id }}">{{ $contact->name }}</option>
                    @endforeach
                </select>
                @error('contact_id') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="lead_name" class="block text-[13.5px] font-semibold text-ink-2">…or a lead name</label>
                    <input id="lead_name" type="text" wire:model="lead_name" autocomplete="off"
                           class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('lead_name') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="lead_phone" class="block text-[13.5px] font-semibold text-ink-2">Their phone</label>
                    <input id="lead_phone" type="tel" wire:model="lead_phone" autocomplete="off"
                           class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('lead_phone') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="value" class="block text-[13.5px] font-semibold text-ink-2">What is it worth?</label>
                    <input id="value" type="number" step="0.01" min="0" inputmode="decimal" wire:model="value"
                           class="tnum control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('value') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="expected_close_on" class="block text-[13.5px] font-semibold text-ink-2">Expected to close</label>
                    <input id="expected_close_on" type="date" wire:model="expected_close_on"
                           class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('expected_close_on') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="stage" class="block text-[13.5px] font-semibold text-ink-2">Stage</label>
                <select id="stage" wire:model="stage"
                        class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @foreach (Deal::STAGES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('stage') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="notes" class="block text-[13.5px] font-semibold text-ink-2">Notes</label>
                <textarea id="notes" rows="4" wire:model="notes"
                          class="mt-1.5 w-full rounded-xl border border-border bg-surface px-4 py-3 text-[15px] leading-relaxed text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                @error('notes') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-3 pt-2 sm:flex-row">
                <button type="submit"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">{{ $deal ? 'Save changes' : 'Add deal' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>

                <a href="{{ route('deals') }}" wire:navigate
                   class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
