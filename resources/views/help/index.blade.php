@php
    use App\Support\Accent;

    $topics = [
        ['icon' => 'document-plus', 'accent' => 'blue', 'title' => 'Create an invoice',
         'body' => 'Sales → New Invoice, or the Quick Actions grid on your dashboard. Pick a customer, add items, then Save Draft or Issue. Drafts can be edited freely; issuing assigns the permanent number.'],
        ['icon' => 'banknotes', 'accent' => 'green', 'title' => 'Record a payment',
         'body' => 'Open any unpaid invoice and choose Record Payment. The amount is prefilled with the balance — edit it down for a part-payment. A numbered receipt is issued automatically.'],
        ['icon' => 'qr-code', 'accent' => 'pink', 'title' => 'How verification works',
         'body' => 'Every issued document and receipt carries a QR code. Anyone who scans it sees a public page confirming who issued it, the amount, and whether it is still valid — without needing an account.'],
        ['icon' => 'printer', 'accent' => 'orange', 'title' => 'Print or save as PDF',
         'body' => 'Use Print / PDF on any document, or the printer icon beside a payment. Receipts support A4, A5 and 58mm or 80mm thermal rolls. Your browser’s print dialog can save any of them as a PDF.'],
        ['icon' => 'cube', 'accent' => 'purple', 'title' => 'Track stock',
         'body' => 'Turn on stock tracking when adding a product and set an opening quantity. Every sale is recorded as a movement, and the dashboard warns you when an item drops to its reorder level.'],
        ['icon' => 'briefcase', 'accent' => 'teal', 'title' => 'Your public page',
         'body' => 'Business → View public page shows what customers see when they scan your business QR: your logo, contact details, verification badge and what you sell.'],
    ];
@endphp

<x-layouts.app title="Help & Support" active="help">
    <div class="px-5 pb-8 lg:px-6 lg:pt-6">

        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Help &amp; Support</h1>
            <p class="mt-1 text-[14.5px] text-muted">Short answers to the things people ask first.</p>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            @foreach ($topics as $topic)
                <section class="card p-5">
                    <div class="flex items-start gap-3.5">
                        <span class="flex size-[46px] shrink-0 items-center justify-center rounded-xl {{ Accent::tint($topic['accent']) }}">
                            <x-icon :name="$topic['icon']" class="size-[22px] {{ Accent::text($topic['accent']) }}" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-[15.5px] font-bold text-ink">{{ $topic['title'] }}</h2>
                            <p class="mt-1.5 text-[13.5px] leading-relaxed text-muted">{{ $topic['body'] }}</p>
                        </div>
                    </div>
                </section>
            @endforeach
        </div>

        <section class="card mt-4 flex flex-col items-center p-7 text-center">
            <span class="flex size-[58px] items-center justify-center rounded-full bg-tint-blue">
                <x-icon name="help" class="size-7 text-accent-blue" />
            </span>
            <h2 class="mt-4 text-[17px] font-bold text-ink">Still stuck?</h2>
            <p class="mt-1.5 max-w-md text-[13.5px] text-muted">
                OPES360 is built by {{ config('opes.brand.vendor') }}. Reach the team and we will get back to you.
            </p>
            <a href="{{ config('opes.brand.vendor_url') }}" target="_blank"
               class="focusable mt-4 flex h-11 items-center rounded-xl bg-brand px-6 text-[14.5px] font-semibold text-white hover:opacity-90">
                Contact Opesware
            </a>
        </section>
    </div>
</x-layouts.app>
