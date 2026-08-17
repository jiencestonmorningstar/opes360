<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Expense claims</h1>
            <p class="mt-1 text-[14.5px] text-muted">What staff paid out of their own pocket, and paying it back.</p>
        </div>

        @can('expenses.claim-create')
            <button type="button" wire:click="$toggle('creating')"
                    class="focusable flex h-11 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14px] font-semibold text-white hover:opacity-90">
                <x-icon name="plus" class="size-[16px]" />
                New claim
            </button>
        @endcan
    </div>

    @error('claims')
        <p class="mt-3 rounded-xl bg-negative/10 px-4 py-2.5 text-[13.5px] text-negative">{{ $message }}</p>
    @enderror

    @if ($creating)
        <div class="mt-4">
            <x-ui.panel title="New claim">
                <form wire:submit="save" class="grid gap-3">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label for="c-employee" class="text-[13px] font-semibold text-ink-2">Member of staff</label>
                            <select id="c-employee" wire:model="employeeId"
                                    class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                                <option value="">Choose…</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->name() }}</option>
                                @endforeach
                            </select>
                            @error('employeeId') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="c-title" class="text-[13px] font-semibold text-ink-2">What it was for</label>
                            <input id="c-title" wire:model="title" type="text"
                                   class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                            @error('title') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="c-date" class="text-[13px] font-semibold text-ink-2">Claim date</label>
                            <input id="c-date" wire:model="claimDate" type="date"
                                   class="focusable mt-1 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                            @error('claimDate') <p class="mt-1 text-[13px] text-negative">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <p class="text-[13px] font-semibold text-ink-2">Expenses on the claim</p>

                        @foreach ($lines as $index => $line)
                            <div wire:key="line-{{ $index }}" class="mt-2 grid items-end gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_1fr_auto]">
                                <div>
                                    <label class="text-[12px] text-muted">Description</label>
                                    <input wire:model="lines.{{ $index }}.description" type="text" placeholder="Taxi to the site"
                                           class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                </div>
                                <div>
                                    <label class="text-[12px] text-muted">Category</label>
                                    <select wire:model="lines.{{ $index }}.category"
                                            class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-2 text-[14px] text-ink">
                                        @foreach ($categories as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[12px] text-muted">Amount</label>
                                    <input wire:model="lines.{{ $index }}.amount" type="number" step="0.01" min="0"
                                           class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                </div>
                                <div>
                                    <label class="text-[12px] text-muted">TVA rate</label>
                                    <input wire:model="lines.{{ $index }}.vat_rate" type="number" step="0.0001" min="0" max="1" placeholder="0.1925"
                                           class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                </div>
                                <div>
                                    <label class="text-[12px] text-muted">Cost centre</label>
                                    <select wire:model="lines.{{ $index }}.cost_centre_id"
                                            class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-2 text-[14px] text-ink">
                                        <option value="">None</option>
                                        @foreach ($costCentres as $centre)
                                            <option value="{{ $centre->id }}">{{ $centre->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="button" wire:click="removeLine({{ $index }})" @disabled(count($lines) === 1)
                                        class="focusable flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-surface text-muted hover:text-negative disabled:opacity-40"
                                        aria-label="Remove line">
                                    <x-icon name="x-mark" class="size-[15px]" />
                                </button>
                            </div>
                        @endforeach

                        @error('lines') <p class="mt-2 text-[13px] text-negative">{{ $message }}</p> @enderror

                        <button type="button" wire:click="addLine"
                                class="focusable mt-2 flex h-9 items-center gap-1.5 rounded-full border border-border bg-surface px-3.5 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                            <x-icon name="plus" class="size-[14px]" />
                            Another expense
                        </button>
                    </div>

                    <div>
                        <label for="c-notes" class="text-[13px] font-semibold text-ink-2">Notes <span class="font-normal text-muted">optional</span></label>
                        <textarea id="c-notes" wire:model="notes" rows="2"
                                  class="focusable mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2 text-[14.5px] text-ink"></textarea>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="focusable flex h-10 items-center rounded-full bg-fill-brand px-5 text-[13.5px] font-semibold text-white hover:opacity-90">
                            Save claim
                        </button>
                        <button type="button" wire:click="$set('creating', false)"
                                class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                            Cancel
                        </button>
                    </div>
                </form>
            </x-ui.panel>
        </div>
    @endif

    <div class="mt-5 no-scrollbar flex gap-2 overflow-x-auto">
        @foreach (['' => 'All'] + $statuses as $value => $label)
            <button type="button" wire:click="$set('status', '{{ $value }}')"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $status === $value ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mt-5">
        <x-ui.panel>
            @forelse ($claims as $claim)
                <div wire:key="claim-{{ $claim->id }}" class="{{ $loop->first ? '' : 'mt-3' }} rounded-xl bg-surface-2 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[14.5px] font-semibold text-ink">{{ $claim->title }}</p>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                {{ $claim->employee?->name() ?? '—' }}
                                · {{ $claim->claim_date?->format('d M Y') }}
                                · {{ number_format((float) $claim->total) }} {{ $claim->currency }}
                                @if ((float) $claim->amount_reimbursed > 0 && ! $claim->isSettled())
                                    · {{ number_format($claim->balance()) }} still owed
                                @endif
                            </p>
                        </div>

                        <x-ui.status-badge class="shrink-0"
                            :label="\App\Models\ExpenseClaim::STATUSES[$claim->status] ?? $claim->status"
                            :tone="match ($claim->status) {
                                'approved', 'reimbursed' => 'positive',
                                'submitted' => 'warning',
                                'rejected' => 'negative',
                                default => 'neutral',
                            }" />
                    </div>

                    <div class="mt-2 flex flex-wrap gap-2">
                        @can('expenses.claim-create')
                            @if ($claim->isDraft())
                                <button type="button" wire:click="submit('{{ $claim->id }}')"
                                        class="focusable flex h-8 items-center rounded-full bg-fill-brand px-3.5 text-[12.5px] font-semibold text-white hover:opacity-90">
                                    Send for approval
                                </button>
                            @endif
                        @endcan

                        @can('expenses.claim-reimburse')
                            @if ($claim->isApproved() && ! $claim->isSettled())
                                <button type="button" wire:click="startReimburse('{{ $claim->id }}')"
                                        class="focusable flex h-8 items-center rounded-full border border-border bg-surface px-3.5 text-[12.5px] font-semibold text-ink-2 hover:bg-surface-2">
                                    Reimburse
                                </button>
                            @endif
                        @endcan
                    </div>

                    @if ($reimbursing === $claim->id)
                        <form wire:submit="reimburse" class="mt-3 grid items-end gap-2 rounded-xl border border-border bg-surface p-3 sm:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                            <div>
                                <label class="text-[12px] text-muted">Amount</label>
                                <input wire:model="payAmount" type="number" step="0.01" min="0.01"
                                       class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                                @error('payAmount') <p class="mt-1 text-[12.5px] text-negative">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="text-[12px] text-muted">Method</label>
                                <select wire:model="payMethod"
                                        class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-2 text-[14px] text-ink">
                                    @foreach ($methods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-[12px] text-muted">Paid on</label>
                                <input wire:model="payDate" type="date"
                                       class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                            </div>
                            <div>
                                <label class="text-[12px] text-muted">Reference <span class="text-muted/70">optional</span></label>
                                <input wire:model="payReference" type="text"
                                       class="focusable mt-0.5 h-10 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="focusable flex h-10 items-center rounded-full bg-fill-brand px-4 text-[13px] font-semibold text-white hover:opacity-90">
                                    Pay
                                </button>
                                <button type="button" wire:click="$set('reimbursing', null)"
                                        class="focusable flex h-10 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="py-8 text-center text-[13.5px] text-muted">No claims yet.</p>
            @endforelse

            @if ($claims->hasPages())
                <div class="mt-4">{{ $claims->links() }}</div>
            @endif
        </x-ui.panel>
    </div>
</div>
