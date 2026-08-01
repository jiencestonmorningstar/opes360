<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('opes.brand.name') }} — {{ config('opes.brand.tagline') }}.
Built by [{{ config('opes.brand.vendor') }}]({{ config('opes.brand.vendor_url') }}).

You are receiving this because you have an account on {{ config('opes.brand.name') }}.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
