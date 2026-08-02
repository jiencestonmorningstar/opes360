@php
    use App\Models\Employee;
    use App\Support\Accent;
    use App\Support\Money;

    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Team</h1>
            <p class="mt-1 text-[14.5px] text-muted">Everyone on the payroll, whether or not they log in.</p>
        </div>

        @can('employees.create')
            <button type="button" wire:click="startAdding"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                <span class="sr-only min-[420px]:not-sr-only">Add</span>
            </button>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    <div class="mt-5 grid grid-cols-1 gap-3 min-[400px]:grid-cols-2">
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">On the team</p>
            <p class="tnum mt-1 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $headcount }}</p>
        </div>
        <div class="card p-4">
            <p class="text-[12.5px] font-medium text-muted">Monthly base wages</p>
            <p class="tnum mt-1 text-[20px] font-bold tracking-[-0.02em] text-ink">{{ $money($wageBill) }}</p>
        </div>
    </div>

    @if ($adding)
        <div class="card mt-5 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Add someone to the team</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                This creates the person and their first contract together — nobody can be paid without one.
                Allowances and later contracts go on their page.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="emp-first">First name</label>
                    <input id="emp-first" type="text" wire:model="firstName" class="{{ $inputClass }}" placeholder="Yvonne">
                    @error('firstName') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="emp-last">Last name</label>
                    <input id="emp-last" type="text" wire:model="lastName" class="{{ $inputClass }}" placeholder="Ngo Bell">
                    @error('lastName') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="emp-title">Job title</label>
                    <input id="emp-title" type="text" wire:model="jobTitle" class="{{ $inputClass }}" placeholder="Vendeuse">
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="emp-dept">Department <span class="font-normal text-faint">(optional)</span></label>
                    <input id="emp-dept" type="text" wire:model="department" class="{{ $inputClass }}" placeholder="Boutique">
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="emp-phone">Phone</label>
                    <input id="emp-phone" type="tel" wire:model="phone" class="{{ $inputClass }}" placeholder="+237 6…">
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="emp-cnps">CNPS number <span class="font-normal text-faint">(optional)</span></label>
                    <input id="emp-cnps" type="text" wire:model="cnpsNumber" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="emp-hired">Started on</label>
                    <input id="emp-hired" type="date" wire:model="hiredOn" class="{{ $inputClass }}">
                    @error('hiredOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="emp-type">Contract</label>
                    <select id="emp-type" wire:model.live="contractType" class="{{ $inputClass }}">
                        @foreach (config('payroll.contract_types') as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($contractType !== 'cdi')
                    <div>
                        <label class="{{ $labelClass }}" for="emp-ends">Contract ends</label>
                        <input id="emp-ends" type="date" wire:model="contractEndsOn" class="{{ $inputClass }}">
                        @error('contractEndsOn') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label class="{{ $labelClass }}" for="emp-salary">Monthly salary</label>
                    <input id="emp-salary" type="number" step="1" min="0" inputmode="numeric" wire:model.live.debounce.500ms="baseSalary" class="{{ $inputClass }} tnum">
                    @error('baseSalary') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    @if ($baseSalary !== '' && (float) $baseSalary > 0 && (float) $baseSalary < config('payroll.smig'))
                        <p class="mt-1.5 text-[13px] font-medium text-warning">
                            That is below the {{ $money(config('payroll.smig')) }} minimum wage. Recorded anyway — part-time and
                            apprenticeships legitimately sit below it.
                        </p>
                    @endif
                </div>

                <div>
                    <label class="{{ $labelClass }}" for="emp-method">Paid by</label>
                    <select id="emp-method" wire:model="paymentMethod" class="{{ $inputClass }}">
                        @foreach (Employee::PAYMENT_METHODS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="save"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                    Add to team
                </button>
                <button type="button" wire:click="cancel"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <div class="relative mt-5">
        <x-icon name="search" class="pointer-events-none absolute left-4 top-1/2 size-[19px] -translate-y-1/2 text-faint" />
        <input type="search" wire:model.live.debounce.300ms="search" aria-label="Search the team"
               placeholder="Search by name or job title…"
               class="h-12 w-full rounded-xl border border-border bg-surface pl-11 pr-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
    </div>

    <div class="no-scrollbar -mx-5 mt-4 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Filter the team">
        @foreach (['active' => 'Active', 'ended' => 'Left', 'all' => 'Everyone'] as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')"
                    aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $filter === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="card mt-4 p-2" wire:loading.class="opacity-60">
        @forelse ($employees as $index => $employee)
            @php
                $accent = Accent::forKey($employee->id);
                $contract = $employee->activeContract();
            @endphp
            <a href="{{ route('team.show', $employee) }}" wire:key="{{ $employee->id }}" wire:navigate
               class="focusable flex items-center gap-3.5 rounded-xl px-3 py-3 transition-colors hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                <span class="flex size-[42px] shrink-0 items-center justify-center rounded-full text-[14px] font-bold {{ Accent::tint($accent) }} {{ Accent::text($accent) }}">
                    {{ $employee->initials() }}
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-semibold text-ink">{{ $employee->name() }}</p>
                    <p class="truncate text-[13px] text-muted">
                        {{ $employee->job_title ?: 'No job title' }}
                        @if ($contract) · {{ $contract->typeLabel() }} @endif
                    </p>
                </div>

                <div class="shrink-0 text-right">
                    @if ($contract)
                        <p class="tnum text-[14px] font-bold text-ink sm:text-[15px]">{{ $money($contract->base_salary) }}</p>
                    @endif
                    @if (! $employee->isActive())
                        <p class="text-[11.5px] font-semibold text-faint">{{ Employee::STATUSES[$employee->status] ?? $employee->status }}</p>
                    @elseif ($contract?->expiresSoon())
                        <p class="text-[11.5px] font-semibold text-warning">Ends {{ $contract->ends_on->format('j M') }}</p>
                    @elseif (! $contract)
                        <p class="text-[11.5px] font-semibold text-negative">No contract</p>
                    @endif
                </div>

                {{-- Decoration, and the row is already a link — so on a narrow
                     phone it yields its width to the name, which is the thing
                     someone is actually scanning the list for. --}}
                <x-icon name="chevron-right" class="hidden size-[18px] shrink-0 text-faint min-[420px]:block" />
            </a>
        @empty
            <div class="px-4 py-12 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-tint-slate">
                    <x-icon name="user" class="size-[24px] text-accent-slate" stroke-width="1.7" />
                </span>
                <p class="mt-4 text-[15.5px] font-semibold text-ink">
                    {{ $search !== '' ? 'Nobody matches that.' : 'Nobody on the team yet' }}
                </p>
                <p class="mx-auto mt-1.5 max-w-xs text-[13.5px] leading-relaxed text-muted">
                    {{ $search !== ''
                        ? 'Try a different search, or clear the filter.'
                        : 'Add your staff here and the payroll builds itself from their contracts — CNPS, IRPP and all.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if ($employees->hasPages())
        <div class="mt-5">{{ $employees->links() }}</div>
    @endif
</div>
