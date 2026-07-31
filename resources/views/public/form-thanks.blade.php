<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Response recorded · {{ $company->name }}</title>
    <meta name="robots" content="noindex">

    <script @cspNonce>
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

<body class="flex min-h-full flex-col items-center justify-center px-5 py-10">
<main class="w-full max-w-[440px]">
    <div class="card flex flex-col items-center p-8 text-center">
        <span class="flex size-[74px] items-center justify-center rounded-full bg-tint-green">
            <x-icon name="check-circle" class="size-9 text-positive" stroke-width="1.8" />
        </span>
        <h1 class="mt-4 text-[23px] font-bold tracking-[-0.02em] text-ink">Response recorded</h1>
        <p class="mt-1.5 text-[14px] leading-snug text-muted">
            Thanks — your answers to “{{ $form->title }}” have reached {{ $company->name }}.
        </p>

        @if ($form->isOpen())
            <a href="{{ route('form.public', $form->share_token) }}"
               class="focusable mt-6 text-[13.5px] font-semibold text-brand hover:underline">
                Submit another response
            </a>
        @endif
    </div>

    <p class="mt-7 text-center text-[12px] text-faint">
        Powered by <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
    </p>
</main>
</body>
</html>
