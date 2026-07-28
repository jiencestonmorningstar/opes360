@php
    $phone = data_get($artisan->phones, 0);
    $rating = $artisan->averageRating();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $artisan->full_name }} · {{ $artisan->occupation ?? 'Artisan' }}</title>
    <meta name="description" content="{{ $artisan->occupation }} at {{ $company->name }}">

    <script>
        (function () {
            try {
                var stored = localStorage.getItem('opes-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored === 'dark' || (stored !== 'light' && system));
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css'])
</head>

<body class="flex min-h-full flex-col items-center px-5 py-8">
<main class="w-full max-w-[480px]">

    <div class="card flex flex-col items-center p-7 text-center">
        @if ($artisan->photoUrl())
            <img src="{{ $artisan->photoUrl() }}" alt="" class="size-[92px] rounded-full object-cover">
        @else
            <span class="flex size-[92px] items-center justify-center rounded-full bg-tint-blue text-[28px] font-bold text-accent-blue">
                {{ $artisan->initials() }}
            </span>
        @endif

        <h1 class="mt-4 text-[23px] font-bold tracking-[-0.02em] text-ink">{{ $artisan->full_name }}</h1>

        @if ($artisan->occupation)
            <p class="mt-1 text-[14.5px] font-medium text-muted">{{ $artisan->occupation }}</p>
        @endif

        <p class="mt-0.5 text-[13px] text-faint">{{ $company->name }}</p>

        <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
            @if ($artisan->is_verified)
                <span class="flex items-center gap-1.5 rounded-full bg-tint-green px-3.5 py-1.5">
                    <x-icon name="check-circle" class="size-[16px] text-positive" stroke-width="2.2" />
                    <span class="text-[12.5px] font-semibold text-positive">Verified artisan</span>
                </span>
            @endif
            @if ($rating)
                <span class="rounded-full bg-tint-orange px-3.5 py-1.5 text-[12.5px] font-semibold text-warning">
                    {{ $rating }} / 5 · {{ $artisan->testimonials->count() }} reviews
                </span>
            @endif
            @if ($artisan->years_experience)
                <span class="rounded-full bg-surface-2 px-3.5 py-1.5 text-[12.5px] font-semibold text-ink-2">
                    {{ $artisan->years_experience }} yrs experience
                </span>
            @endif
        </div>

        @if ($artisan->biography)
            <p class="mt-4 text-[14px] leading-relaxed text-ink-2">{{ $artisan->biography }}</p>
        @endif

        {{-- Instant actions: the whole point of a scanned profile. --}}
        <div class="mt-6 grid w-full grid-cols-4 gap-2">
            @if ($phone)
                <a href="tel:{{ $phone }}" class="focusable flex flex-col items-center gap-1.5 rounded-xl py-3 hover:bg-surface-2">
                    <span class="flex size-[46px] items-center justify-center rounded-full bg-tint-blue"><x-icon name="bell" class="size-[21px] text-accent-blue" /></span>
                    <span class="text-[11.5px] font-medium text-ink-2">Call</span>
                </a>
            @endif
            @if ($artisan->whatsapp)
                <a href="https://wa.me/{{ preg_replace('/\D+/', '', $artisan->whatsapp) }}" class="focusable flex flex-col items-center gap-1.5 rounded-xl py-3 hover:bg-surface-2">
                    <span class="flex size-[46px] items-center justify-center rounded-full bg-tint-green"><x-icon name="users" class="size-[21px] text-accent-green" /></span>
                    <span class="text-[11.5px] font-medium text-ink-2">WhatsApp</span>
                </a>
            @endif
            @if ($artisan->email)
                <a href="mailto:{{ $artisan->email }}" class="focusable flex flex-col items-center gap-1.5 rounded-xl py-3 hover:bg-surface-2">
                    <span class="flex size-[46px] items-center justify-center rounded-full bg-tint-purple"><x-icon name="document" class="size-[21px] text-accent-purple" /></span>
                    <span class="text-[11.5px] font-medium text-ink-2">Email</span>
                </a>
            @endif
            <a href="{{ route('profile.artisan.vcard', $artisan) }}" class="focusable flex flex-col items-center gap-1.5 rounded-xl py-3 hover:bg-surface-2">
                <span class="flex size-[46px] items-center justify-center rounded-full bg-tint-orange"><x-icon name="user-plus" class="size-[21px] text-accent-orange" /></span>
                <span class="text-[11.5px] font-medium text-ink-2">Save</span>
            </a>
        </div>
    </div>

    @if ($artisan->skills)
        <div class="card mt-4 p-6">
            <h2 class="text-[16px] font-bold text-ink">Skills</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($artisan->skills as $skill)
                    <span class="rounded-full bg-tint-blue px-3.5 py-1.5 text-[12.5px] font-semibold text-brand">{{ $skill }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card mt-4 p-6">
        <h2 class="text-[16px] font-bold text-ink">Details</h2>
        <dl class="mt-4 space-y-3">
            @foreach (array_filter([
                'ID' => $artisan->artisan_number,
                'Trade' => $artisan->trade_category,
                'Serves' => $artisan->coverage_area ? implode(', ', $artisan->coverage_area) : null,
                'Languages' => $artisan->languages ? implode(', ', $artisan->languages) : null,
                'Based in' => data_get($artisan->address, 'city'),
                'Phone' => $phone,
                'Email' => $artisan->email,
            ]) as $label => $value)
                <div class="flex items-start justify-between gap-4">
                    <dt class="shrink-0 text-[13.5px] text-muted">{{ $label }}</dt>
                    <dd class="text-right text-[13.5px] font-semibold text-ink-2 break-all">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    @if ($artisan->testimonials->isNotEmpty())
        <div class="card mt-4 p-6">
            <h2 class="text-[16px] font-bold text-ink">What customers say</h2>
            <div class="mt-4 space-y-3">
                @foreach ($artisan->testimonials as $testimonial)
                    <div class="rounded-xl bg-surface-2 p-4">
                        <p class="text-[13.5px] leading-relaxed text-ink-2">{{ $testimonial->body }}</p>
                        <p class="mt-2 text-[12px] font-semibold text-muted">
                            {{ $testimonial->author_name }}
                            @if ($testimonial->rating) · {{ $testimonial->rating }}/5 @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <p class="mt-7 text-center text-[12px] text-faint">
        Verified by <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
        · <a href="{{ route('profile.business', $company) }}" class="hover:underline">{{ $company->name }}</a>
    </p>
</main>
</body>
</html>
