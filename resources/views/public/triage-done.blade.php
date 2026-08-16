{{--
    The confirmation — the visitor's queue ticket. The reference is set huge
    because it *is* their number: they will show this screen, or read it out,
    when they are called.
--}}
<x-layouts.public :title="'Your queue number · '.$company->name" :brand-company="$company" robots="noindex" width="max-w-[560px]">
    <div class="card border-t-4 border-t-brand px-6 py-10 text-center">
        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">{{ $company->name }}</p>
        <p class="mt-3 text-[15px] text-muted">You're in the queue. Your number is</p>

        <p class="mt-3 break-all text-[40px] font-bold leading-none tracking-[-0.02em] text-ink">
            {{ $ticket->reference }}
        </p>

        <p class="mt-6 text-[15px] text-ink-2">
            @if ($ahead === 0)
                <span class="font-semibold">You're next.</span> Someone will be with you shortly.
            @else
                <span class="font-semibold">{{ $ahead }}</span>
                {{ $ahead === 1 ? 'person is' : 'people are' }} ahead of you.
            @endif
        </p>

        <p class="mt-2 text-[13.5px] text-muted">
            Keep this screen open, or note the number — it's how we'll call you.
        </p>
    </div>
</x-layouts.public>
