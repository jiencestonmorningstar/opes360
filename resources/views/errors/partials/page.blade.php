{{--
    The one skeleton behind 404/419/500/503.

    Everything is inline: styles, the icon (an SVG glyph, not an icon
    component), the fonts (system stack). The rule is that this file may
    depend on nothing that can break — not the Vite manifest, not a session,
    not the database, not a Blade component that reads config at render time.
    The palette hand-copies the app's tokens (brand blue #2563eb, ink, muted,
    the tinted circles) so it *looks* like errors/403.blade.php without
    *needing* what 403 needs.

    Expects: $code, $tone ('blue'|'orange'), $title, $message, $action,
    and optionally $href (defaults to the app root).
--}}
@php
    $href = $href ?? url('/');
    $accent = $tone === 'orange'
        ? ['bg' => 'rgba(245, 158, 11, .12)', 'fg' => '#d97706']
        : ['bg' => 'rgba(37, 99, 235, .10)', 'fg' => '#2563eb'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — {{ config('app.name', 'Opes360') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f8fafc; color: #0f172a;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; text-align: center;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { width: 100%; max-width: 420px; }
        .badge {
            width: 70px; height: 70px; margin: 0 auto; border-radius: 9999px;
            display: flex; align-items: center; justify-content: center;
            background: {{ $accent['bg'] }}; color: {{ $accent['fg'] }};
        }
        .code { margin-top: 18px; font-size: 13px; font-weight: 600; letter-spacing: .08em; color: #94a3b8; }
        h1 { margin-top: 6px; font-size: 21px; font-weight: 700; letter-spacing: -0.02em; }
        p { margin-top: 8px; font-size: 14px; line-height: 1.6; color: #64748b; }
        .btn {
            margin-top: 28px; display: inline-flex; align-items: center; justify-content: center;
            height: 44px; padding: 0 24px; border-radius: 12px;
            background: #2563eb; color: #fff; font-size: 14.5px; font-weight: 600;
            text-decoration: none; transition: opacity .15s;
        }
        .btn:hover { opacity: .9; }
        @media (prefers-color-scheme: dark) {
            body { background: #0b1220; color: #e2e8f0; }
            p { color: #94a3b8; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <span class="badge">
            @if ($tone === 'orange')
                {{-- The app's "alert" glyph, drawn inline. --}}
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                    <line x1="12" y1="9" x2="12" y2="13" />
                    <line x1="12" y1="17" x2="12.01" y2="17" />
                </svg>
            @else
                {{-- A compass: "you are here, home is that way". --}}
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" />
                    <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76" />
                </svg>
            @endif
        </span>

        <div class="code">{{ $code }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>

        <a class="btn" href="{{ $href }}">{{ $action }}</a>
    </div>
</body>
</html>
