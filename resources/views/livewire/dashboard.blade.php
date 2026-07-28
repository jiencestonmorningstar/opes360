@php
    use App\Support\Accent;
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    @if ($company === null)
        <div class="card flex flex-col items-center justify-center px-6 py-16 text-center">
            <span class="flex size-[58px] items-center justify-center rounded-full bg-tint-blue">
                <x-icon name="briefcase" class="size-7 text-accent-blue" />
            </span>
            <h1 class="mt-4 text-[20px] font-bold text-ink">Set up your business</h1>
            <p class="mt-1.5 max-w-sm text-[14.5px] text-muted">
                Create your business identity to start issuing invoices, quotations and receipts.
            </p>
        </div>
    @else

        {{-- Greeting --}}
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink min-[560px]:text-[28px] lg:text-[31px]">
                    {{ $greeting }}, {{ auth()->user()?->firstName() }} <span aria-hidden="true">👋</span>
                </h1>
                <p class="mt-1.5 text-[14.5px] leading-snug text-muted min-[560px]:text-[15px] lg:text-[15.5px]">
                    Here's what's happening with your business today.
                </p>
            </div>

            {{-- The design shows the calendar glyph only in the desktop composition. --}}
            <x-ui.filter-pill :label="$rangeLabel" icon="calendar" icon-class="hidden lg:block" />
        </div>

        {{--
            Four across at the design's own width and above. Below 560px they stack
            two-up: at 390px a four-column row gives each card ~80px, which cannot
            hold a formatted currency value at a legible size.
        --}}
        <div class="mt-5 grid grid-cols-2 gap-3 min-[560px]:grid-cols-4 lg:mt-6 lg:gap-4">
            @foreach ($stats as $stat)
                <x-ui.stat-card
                    :label="$stat['label']"
                    :value="$stat['value']"
                    :icon="$stat['icon']"
                    :accent="$stat['accent']"
                    :delta="$stat['delta']"
                    :delta-tone="$stat['deltaTone']"
                    :delta-caption="$stat['deltaCaption']" />
            @endforeach
        </div>

        {{-- Quick actions · Sales overview · Top customers --}}
        <div class="mt-4 space-y-4 lg:mt-4 lg:grid lg:grid-cols-11 lg:gap-4 lg:space-y-0">

            <x-ui.panel title="Quick Actions" action="See All" class="lg:col-span-5">
                <div class="grid grid-cols-4 gap-x-2 gap-y-5">
                    @foreach (config('opes.quick_actions') as $action)
                        <x-ui.quick-action
                            :label="$action['label']"
                            :icon="$action['icon']"
                            :accent="$action['accent']"
                            :href="$action['route'] ? route($action['route']) : '#'" />
                    @endforeach
                </div>
            </x-ui.panel>

            {{-- lg:contents dissolves this wrapper into the parent grid on desktop,
                 while keeping the two panels side by side on mobile. They stack
                 below 560px, where a half-width chart cannot fit seven day labels. --}}
            <div class="grid grid-cols-1 gap-4 min-[560px]:grid-cols-2 lg:contents">

                <x-ui.panel title="Sales Overview" class="lg:col-span-3">
                    <x-slot:actions>
                        <x-ui.filter-pill label="This Week" variant="ghost" />
                    </x-slot:actions>

                    <p class="tnum text-[24px] font-bold leading-none tracking-[-0.02em] text-ink">
                        {{ $chartTotal }}
                    </p>

                    <x-ui.bar-chart :series="$chart->all()" class="mt-5" />

                    <a href="#"
                       class="focusable mt-5 flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-brand transition-colors hover:bg-tint-blue">
                        <x-icon name="trending-up" class="size-[18px]" stroke-width="2" />
                        View Report
                    </a>
                </x-ui.panel>

                <x-ui.panel title="Top Customers" class="lg:col-span-3">
                    <x-slot:actions>
                        <x-ui.filter-pill label="This Month" variant="ghost" />
                    </x-slot:actions>

                    @forelse ($topCustomers as $row)
                        @php $accent = $row['contact']->accent(); @endphp
                        <a href="#" class="focusable -mx-1.5 flex items-center gap-3 rounded-lg px-1.5 py-[9px] hover:bg-surface-2">
                            <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[12.5px] font-bold {{ Accent::text($accent) }}">
                                {{ $row['contact']->initials() }}
                            </span>
                            <span class="min-w-0 flex-1 truncate text-[14.5px] font-medium text-ink">
                                {{ $row['contact']->displayName() }}
                            </span>
                            <span class="tnum shrink-0 text-[14.5px] font-bold text-brand">
                                {{ Money::format($row['revenue'], $currency) }}
                            </span>
                        </a>
                    @empty
                        <p class="py-6 text-center text-[13.5px] text-muted">No sales this month yet.</p>
                    @endforelse

                    <a href="#"
                       class="focusable mt-3 flex items-center justify-center gap-1 text-[14px] font-semibold text-brand hover:underline">
                        View All
                        <x-icon name="chevron-right" class="size-[15px]" stroke-width="2.4" />
                    </a>
                </x-ui.panel>
            </div>
        </div>

        {{-- Recent invoices · Counters --}}
        <div class="mt-4 grid gap-4 lg:grid-cols-2">

            <x-ui.panel title="Recent Invoices" action="See All" :action-href="route('sales')" body-class="-mx-1.5">
                @forelse ($recentInvoices as $index => $invoice)
                    @php
                        $accent = Accent::byIndex($index);
                        $state = $invoice->paymentState();
                    @endphp
                    <a href="{{ route('documents.show', $invoice) }}"
                       class="focusable flex items-center gap-3.5 rounded-lg px-1.5 py-3 hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                        <span class="flex size-[46px] shrink-0 items-center justify-center rounded-xl {{ Accent::tint($accent) }}">
                            <x-icon name="document" class="size-[23px] {{ Accent::text($accent) }}" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-semibold text-ink">{{ $invoice->number }}</span>
                            <span class="block truncate text-[13px] text-muted">
                                {{ $invoice->contact?->displayName() ?? 'Walk-in customer' }}
                            </span>
                        </span>

                        <span class="shrink-0 text-right">
                            <span class="tnum block text-[15px] font-bold text-ink">
                                {{ Money::format($invoice->total, $invoice->currency) }}
                            </span>
                            <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" class="mt-0.5" />
                        </span>

                        {{-- The date column only appears in the desktop composition. --}}
                        <span class="hidden shrink-0 text-[13px] text-muted lg:block">
                            {{ $invoice->issue_date?->format('M j, Y') }}
                        </span>

                        <x-icon name="chevron-right" class="size-[18px] shrink-0 text-faint" stroke-width="2" />
                    </a>
                @empty
                    <p class="py-8 text-center text-[13.5px] text-muted">No invoices yet.</p>
                @endforelse
            </x-ui.panel>

            <section class="card px-5 py-6">
                <div class="grid grid-cols-2 gap-y-7 lg:grid-cols-4 lg:gap-y-0">
                    @foreach ($counters as $counter)
                        @php
                            $captionColour = match ($counter['captionTone']) {
                                'positive' => 'text-positive',
                                'warning' => 'text-warning',
                                'brand' => 'text-brand',
                                default => 'text-muted',
                            };
                        @endphp
                        <div class="flex flex-col items-center text-center">
                            <span class="flex size-[58px] items-center justify-center rounded-full {{ Accent::tint($counter['accent']) }}">
                                <x-icon :name="$counter['icon']" class="size-[26px] {{ Accent::text($counter['accent']) }}" stroke-width="1.7" />
                            </span>

                            <p class="mt-3 text-[13.5px] font-medium text-muted">{{ $counter['label'] }}</p>

                            <p class="tnum mt-1 text-[24px] font-bold leading-tight tracking-[-0.02em] text-ink">
                                {{ $counter['value'] }}
                            </p>

                            <p class="mt-1 flex items-center gap-1 text-[12.5px] font-medium {{ $captionColour }}">
                                @if ($counter['captionArrow'])
                                    <x-icon name="arrow-up" class="size-[13px]" stroke-width="2.6" />
                                @endif
                                {{ $counter['caption'] }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</div>
