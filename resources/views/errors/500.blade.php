{{--
    Branded 500. Self-contained above all others: whatever just broke may be
    the database, the cache, or the asset pipeline, and this page must render
    with none of them. It says nothing about what failed — the details are in
    the log, not on a page anyone can reach by causing an error.
--}}
@include('errors.partials.page', [
    'code' => 500,
    'tone' => 'orange',
    'title' => 'Something went wrong on our side',
    'message' => 'The problem has been recorded. Try again in a moment — if it keeps happening, let us know what you were doing.',
    'action' => 'Try again',
    'href' => 'javascript:location.reload()',
])
