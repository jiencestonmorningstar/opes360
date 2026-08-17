{{--
    Maintenance mode (`php artisan down`). Self-contained like the rest —
    during a deploy the asset manifest is exactly the thing that may be
    mid-swap, so this page must not ask for it.
--}}
@include('errors.partials.page', [
    'code' => 503,
    'tone' => 'blue',
    'title' => 'Down for a moment',
    'message' => 'We are doing planned maintenance. Everything is safe — check back in a few minutes.',
    'action' => 'Check again',
    'href' => 'javascript:location.reload()',
])
