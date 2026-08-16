{{--
    The walk-in triage page — what the QR on the counter opens.

    Filled standing in a doorway on a phone, so: one column, big tap targets,
    and the shortest form that still tells the desk what it needs. The urgency
    choices are the customer's words on purpose; what they map to on the desk
    is the desk's business, and saying "this becomes priority X" here would
    invite gaming it.
--}}
<x-layouts.public :title="'Get help · '.$company->name" :brand-company="$company" robots="noindex" width="max-w-[560px]">
    <div class="card border-t-4 border-t-brand p-6">
        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $company->name }}</p>
        <h1 class="mt-1.5 text-[24px] font-bold leading-tight tracking-[-0.02em] text-ink">Tell us what you need</h1>
        <p class="mt-2 text-[14.5px] leading-relaxed text-muted">
            Answer a few questions and you'll get a queue number — no need to wait at the counter.
        </p>
    </div>

    @if ($closed)
        <div class="card mt-4 px-6 py-10 text-center">
            <p class="text-[16px] font-semibold text-ink">{{ $company->name }} is not taking walk-in requests right now.</p>
            <p class="mt-1.5 text-[13.5px] text-muted">Please speak to someone at the counter.</p>
        </div>
    @else
        @if ($errors->any())
            <div class="card mt-4 border-warning/40 bg-tint-orange px-5 py-4">
                <p class="text-[13.5px] font-semibold text-warning">Check the highlighted answers below.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('triage.submit', $token) }}" class="mt-4 space-y-4">
            @csrf

            <div class="card p-5">
                <label class="block text-[14px] font-semibold text-ink">What is it about?</label>
                <div class="mt-2.5 space-y-2">
                    @foreach ($categories as $value => $label)
                        <label class="tap flex items-center gap-3 rounded-xl border border-border px-4 py-3">
                            <input type="radio" name="category" value="{{ $value }}"
                                   @checked(old('category') === $value) required class="size-4">
                            <span class="text-[15px] text-ink">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('category') <p class="mt-1.5 text-[13px] text-warning">{{ $message }}</p> @enderror
            </div>

            <div class="card p-5">
                <label for="description" class="block text-[14px] font-semibold text-ink">Tell us a little more</label>
                <textarea id="description" name="description" rows="4" required maxlength="2000"
                          class="focusable mt-2 w-full rounded-xl border border-border bg-transparent px-4 py-3 text-[15px] text-ink"
                          placeholder="What happened, and to what?">{{ old('description') }}</textarea>
                @error('description') <p class="mt-1.5 text-[13px] text-warning">{{ $message }}</p> @enderror
            </div>

            <div class="card p-5">
                <label class="block text-[14px] font-semibold text-ink">How urgent is it for you?</label>
                <div class="mt-2.5 space-y-2">
                    @foreach (['whenever' => 'Whenever you get to it', 'today' => 'Today would be good', 'now' => 'I need help right now'] as $value => $label)
                        <label class="tap flex items-center gap-3 rounded-xl border border-border px-4 py-3">
                            <input type="radio" name="urgency" value="{{ $value }}"
                                   @checked(old('urgency') === $value) required class="size-4">
                            <span class="text-[15px] text-ink">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('urgency') <p class="mt-1.5 text-[13px] text-warning">{{ $message }}</p> @enderror
            </div>

            <div class="card space-y-4 p-5">
                <div>
                    <label for="name" class="block text-[14px] font-semibold text-ink">Your name</label>
                    <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name') }}"
                           autocomplete="name"
                           class="focusable mt-2 w-full rounded-xl border border-border bg-transparent px-4 py-3 text-[15px] text-ink">
                    @error('name') <p class="mt-1.5 text-[13px] text-warning">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="block text-[14px] font-semibold text-ink">Your phone number</label>
                    <input id="phone" name="phone" type="tel" required maxlength="40" value="{{ old('phone') }}"
                           autocomplete="tel" inputmode="tel"
                           class="focusable mt-2 w-full rounded-xl border border-border bg-transparent px-4 py-3 text-[15px] text-ink">
                    @error('phone') <p class="mt-1.5 text-[13px] text-warning">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit"
                    class="tap focusable w-full rounded-full bg-fill-brand px-6 py-3.5 text-[16px] font-semibold text-white">
                Get my queue number
            </button>
        </form>
    @endif
</x-layouts.public>
