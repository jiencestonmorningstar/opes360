@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- Always the wordmark, and always as live text: every message this
     application sends is its own, and an image header renders as a grey box
     in the many clients that block images by default. Two-toned to match the
     app and the printed stationery — mail that does not look like the
     product it came from is the mail people report as phishing. --}}
<span class="wordmark"><span class="wordmark-prefix">{{ config('opes.brand.name_prefix') }}</span><span class="wordmark-suffix">{{ config('opes.brand.name_suffix') }}</span></span>
<span class="wordmark-tagline">{{ config('opes.brand.tagline') }}</span>
</a>
</td>
</tr>
