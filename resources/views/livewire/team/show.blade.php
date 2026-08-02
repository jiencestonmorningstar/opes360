@php
    use App\Models\Employee;
    use App\Support\Accent;
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $accent = Accent::forKey($employee->id);
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('team') }}" wire:navigate
       class="focusable -ml-1.5 inline-flex min-h-[24px] items-center gap-1.5 rounded-lg px-1.5 py-1 text-[13.5px] font-semibold text-muted hover:text-ink-2">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        Team
    </a>

    <div class="mt-3 flex items-start gap-4">
        <span class="flex size-[54px] shrink-0 items-center justify-center rounded-full text-[18px] font-bold {{ Accent::tint($accent) }} {{ Accent::text($accent) }}">
            {{ $employee->initials() }}
        </span>

        <div class="min-w-0 flex-1">
            <h1 class="truncate text-[23px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[26px]">{{ $employee->name() }}</h1>
            <p class="mt-1 truncate text-[14px] text-muted">
                {{ $employee->job_title ?: 'No job title' }}
                @if ($employee->department) · {{ $employee->department }} @endif
                @if ($employee->hired_on) · since {{ $employee->hired_on->format('M Y') }} @endif
            </p>
            @unless ($employee->isActive())
                <span class="mt-2 inline-flex items-center rounded-full bg-tint-slate px-2.5 py-1 text-[12px] font-semibold text-accent-slate">
                    Left {{ $employee->ended_on?->format('j M Y') }}
                </span>
            @endunless
        </div>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Monthly salary</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] text-ink">
                {{ $contract ? $money($contract->base_salary) : '—' }}
            </p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Contract</p>
            <p class="mt-1 text-[15px] font-bold text-ink">{{ $contract?->typeLabel() ?? 'None' }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Leave left</p>
            <p class="tnum mt-1 text-[18px] font-bold tracking-[-0.02em] {{ $leaveBalance < 0 ? 'text-warning' : 'text-ink' }}">
                {{ rtrim(rtrim(number_format($leaveBalance, 1), '0'), '.') }} days
            </p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Paid by</p>
            <p class="mt-1 text-[15px] font-bold text-ink">{{ Employee::PAYMENT_METHODS[$employee->payment_method] ?? '—' }}</p>
        </div>
    </div>

    <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Choose a section">
        @foreach (['profile' => 'Profile', 'contracts' => 'Contracts', 'pay' => 'Pay', 'leave' => 'Leave'] as $key => $label)
            <button type="button" wire:click="$set('panel', '{{ $key }}')"
                    aria-pressed="{{ $panel === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $panel === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ─────────────────────────────────────────────────────── Profile ── --}}
    @if ($panel === 'profile')
        <div class="card mt-4 p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="p-first">First name</label>
                    <input id="p-first" type="text" wire:model="firstName" class="{{ $inputClass }}">
                    @error('firstName') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-last">Last name</label>
                    <input id="p-last" type="text" wire:model="lastName" class="{{ $inputClass }}">
                    @error('lastName') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-title">Job title</label>
                    <input id="p-title" type="text" wire:model="jobTitle" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-dept">Department</label>
                    <input id="p-dept" type="text" wire:model="department" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-number">Staff number</label>
                    <input id="p-number" type="text" wire:model="number" class="{{ $inputClass }}">
                    @error('number') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-phone">Phone</label>
                    <input id="p-phone" type="tel" wire:model="phone" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-email">Email</label>
                    <input id="p-email" type="email" wire:model="employeeEmail" class="{{ $inputClass }}">
                    @error('employeeEmail') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-address">Address</label>
                    <input id="p-address" type="text" wire:model="address" class="{{ $inputClass }}">
                </div>
            </div>

            <h3 class="mt-7 text-[14px] font-bold tracking-[-0.01em] text-ink">Identity numbers</h3>
            <p class="mt-1 text-[13px] leading-relaxed text-muted">
                These go on the payslip and on the CNPS and DGI declarations. Blank is fine until you have them.
            </p>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="{{ $labelClass }}" for="p-cni">CNI</label>
                    <input id="p-cni" type="text" wire:model="nationalId" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-cnps">CNPS number</label>
                    <input id="p-cnps" type="text" wire:model="cnpsNumber" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="p-niu">NIU</label>
                    <input id="p-niu" type="text" wire:model="niu" class="{{ $inputClass }}">
                </div>
            </div>

            <h3 class="mt-7 text-[14px] font-bold tracking-[-0.01em] text-ink">How they are paid</h3>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="{{ $labelClass }}" for="p-method">Method</label>
                    <select id="p-method" wire:model.live="paymentMethod" class="{{ $inputClass }}">
                        @foreach (Employee::PAYMENT_METHODS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if (in_array($paymentMethod, ['bank', 'cheque'], true))
                    <div>
                        <label class="{{ $labelClass }}" for="p-bank">Bank</label>
                        <input id="p-bank" type="text" wire:model="bankName" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="p-account">Account / RIB</label>
                        <input id="p-account" type="text" wire:model="bankAccount" class="{{ $inputClass }}">
                    </div>
                @elseif ($paymentMethod === 'mobile_money')
                    <div>
                        <label class="{{ $labelClass }}" for="p-momo">Mobile money number</label>
                        <input id="p-momo" type="tel" wire:model="mobileMoneyNumber" class="{{ $inputClass }}">
                    </div>
                @endif
                <div>
                    <label class="{{ $labelClass }}" for="p-leave">Leave brought forward (days)</label>
                    <input id="p-leave" type="number" step="0.5" min="0" wire:model="leaveOpeningBalance" class="{{ $inputClass }} tnum">
                    @error('leaveOpeningBalance') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6">
                <label class="{{ $labelClass }}" for="p-notes">Notes</label>
                <textarea id="p-notes" rows="3" wire:model="notes"
                          class="w-full rounded-xl border border-border bg-surface p-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
            </div>

            @can('employees.update')
                <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse sm:items-center sm:justify-between">
                    <button type="button" wire:click="saveProfile"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                        Save
                    </button>

                    @if ($employee->isActive())
                        <button type="button" wire:click="startEnding"
                                class="tap focusable flex h-12 items-center justify-center rounded-xl px-4 text-[14.5px] font-semibold text-negative hover:bg-tint-red">
                            Record that they have left
                        </button>
                    @else
                        <button type="button" wire:click="reinstate"
                                class="tap focusable flex h-12 items-center justify-center rounded-xl px-4 text-[14.5px] font-semibold text-brand hover:bg-tint-blue">
                            Bring back onto the team
                        </button>
                    @endif
                </div>
            @endcan

            @if ($ending)
                <div class="mt-5 rounded-xl border border-negative/40 bg-tint-red/50 p-4">
                    <p class="text-[14px] font-semibold text-ink">Record that {{ $employee->first_name }} has left</p>
                    <p class="mt-1 text-[13px] leading-relaxed text-muted">
                        Nothing is deleted. Their record and every payslip stay exactly as they are — they simply stop
                        appearing on new payroll runs.
                    </p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="{{ $labelClass }}" for="p-end-date">Last day</label>
                            <input id="p-end-date" type="date" wire:model="endDate" class="{{ $inputClass }}">
                            @error('endDate') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $labelClass }}" for="p-end-reason">Reason <span class="font-normal text-faint">(optional)</span></label>
                            <input id="p-end-reason" type="text" wire:model="endReason" class="{{ $inputClass }}" placeholder="Resignation">
                        </div>
                    </div>
                    <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                        <button type="button" wire:click="endEmployment"
                                class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-red px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                            Confirm
                        </button>
                        <button type="button" wire:click="$set('ending', false)"
                                class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ───────────────────────────────────────────────────── Contracts ── --}}
    @if ($panel === 'contracts')
        @can('employees.update')
            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="startContract"
                        class="tap focusable flex items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    <x-icon name="plus" class="size-[17px]" stroke-width="2.4" />
                    New contract
                </button>
            </div>
        @endcan

        @if ($addingContract)
            <div class="card mt-4 p-5">
                <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">New contract</h2>
                <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                    This is how a raise is recorded too. The contract it replaces is closed the day before this one
                    starts, so past payslips keep the salary they were computed at.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="c-type">Type</label>
                        <select id="c-type" wire:model.live="contractType" class="{{ $inputClass }}">
                            @foreach (config('payroll.contract_types') as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="c-salary">Monthly salary</label>
                        <input id="c-salary" type="number" step="1" min="0" inputmode="numeric" wire:model="contractSalary" class="{{ $inputClass }} tnum">
                        @error('contractSalary') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="c-starts">Starts on</label>
                        <input id="c-starts" type="date" wire:model="contractStartsOn" class="{{ $inputClass }}">
                        @error('contractStartsOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    @if ($contractType !== 'cdi')
                        <div>
                            <label class="{{ $labelClass }}" for="c-ends">Ends on</label>
                            <input id="c-ends" type="date" wire:model="contractEndsOn" class="{{ $inputClass }}">
                            @error('contractEndsOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label class="{{ $labelClass }}" for="c-title">Job title</label>
                        <input id="c-title" type="text" wire:model="contractTitle" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="c-cat">Catégorie <span class="font-normal text-faint">(optional)</span></label>
                        <input id="c-cat" type="text" wire:model="contractCategory" class="{{ $inputClass }}" placeholder="6e / B">
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                    <button type="button" wire:click="saveContract"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                        Record contract
                    </button>
                    <button type="button" wire:click="$set('addingContract', false)"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        <div class="card mt-4 p-2">
            @forelse ($employee->contracts as $index => $row)
                <div wire:key="{{ $row->id }}" class="rounded-xl px-3 py-3.5 {{ $index > 0 ? 'border-t border-border' : '' }}">
                    <div class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-semibold text-ink">
                                {{ $row->typeLabel() }}
                                @if ($row->job_title) <span class="font-normal text-muted">· {{ $row->job_title }}</span> @endif
                            </p>
                            <p class="tnum truncate text-[13px] text-muted">
                                {{ $row->starts_on->format('j M Y') }} —
                                {{ $row->ended_on?->format('j M Y') ?? $row->ends_on?->format('j M Y') ?? 'open' }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="tnum text-[15px] font-bold text-ink">{{ $money($row->base_salary) }}</p>
                            @if ($row->status === 'active')
                                <p class="text-[11.5px] font-semibold text-positive">In force</p>
                            @else
                                <p class="text-[11.5px] font-semibold text-faint">Ended</p>
                            @endif
                        </div>
                    </div>
                    @if ($row->status === 'active' && $row->isBelowMinimumWage())
                        <p class="mt-2 text-[12.5px] font-medium text-warning">Below the {{ $money(config('payroll.smig')) }} minimum wage.</p>
                    @endif
                    @if ($row->expiresSoon())
                        <p class="mt-2 text-[12.5px] font-medium text-warning">Ends {{ $row->ends_on->format('j M Y') }} — renew or let it run out.</p>
                    @endif
                </div>
            @empty
                <p class="px-4 py-10 text-center text-[14px] text-muted">No contract yet. Nobody can be paid without one.</p>
            @endforelse
        </div>
    @endif

    {{-- ─────────────────────────────────────────────────────────── Pay ── --}}
    @if ($panel === 'pay')
        @can('employees.update')
            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="startComponent"
                        class="tap focusable flex items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    <x-icon name="plus" class="size-[17px]" stroke-width="2.4" />
                    Allowance or deduction
                </button>
            </div>
        @endcan

        @if ($addingComponent)
            <div class="card mt-4 p-5">
                <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Recurring line</h2>
                <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                    It appears on every payslip from the next run onwards, under the name you give it here.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="sc-name">Name</label>
                        <input id="sc-name" type="text" wire:model="componentName" class="{{ $inputClass }}" placeholder="Prime de transport">
                        @error('componentName') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="sc-kind">Kind</label>
                        <select id="sc-kind" wire:model.live="componentKind" class="{{ $inputClass }}">
                            <option value="allowance">Allowance — adds to pay</option>
                            <option value="deduction">Deduction — comes off pay</option>
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="sc-amount">Amount a month</label>
                        <input id="sc-amount" type="number" step="1" min="0" inputmode="numeric" wire:model="componentAmount" class="{{ $inputClass }} tnum">
                        @error('componentAmount') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($componentKind === 'allowance')
                    <div class="mt-5 rounded-xl bg-surface-2 p-4">
                        <p class="text-[13px] font-semibold text-ink-2">Is it taxed, and does CNPS count it?</p>
                        <p class="mt-1 text-[12.5px] leading-relaxed text-muted">
                            These are two separate questions and the answers genuinely differ — a transport allowance
                            within the legal limit is outside both. If you are not sure, leave both on: that withholds
                            more, not less.
                        </p>
                        <div class="mt-3 flex flex-col gap-2.5">
                            <label class="flex items-center gap-2.5 text-[14px] font-medium text-ink-2">
                                <input type="checkbox" wire:model="componentTaxable" class="size-[18px] rounded border-border-strong text-brand focus:ring-brand/30">
                                Counts towards income tax (IRPP, CFC)
                            </label>
                            <label class="flex items-center gap-2.5 text-[14px] font-medium text-ink-2">
                                <input type="checkbox" wire:model="componentCnps" class="size-[18px] rounded border-border-strong text-brand focus:ring-brand/30">
                                Counts towards CNPS contributions
                            </label>
                        </div>
                    </div>
                @endif

                <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                    <button type="button" wire:click="saveComponent"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                        Add
                    </button>
                    <button type="button" wire:click="$set('addingComponent', false)"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        <div class="card mt-4 p-2">
            @forelse ($employee->components as $index => $component)
                <div wire:key="{{ $component->id }}" class="flex items-center gap-3 rounded-xl px-3 py-3.5 {{ $index > 0 ? 'border-t border-border' : '' }}">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[15px] font-semibold {{ $component->active ? 'text-ink' : 'text-faint line-through' }}">{{ $component->name }}</p>
                        <p class="truncate text-[13px] text-muted">
                            {{ $component->isAllowance() ? 'Allowance' : 'Deduction' }}
                            @if ($component->isAllowance())
                                · {{ $component->taxable ? 'taxed' : 'tax-exempt' }}
                                · {{ $component->cnps_liable ? 'CNPS' : 'no CNPS' }}
                            @endif
                        </p>
                    </div>
                    <p class="tnum shrink-0 text-[15px] font-bold {{ $component->isAllowance() ? 'text-positive' : 'text-negative' }}">
                        {{ $component->isAllowance() ? '+' : '−' }}{{ $money($component->amount) }}
                    </p>
                    @can('employees.update')
                        <button type="button" wire:click="toggleComponent('{{ $component->id }}')"
                                class="focusable shrink-0 rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted hover:bg-surface-2 hover:text-ink-2">
                            {{ $component->active ? 'Pause' : 'Resume' }}
                        </button>
                    @endcan
                </div>
            @empty
                <p class="px-4 py-10 text-center text-[14px] text-muted">Salary only — no allowances or deductions.</p>
            @endforelse
        </div>

        <h3 class="mt-7 text-[15px] font-bold tracking-[-0.02em] text-ink">Payslips</h3>
        <div class="card mt-3 p-2">
            @forelse ($payslips as $index => $slip)
                <a href="{{ route('payslips.print', $slip) }}" target="_blank" wire:key="{{ $slip->id }}"
                   class="focusable flex items-center gap-3 rounded-xl px-3 py-3.5 hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-[15px] font-semibold text-ink">{{ $slip->run?->period?->translatedFormat('F Y') }}</p>
                        <p class="tnum text-[13px] text-muted">Gross {{ $money($slip->gross) }} · deductions {{ $money($slip->total_deductions) }}</p>
                    </div>
                    <p class="tnum shrink-0 text-[15px] font-bold text-ink">{{ $money($slip->net_pay) }}</p>
                    <x-icon name="printer" class="size-[17px] shrink-0 text-faint" />
                </a>
            @empty
                <p class="px-4 py-10 text-center text-[14px] text-muted">No payslips yet.</p>
            @endforelse
        </div>
    @endif

    {{-- ───────────────────────────────────────────────────────── Leave ── --}}
    @if ($panel === 'leave')
        @can('leave.request')
            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="startLeave"
                        class="tap focusable flex items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink hover:bg-surface-2">
                    <x-icon name="plus" class="size-[17px]" stroke-width="2.4" />
                    Record leave
                </button>
            </div>
        @endcan

        @if ($addingLeave)
            <div class="card mt-4 p-5">
                <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Leave</h2>
                <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                    The day count skips weekends. Public holidays are not deducted automatically — Cameroon's movable
                    feasts are announced rather than calculated — so adjust it if one falls inside.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}" for="l-type">Type</label>
                        <select id="l-type" wire:model="leaveType" class="{{ $inputClass }}">
                            @foreach (config('payroll.leave.types') as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="l-days">Working days</label>
                        <input id="l-days" type="number" step="0.5" min="0" wire:model="leaveDays" class="{{ $inputClass }} tnum">
                        @error('leaveDays') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="l-from">From</label>
                        <input id="l-from" type="date" wire:model.live="leaveFrom" class="{{ $inputClass }}">
                        @error('leaveFrom') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $labelClass }}" for="l-to">To</label>
                        <input id="l-to" type="date" wire:model.live="leaveTo" class="{{ $inputClass }}">
                        @error('leaveTo') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}" for="l-reason">Reason <span class="font-normal text-faint">(optional)</span></label>
                        <input id="l-reason" type="text" wire:model="leaveReason" class="{{ $inputClass }}">
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                    <button type="button" wire:click="saveLeave"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                        Request
                    </button>
                    <button type="button" wire:click="$set('addingLeave', false)"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        <div class="card mt-4 p-2">
            @forelse ($employee->leaveRequests as $index => $leave)
                <div wire:key="{{ $leave->id }}" class="rounded-xl px-3 py-3.5 {{ $index > 0 ? 'border-t border-border' : '' }}">
                    <div class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-semibold text-ink">{{ $leave->typeLabel() }}</p>
                            <p class="tnum truncate text-[13px] text-muted">
                                {{ $leave->starts_on->format('j M') }} — {{ $leave->ends_on->format('j M Y') }}
                                @unless ($leave->paid) · unpaid @endunless
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="tnum text-[15px] font-bold text-ink">{{ rtrim(rtrim(number_format((float) $leave->days, 1), '0'), '.') }} d</p>
                            <p class="text-[11.5px] font-semibold
                                      {{ $leave->status === 'approved' ? 'text-positive' : ($leave->status === 'declined' ? 'text-negative' : 'text-warning') }}">
                                {{ ucfirst($leave->status) }}
                            </p>
                        </div>
                    </div>

                    @if ($leave->isPending())
                        @can('leave.approve')
                            <div class="mt-2.5 flex flex-wrap gap-2">
                                <button type="button" wire:click="decideLeave('{{ $leave->id }}', 'approve')"
                                        class="focusable rounded-lg bg-surface-2 px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-tint-green hover:text-positive">
                                    Approve
                                </button>
                                <button type="button" wire:click="decideLeave('{{ $leave->id }}', 'decline')"
                                        class="focusable rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-negative hover:bg-tint-red">
                                    Decline
                                </button>
                            </div>
                        @endcan
                    @endif
                </div>
            @empty
                <p class="px-4 py-10 text-center text-[14px] text-muted">No leave recorded.</p>
            @endforelse
        </div>
    @endif
</div>
