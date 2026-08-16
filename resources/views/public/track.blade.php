{{--
    Public shipment tracking — the whole story is the status history.

    What renders here is a deliberate ceiling, not a first draft: the
    shipment's own reference, route, parties and events. Never the manifest,
    the vehicle, the driver, the money, or a word about any other customer's
    cargo. Anything added to this page is being added to a page anyone with
    the link can read.
--}}
<x-layouts.public :title="'Track '.$shipment->reference.' · '.$company->name" :brand-company="$company" robots="noindex" width="max-w-[560px]">
    <div class="card border-t-4 border-t-brand px-6 py-8">
        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $company->name }}</p>

        <h1 class="mt-2 text-[22px] font-bold tracking-[-0.01em] text-ink">{{ $shipment->reference }}</h1>

        <p class="mt-1 text-[14px] text-muted">
            {{ $shipment->from_location }} &rarr; {{ $shipment->to_location }}
        </p>

        <p class="mt-1 text-[13.5px] text-muted">
            {{ $shipment->sender?->name }} &rarr; {{ $shipment->receiver?->name }}
        </p>

        <div class="mt-4">
            <span @class([
                'inline-flex rounded-full px-3 py-1 text-[13px] font-semibold',
                'bg-emerald-100 text-emerald-800' => $shipment->status === 'delivered',
                'bg-amber-100 text-amber-800' => $shipment->status === 'in_transit',
                'bg-rose-100 text-rose-800' => $shipment->status === 'cancelled',
                'bg-slate-100 text-slate-700' => in_array($shipment->status, ['booked', 'loaded'], true),
            ])>
                {{ $shipment->statusLabel() }}
            </span>
        </div>

        <div class="mt-6 border-t border-line pt-4">
            <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">History</p>

            <ol class="mt-3 space-y-3">
                @forelse ($events as $event)
                    <li class="flex items-baseline justify-between gap-4">
                        <div>
                            <p class="text-[14px] font-semibold text-ink">{{ $event->statusLabel() }}</p>
                            @if ($event->note)
                                <p class="text-[13px] text-muted">{{ $event->note }}</p>
                            @endif
                        </div>
                        <p class="shrink-0 text-[12.5px] text-faint">{{ $event->happened_at->format('d/m/Y H:i') }}</p>
                    </li>
                @empty
                    <li class="text-[13.5px] text-muted">No movements recorded yet.</li>
                @endforelse
            </ol>
        </div>
    </div>
</x-layouts.public>
