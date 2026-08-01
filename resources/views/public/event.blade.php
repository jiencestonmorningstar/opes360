@php use App\Support\Money; @endphp

<x-layouts.public :title="$event->title.' · '.$company->name" robots="noindex" width="max-w-[560px]">
    <div class="card overflow-hidden">
        <div class="border-t-4 border-t-brand p-6">
            <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $company->name }} presents</p>
            <h1 class="mt-1.5 text-[26px] font-bold leading-tight tracking-[-0.02em] text-ink">{{ $event->title }}</h1>

            <div class="mt-4 space-y-2">
                <p class="flex items-center gap-2.5 text-[14.5px] text-ink-2">
                    <x-icon name="calendar" class="size-[18px] shrink-0 text-muted" stroke-width="1.9" />
                    {{ $event->starts_at->format('l, F j, Y · g:ia') }}@if ($event->ends_at) – {{ $event->ends_at->format($event->ends_at->isSameDay($event->starts_at) ? 'g:ia' : 'M j, g:ia') }}@endif
                </p>
                @if ($event->venue)
                    <p class="flex items-center gap-2.5 text-[14.5px] text-ink-2">
                        <x-icon name="home" class="size-[18px] shrink-0 text-muted" stroke-width="1.9" />
                        {{ $event->venue }}
                    </p>
                @endif
            </div>

            @if ($event->description)
                <p class="mt-4 whitespace-pre-line text-[14.5px] leading-relaxed text-muted">{{ $event->description }}</p>
            @endif
        </div>
    </div>

    @if ($event->status === 'cancelled')
        <div class="card mt-4 px-6 py-10 text-center">
            <p class="text-[16px] font-semibold text-warning">This event has been cancelled.</p>
            <p class="mt-1.5 text-[13.5px] text-muted">Contact {{ $company->name }} about tickets already bought.</p>
        </div>
    @elseif (! $event->isSelling())
        <div class="card mt-4 px-6 py-10 text-center">
            <p class="text-[16px] font-semibold text-ink">Ticket sales have closed.</p>
            <p class="mt-1.5 text-[13.5px] text-muted">The event {{ $event->starts_at->isPast() ? 'has started' : 'is not on sale' }}.</p>
        </div>
    @else
        @if ($errors->any())
            <div class="card mt-4 border-warning/40 bg-tint-orange px-5 py-4">
                <p class="text-[13.5px] font-semibold text-warning">{{ $errors->first() }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route('event.purchase', $event->share_token) }}" class="mt-4 space-y-4">
            @csrf

            <div class="card p-5">
                <p class="text-[15px] font-bold text-ink">Tickets</p>
                <div class="mt-3 space-y-3">
                    @foreach ($types as $type)
                        @php $remaining = $type->remaining(); @endphp
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-border p-4">
                            <div class="min-w-0">
                                <p class="truncate text-[14.5px] font-semibold text-ink">{{ $type->name }}</p>
                                <p class="text-[13px] text-muted">
                                    {{ (float) $type->price > 0 ? Money::format($type->price, $company->currency) : 'Free' }}
                                    @if ($remaining !== null && $remaining <= 10 && $remaining > 0)
                                        · <span class="font-semibold text-warning">{{ $remaining }} left</span>
                                    @endif
                                </p>
                            </div>

                            @if ($type->isSoldOut())
                                <span class="shrink-0 text-[13px] font-bold uppercase tracking-wide text-faint">Sold out</span>
                            @else
                                <select name="quantities[{{ $type->id }}]"
                                        class="h-11 w-[76px] shrink-0 rounded-lg border border-border bg-surface px-2.5 text-center text-[15px] font-semibold text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                    @foreach (range(0, min(10, $remaining ?? 10)) as $n)
                                        <option value="{{ $n }}" @selected((int) old('quantities.'.$type->id, 0) === $n)>{{ $n }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card space-y-4 p-5">
                <p class="text-[15px] font-bold text-ink">Your details</p>

                <div>
                    <label for="buyer_name" class="block text-[13.5px] font-semibold text-ink-2">Full name</label>
                    <input id="buyer_name" name="buyer_name" type="text" required value="{{ old('buyer_name') }}"
                           class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                </div>

                <div class="grid gap-4 min-[480px]:grid-cols-2">
                    <div>
                        <label for="buyer_email" class="block text-[13.5px] font-semibold text-ink-2">Email</label>
                        <input id="buyer_email" name="buyer_email" type="email" value="{{ old('buyer_email') }}"
                               class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </div>
                    <div>
                        <label for="buyer_phone" class="block text-[13.5px] font-semibold text-ink-2">Phone</label>
                        <input id="buyer_phone" name="buyer_phone" type="tel" value="{{ old('buyer_phone') }}"
                               class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </div>
                </div>
                <p class="text-[12.5px] text-muted">Leave an email or phone number so your tickets can reach you.</p>
            </div>

            <button type="submit"
                    class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Get tickets
            </button>
        </form>
    @endif

    <p class="mt-7 text-center text-[12px] text-faint">
        Ticketed with <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
        · Every ticket carries a verifiable QR code.
    </p>
</x-layouts.public>
