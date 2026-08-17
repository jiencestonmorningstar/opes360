{{--
    Branded 404. Deliberately self-contained — no app layout, no Vite, no
    components — because an error page that itself needs the asset pipeline
    and a database-backed session to render is an error page that can 500.
    The inline palette mirrors errors/403.blade.php's look (centred card,
    tinted icon circle, ink/muted type) without depending on the compiled CSS.
--}}
@include('errors.partials.page', [
    'code' => 404,
    'tone' => 'blue',
    'title' => 'That page is not here',
    'message' => 'The address may be mistyped, or the thing it pointed at may have been removed.',
    'action' => 'Back home',
])
