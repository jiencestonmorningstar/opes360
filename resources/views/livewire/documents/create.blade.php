{{--
    The form's state lives in Alpine (see resources/js/forms/document.js) so it
    works with no connection at all. Livewire is used for exactly two things:
    the customer search while online, and the save.
--}}
<div class="px-5 pb-32 lg:px-6 lg:pt-6 lg:pb-8"
     x-data="opesDocumentForm({
        currency: @js($currency),
        vat: @js($vat),
        docLabel: @js($docType->label()),
        entityType: @js($type),
        canIssueOffline: @js($canIssueOffline),
        today: @js(now()->toDateString()),
        defaultDue: @js(now()->addDays(14)->toDateString()),
     })">

    {{-- Header --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('sales', ['type' => $type]) }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink"
           aria-label="Back to sales">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <div>
            <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
                New {{ $docType->label() }}
            </h1>
            <p class="mt-0.5 text-[13.5px] text-muted" x-show="online">The number is assigned when you issue it.</p>
            <p class="mt-0.5 text-[13.5px] text-muted" x-show="! online" x-cloak>
                <span x-show="numbersLeft > 0">
                    Offline — <span x-text="numbersLeft"></span> number<span x-show="numbersLeft !== 1">s</span> ready on this device.
                </span>
                <span x-show="numbersLeft === 0">Offline — you can still save a draft.</span>
            </p>
        </div>
    </div>

    {{-- Offline confirmation. Replaces the form once a document is saved on the
         device: there is no server page to redirect to yet, and the number is
         the one thing the user needs to see before handing over the paper. --}}
    <template x-if="savedOffline">
        <div class="card mt-5 p-6 text-center">
            <span class="mx-auto flex size-[52px] items-center justify-center rounded-full bg-tint-green">
                <x-icon name="check-circle" class="size-[26px] text-accent-green" stroke-width="2.2" />
            </span>

            <h2 class="mt-4 text-[19px] font-bold tracking-[-0.02em] text-ink">
                Saved on this device
            </h2>
            <p class="mt-1.5 text-[14px] text-muted">
                It will sync automatically the moment you're back online.
            </p>

            <div class="mt-5 space-y-2 rounded-xl bg-surface-2 px-4 py-3.5 text-left">
                <template x-if="savedOffline.number">
                    <div class="flex items-center justify-between">
                        <span class="text-[13.5px] text-muted">Number</span>
                        <span class="tnum text-[15px] font-bold text-ink" x-text="savedOffline.number"></span>
                    </div>
                </template>
                <div class="flex items-center justify-between">
                    <span class="text-[13.5px] text-muted">Customer</span>
                    <span class="text-[14.5px] font-semibold text-ink" x-text="savedOffline.customer"></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-[13.5px] text-muted">Total</span>
                    <span class="tnum text-[15px] font-bold text-brand" x-text="savedOffline.total"></span>
                </div>
            </div>

            <div class="mt-5 flex gap-3">
                <a href="{{ route('sales', ['type' => $type]) }}"
                   class="focusable flex h-12 flex-1 items-center justify-center rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                    Done
                </a>
                <button type="button" @click="startAnother()"
                        class="focusable h-12 flex-[1.4] rounded-xl bg-fill-brand text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    New {{ $docType->label() }}
                </button>
            </div>
        </div>
    </template>

    <div class="mt-5 grid gap-4 lg:grid-cols-3" x-show="! savedOffline">
        <div class="space-y-4 lg:col-span-2">

            {{-- Customer picker --}}
            <x-ui.panel title="Customer">
                <template x-if="contact">
                    <div class="flex items-center gap-3.5">
                        <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full bg-tint-blue text-[13px] font-bold text-accent-blue"
                              x-text="contact.initials"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-semibold text-ink" x-text="contact.name"></p>
                            <p class="truncate text-[13px] text-muted" x-text="contact.subtitle"></p>
                        </div>
                        <button type="button" @click="clearContact()"
                                class="focusable shrink-0 text-[13.5px] font-semibold text-brand hover:underline">
                            Change
                        </button>
                    </div>
                </template>

                <div x-show="! contact">
                    <div class="relative">
                        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
                        <input type="search" x-model="contactSearch"
                               @input.debounce.250ms="search()"
                               placeholder="Search customers…"
                               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </div>

                    <div class="mt-2 overflow-hidden rounded-xl border border-border" x-show="contactResults.length" x-cloak>
                        <template x-for="(result, index) in contactResults" :key="result.id">
                            <button type="button" @click="selectContact(result)"
                                    class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-surface-2"
                                    :class="index > 0 && 'border-t border-border'">
                                <span class="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-tint-blue text-[11.5px] font-bold text-accent-blue"
                                      x-text="result.initials"></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-[14px] font-medium text-ink" x-text="result.name"></span>
                                    <span class="block truncate text-[12.5px] text-muted" x-text="result.subtitle"></span>
                                </span>
                            </button>
                        </template>
                    </div>

                    <p class="mt-3 text-[13.5px] text-muted" x-cloak
                       x-show="contactSearch.trim().length > 0 && ! contactResults.length && ! searching">
                        No customers match "<span x-text="contactSearch"></span>".
                        <span x-show="! online">Only customers already on this device can be found offline.</span>
                    </p>
                </div>

                <p class="mt-2 text-[13px] font-medium text-warning" x-cloak
                   x-show="error('contact_id')" x-text="error('contact_id')"></p>
            </x-ui.panel>

            {{-- Line items --}}
            <x-ui.panel title="Items">
                <div class="space-y-4">
                    <template x-for="(line, index) in lines" :key="index">
                        <div class="rounded-xl border border-border p-3.5"
                             :class="(lineError(index, 'description') || lineError(index, 'quantity') || lineError(index, 'unit_price')) && 'border-warning/60'">
                            {{-- Labelled like Qty and Unit price below it. Left as
                                 a bare placeholder, this was the field people
                                 could not find when the save came back asking
                                 for a "description" — a word that then appeared
                                 nowhere on the screen. --}}
                            <div class="flex items-end gap-2">
                                <label class="min-w-0 flex-1">
                                    <span class="mb-1 block text-[11.5px] font-medium uppercase tracking-wide text-faint">Description</span>
                                    <input type="text" x-model="line.description"
                                           placeholder="What are you charging for?"
                                           class="h-11 w-full min-w-0 rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                </label>
                                <button type="button" @click="removeLine(index)"
                                        class="tap focusable flex h-11 shrink-0 items-center justify-center rounded-lg text-faint hover:text-warning"
                                        aria-label="Remove item">
                                    <x-icon name="plus" class="size-[20px] rotate-45" stroke-width="2.2" />
                                </button>
                            </div>

                            <div class="mt-2.5 flex items-center gap-2">
                                <label class="min-w-0 flex-1">
                                    <span class="mb-1 block text-[11.5px] font-medium uppercase tracking-wide text-faint">Qty</span>
                                    <input type="number" step="any" min="0" inputmode="decimal" x-model="line.quantity"
                                           class="tnum h-11 w-full rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                </label>
                                <label class="min-w-0 flex-[1.4]">
                                    <span class="mb-1 block text-[11.5px] font-medium uppercase tracking-wide text-faint">Unit price</span>
                                    <input type="number" step="any" min="0" inputmode="decimal" x-model="line.unit_price"
                                           placeholder="0.00"
                                           class="tnum h-11 w-full rounded-lg border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                </label>
                                <div class="min-w-0 flex-[1.2] text-right">
                                    <span class="mb-1 block text-[11.5px] font-medium uppercase tracking-wide text-faint">Total</span>
                                    <span class="tnum block h-11 content-center text-[15px] font-semibold text-ink"
                                          x-text="format(lineTotal(line))"></span>
                                </div>
                            </div>

                            <template x-for="field in ['description', 'quantity', 'unit_price']" :key="field">
                                <p class="mt-2 text-[12.5px] font-medium text-warning" x-cloak
                                   x-show="lineError(index, field)" x-text="lineError(index, field)"></p>
                            </template>
                        </div>
                    </template>
                </div>

                <button type="button" @click="addLine()"
                        class="focusable mt-4 flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-dashed border-border-strong text-[14px] font-semibold text-brand hover:bg-tint-blue">
                    <x-icon name="plus" class="size-[17px]" stroke-width="2.4" />
                    Add Item
                </button>
            </x-ui.panel>

            {{-- Dates + notes --}}
            <x-ui.panel title="Details">
                <div class="grid grid-cols-2 gap-3">
                    <label>
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Issue date</span>
                        <input type="date" x-model="issueDate"
                               class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </label>
                    <label>
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Due date</span>
                        <input type="date" x-model="dueDate"
                               class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </label>
                </div>
                <p class="mt-2 text-[13px] font-medium text-warning" x-cloak
                   x-show="error('due_date')" x-text="error('due_date')"></p>

                <label class="mt-4 block">
                    <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Notes <span class="font-normal text-faint">(optional)</span></span>
                    <textarea x-model="notes" rows="3" placeholder="Payment terms, thank-you note…"
                              class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                </label>
            </x-ui.panel>
        </div>

        {{-- Summary + actions (sticky bar on mobile, side panel on desktop) --}}
        <div>
            <div class="card fixed inset-x-0 bottom-0 z-30 rounded-b-none border-x-0 border-b-0 p-5 lg:static lg:z-auto lg:rounded-[var(--radius-card)] lg:border"
                 style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <p class="mb-3 rounded-lg bg-tint-orange px-3 py-2 text-[12.5px] font-medium text-warning" x-cloak
                   x-show="error('form')" x-text="error('form')"></p>

                <div class="flex items-center justify-between lg:mb-4">
                    <span class="text-[14px] font-medium text-muted">Total</span>
                    <span class="tnum text-[22px] font-bold tracking-[-0.02em] text-ink" x-text="format(total)"></span>
                </div>

                <div class="mt-3.5 flex gap-3 lg:mt-0 lg:flex-col">
                    <button type="button" @click="saveDraft()" :disabled="saving"
                            class="focusable h-12 flex-1 rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand disabled:opacity-60">
                        Save Draft
                    </button>
                    <button type="button" @click="saveAndIssue()" :disabled="saving"
                            class="focusable h-12 flex-[1.4] rounded-xl bg-fill-brand text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60 lg:flex-1">
                        <span x-show="! saving">Issue {{ $docType->label() }}</span>
                        <span x-show="saving" x-cloak>Issuing…</span>
                    </button>
                </div>
            </div>

            {{-- Live preview: a miniature of print/document.blade.php, drawn
                 entirely from the Alpine state above, so it works offline
                 exactly like the rest of the form. Collapsed on a phone,
                 where it would push the form off screen; open where there is
                 room for it. --}}
            <div class="mt-4" data-preview="document"
                 x-data="{ open: window.matchMedia('(min-width: 1024px)').matches }">
                <div class="card overflow-hidden">
                    <button type="button" @click="open = ! open" :aria-expanded="open"
                            class="focusable flex h-12 w-full items-center justify-between px-5 text-left">
                        <span class="text-[13.5px] font-semibold text-ink-2">Preview</span>
                        <span class="flex items-center text-faint transition-transform" :class="open && 'rotate-180'">
                            <x-icon name="chevron-down" class="size-[18px]" stroke-width="2.2" />
                        </span>
                    </button>

                    <div x-show="open" x-cloak class="border-t border-border p-4">
                        {{-- Letterhead --}}
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                            <p class="min-w-0 break-words text-[14px] font-extrabold leading-tight tracking-[-0.02em] text-ink">
                                {{ $companyName }}
                            </p>
                            <div class="text-right">
                                <p class="text-[10px] font-extrabold uppercase tracking-[0.08em] text-brand">{{ $docType->label() }}</p>
                                <p class="tnum text-[11px] font-bold text-ink">Draft — no number yet</p>
                                <p class="tnum mt-0.5 text-[10px] leading-relaxed text-muted">
                                    <span x-show="issueDate">Issued <span x-text="formatDate(issueDate)"></span></span>
                                    <span x-show="dueDate" class="block">Due <span x-text="formatDate(dueDate)"></span></span>
                                </p>
                            </div>
                        </div>

                        <div class="mt-3">
                            <p class="text-[8.5px] font-bold uppercase tracking-[0.1em] text-faint">Billed To</p>
                            <p class="mt-0.5 truncate text-[12px] font-bold text-ink"
                               x-text="contact ? contact.name : 'No customer yet'"></p>
                        </div>

                        {{-- Line table. table-fixed with wrapping descriptions,
                             so a long item never scrolls the sheet sideways. --}}
                        <table class="mt-3 w-full table-fixed border-collapse">
                            <thead>
                                <tr class="text-[8.5px] font-bold uppercase tracking-[0.08em] text-muted">
                                    <th class="w-[44%] border-b-2 border-ink pb-1 pr-1 text-left font-bold">Description</th>
                                    <th class="w-[12%] border-b-2 border-ink pb-1 text-right font-bold">Qty</th>
                                    <th class="w-[22%] border-b-2 border-ink pb-1 pl-1 text-right font-bold">Price</th>
                                    <th class="w-[22%] border-b-2 border-ink pb-1 pl-1 text-right font-bold">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(line, index) in lines" :key="index">
                                    <tr class="align-top">
                                        <td class="break-words border-b border-border py-1.5 pr-1 text-[11px] text-ink"
                                            x-text="line.description || '—'"></td>
                                        <td class="tnum border-b border-border py-1.5 text-right text-[11px] text-ink-2"
                                            x-text="line.quantity || '0'"></td>
                                        <td class="tnum border-b border-border py-1.5 pl-1 text-right text-[11px] text-ink-2"
                                            x-text="format(line.unit_price)"></td>
                                        <td class="tnum border-b border-border py-1.5 pl-1 text-right text-[11px] font-semibold text-ink"
                                            x-text="format(lineTotal(line))"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>

                        {{-- Totals. Split the same way the printed sheet splits
                             them, so the preview is not quietly a different
                             document from the one that comes out of the printer. --}}
                        <div class="ml-auto mt-2.5 max-w-[220px]">
                            <div class="flex items-baseline justify-between gap-4 py-0.5 text-[11px]">
                                <span class="text-muted" x-text="vat.registered ? 'Total HT' : 'Subtotal'"></span>
                                <span class="tnum text-ink-2" x-text="format(totals.subtotal)"></span>
                            </div>
                            <template x-if="vat.registered">
                                <div class="flex items-baseline justify-between gap-4 py-0.5 text-[11px]">
                                    <span class="text-muted" x-text="`TVA ${vat.rate}%`"></span>
                                    <span class="tnum text-ink-2" x-text="format(totals.tax)"></span>
                                </div>
                            </template>
                            <div class="mt-1 flex items-baseline justify-between gap-4 border-t-2 border-ink pt-1.5 text-[12.5px] font-extrabold text-ink">
                                <span x-text="vat.registered ? 'Total TTC' : 'Total'"></span>
                                <span class="tnum" x-text="format(totals.total)"></span>
                            </div>
                        </div>

                        <div class="mt-3" x-show="notes.trim() !== ''" x-cloak>
                            <p class="text-[8.5px] font-bold uppercase tracking-[0.1em] text-faint">Notes</p>
                            <p class="mt-0.5 whitespace-pre-line text-[10.5px] leading-relaxed text-ink-2" x-text="notes"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
