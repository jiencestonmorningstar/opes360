{{--
    419 is the page most people will actually meet: every Livewire form left
    open past the session lifetime answers with it on the next click. So the
    copy says the true thing — the session expired, refresh and carry on —
    rather than the framework's alarming "Page Expired". Self-contained for
    the same reason as the other error pages: no layout that can itself fail.
--}}
@include('errors.partials.page', [
    'code' => 419,
    'tone' => 'orange',
    'title' => 'Your session expired',
    'message' => 'Nothing is wrong — the page just sat open for a while. Refresh and pick up where you left off; anything already saved is still saved.',
    'action' => 'Refresh',
    'href' => 'javascript:location.reload()',
])
