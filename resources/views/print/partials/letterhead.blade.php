{{--
    The letterhead: logo, name, motto, address, contacts and fiscal identity.

    One partial shared by every printed document — invoice, quotation, receipt,
    statement, payslip — because a business that changes its logo expects all of
    them to change together, and five copies of this markup is five places for
    that to be half-done.

    Nothing here is stored on the document. The company is read at render time,
    so editing the letterhead updates every document ever issued, including ones
    printed years ago. That is what a business means by "our letterhead changed".

    It does not touch what the QR verifies: the content hash covers the number,
    dates, lines and totals, and deliberately not the presentation around them.
    A reprinted invoice therefore shows today's logo over the same figures, and
    still verifies.

    @param  \App\Models\Company  $company
    @param  string|null  $align  'left' (default) or 'center'
--}}
@php
    $logoUrl = $company->logoUrl();
    $centred = ($align ?? 'left') === 'center';

    $address = implode(' · ', array_filter([
        $company->address_line1,
        trim(($company->city ? $company->city.', ' : '').($company->country ?? ''), ', ') ?: null,
    ]));

    $contacts = implode(' · ', array_filter([
        data_get($company->phones, 0),
        $company->email,
        $company->website,
    ]));
@endphp

<div class="letterhead {{ $centred ? 'letterhead-center' : '' }}">
    @if ($logoUrl)
        {{-- Constrained rather than sized: a business uploads whatever it has,
             and a tall logo must not push the rest of the sheet down the page. --}}
        <img class="letterhead-logo" src="{{ $logoUrl }}" alt="{{ $company->name }}">
    @endif

    <div class="brand-name">{{ $company->name }}</div>

    @if ($company->motto)
        <div class="muted small">{{ $company->motto }}</div>
    @endif

    @if ($address !== '' || $contacts !== '')
        <div class="muted small" style="margin-top:6px">
            {{ $address }}@if ($address !== '' && $contacts !== '')<br>@endif{{ $contacts }}
        </div>
    @endif

    {{-- The fiscal identity a DGI-acceptable invoice has to state. The NIU is
         the mention most closely checked: without the supplier's, a customer
         cannot deduct the TVA they were charged. --}}
    @if ($company->registration_number)
        <div class="muted small">RCCM {{ $company->registration_number }}</div>
    @endif

    @if ($company->tax_id)
        <div class="muted small">NIU {{ $company->tax_id }}</div>
    @endif
</div>
