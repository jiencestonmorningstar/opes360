<x-layouts.marketing title="Terms of Service">
<x-marketing.page-header eyebrow="Legal" title="Terms of Service"
    :lead="'Last updated '.now()->format('F Y').'.'" />

<div class="mx-auto max-w-3xl px-5 py-14 sm:py-20">

    <div class="space-y-8 text-[15px] leading-relaxed text-ink-2">
        <section>
            <h2 class="text-[17px] font-bold text-ink">The service</h2>
            <p class="mt-2">
                {{ config('opes.brand.name') }} is a business operations suite provided by {{ config('opes.brand.vendor') }}.
                By creating a business, requesting a demo, or otherwise using the product, you agree to these terms.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Accounts and plans</h2>
            <p class="mt-2">
                A demo account runs for 14 days and then automatically converts to a free trial — nothing you build
                is deleted or locked at that point. Paid plans (Basic, Growth, Business) are billed monthly or
                annually as described on the <a href="{{ route('marketing.pricing') }}" class="font-semibold text-brand hover:underline">pricing page</a>,
                and each plan's included modules are listed there. You are responsible for the accuracy of the
                business, customer, and financial data you enter.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Documents you generate</h2>
            <p class="mt-2">
                Business documents generated from a template (contracts, letters, agreements) are drafting aids, not
                legal advice. You are responsible for reviewing anything you issue before relying on it, and for
                its accuracy and legality in your jurisdiction.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Verification and loyalty</h2>
            <p class="mt-2">
                The QR verification pages behind your documents, receipts, tickets, and loyalty cards are provided
                to help your customers confirm authenticity. Misusing them to misrepresent a document or a balance
                is a breach of these terms.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Acceptable use</h2>
            <p class="mt-2">
                You will not use the platform to send unsolicited communications, store unlawful content, or attempt
                to access another business's data. We may suspend an account that does.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Availability</h2>
            <p class="mt-2">
                We aim for the service to be available and reliable, including its offline mode for previously
                loaded data, but we do not guarantee uninterrupted access and are not liable for losses arising from
                downtime.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Changes</h2>
            <p class="mt-2">
                We may update these terms as the product evolves. Continued use after a change means you accept the
                updated terms.
            </p>
        </section>

        <section>
            <h2 class="text-[17px] font-bold text-ink">Contact</h2>
            <p class="mt-2">
                Questions about these terms can be sent through the <a href="{{ route('marketing.contact') }}" class="font-semibold text-brand hover:underline">contact form</a>.
            </p>
        </section>
    </div>
</div>
</x-layouts.marketing>
