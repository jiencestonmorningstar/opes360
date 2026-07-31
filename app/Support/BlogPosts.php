<?php

namespace App\Support;

/**
 * The marketing blog's content. Static and code-shipped rather than
 * database-backed: there is no editorial team and no admin screen for it —
 * a post is written the same way a document template is, as a change to the
 * codebase, reviewed and deployed like anything else.
 */
class BlogPosts
{
    /** @return array<string, array<string, mixed>> keyed by slug, newest first */
    public static function all(): array
    {
        return [
            'offline-first-for-real-connectivity' => [
                'title' => 'Why offline-first isn\'t a nice-to-have here',
                'excerpt' => 'A sale doesn\'t wait for a signal bar. Here\'s how the offline engine actually works, and what it can and can\'t do.',
                'published_at' => '2026-06-02',
                'read_minutes' => 5,
                'body' => <<<'MD'
                Most business software is built somewhere the network is assumed. That assumption breaks
                constantly across the markets this product is built for — a shop floor with one bar of
                signal, a fibre line that drops mid-afternoon, a mobile plan that runs out on the 20th of
                the month. None of that should be the reason a sale doesn't get made.

                ## What actually happens offline

                Every invoice, receipt, customer and product record can be created with no connection at
                all. The service worker precaches the app shell, IndexedDB holds anything created or
                edited locally, and a device leases a block of document numbers in advance — so an
                invoice written on a plane lands with a real, final number the moment it syncs, not a
                placeholder that gets swapped out later.

                ## What it doesn't try to do

                It's not a promise that everything works offline forever. Reports that need a live
                aggregate, anything touching another business's data, and the first sign-in all need a
                connection. The honest line is: keep selling, keep issuing, keep recording — and let
                everything else wait for signal, same as it always did.

                ## The sync itself

                When the connection returns, the device pushes its queued writes and pulls anything it
                missed. Every write carries a client-generated id, so a sync that gets interrupted and
                retried never creates the same invoice twice — that's the whole reason the sync protocol
                is idempotent rather than "best effort."
                MD,
            ],

            'qr-verification-explained' => [
                'title' => 'What a QR code on your invoice is actually proving',
                'excerpt' => 'It\'s not decoration. Every printed document carries a code that proves it hasn\'t been altered since it was issued.',
                'published_at' => '2026-05-14',
                'read_minutes' => 4,
                'body' => <<<'MD'
                A printed invoice is just paper. Anyone can edit a PDF, change a total, or print a
                convincing fake. The QR code on every document, receipt, and ticket this platform issues
                exists to close that gap.

                ## How it works

                At the moment a document is issued, its contents are hashed and a random, unguessable
                token is minted for it — not derived from the invoice number, so nobody can find your
                other invoices by incrementing a URL. The QR encodes a link to a public verification page
                that recomputes that hash on every scan and compares it against what was frozen at issue.

                If nothing has changed, the page says so plainly: verified authentic. If a number was
                altered, it says content mismatch. If the document was voided, it says voided — never a
                blank page pretending everything is fine.

                ## Who it's for

                The person scanning is a customer, a bank, or an auditor — not a user of the platform, and
                they need no account to check. That's deliberate: verification that requires a login isn't
                verification anyone will actually use.
                MD,
            ],

            'loyalty-programs-that-dont-need-an-app' => [
                'title' => 'A loyalty program your customers don\'t need to install anything for',
                'excerpt' => 'Points that earn themselves on every payment, and a physical card with a QR instead of another app to download.',
                'published_at' => '2026-04-22',
                'read_minutes' => 3,
                'body' => <<<'MD'
                Most loyalty programs ask for the wrong thing first: download an app, create an account,
                remember a phone number at the till. All of that friction is exactly what makes a program
                fail to get used.

                ## Points that earn themselves

                Once a business turns the program on and sets a rate — how much spend earns a point, and
                what a point is worth back — every payment recorded against a customer earns points
                automatically. Nobody has to remember to scan anything to earn.

                ## A card instead of an app

                Redeeming works the other way: a printed card with a QR code. Scan it at the till, and
                whoever is holding staff permissions sees the balance and can redeem points on the spot —
                the same shape as checking a ticket at the door of an event. No new app, no new login,
                just a card in a wallet.

                ## The ledger underneath

                Every point earned, redeemed, or manually adjusted is its own row in an append-only
                ledger — never an in-place edit to a running total. If a balance is ever in question, the
                ledger can explain exactly how it got there.
                MD,
            ],

            'going-live-checklist' => [
                'title' => 'Moving from spreadsheets: a going-live checklist',
                'excerpt' => 'What to set up in what order when a business switches its invoicing and records over for real.',
                'published_at' => '2026-03-10',
                'read_minutes' => 6,
                'body' => <<<'MD'
                Switching a business's real invoicing and customer records off a spreadsheet is a bigger
                moment than it looks. Here's the order that avoids the usual snags.

                ## 1. Business profile first

                Name, address, tax details, and logo — everything else (letterhead, invoices, the public
                verification page) reads live from this record, so getting it right first means it's right
                everywhere at once.

                ## 2. Bring customers and products in

                Import or re-key the customer list and product catalogue before the first real invoice
                goes out. A document created against a customer that doesn't exist yet is the single most
                common early mistake.

                ## 3. Decide who does what

                Assign roles — Owner, Manager, Sales Officer, Cashier — before staff start using it day to
                day. A four-layer permission system only helps if the roles handed out actually match what
                each person's job needs.

                ## 4. Turn on what you'll actually use

                Loyalty, Forms, Events, and public reviews are all off or empty until switched on and
                configured. Better to enable them deliberately, one at a time, than all at once on day one.

                ## 5. Print stationery last

                Business cards and letterhead pull from the finished profile — printing them before step 1
                is settled just means reprinting them.
                MD,
            ],
        ];
    }

    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    /** Very small subset of markdown: `## heading` and blank-line paragraphs — same rule as document templates. */
    public static function toHtml(string $body): string
    {
        $blocks = preg_split('/\n\s*\n/', trim($body));
        $html = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if (str_starts_with($block, '## ')) {
                $html[] = '<h2>'.e(trim(substr($block, 3))).'</h2>';

                continue;
            }

            $paragraph = e($block);
            $paragraph = preg_replace('/\n\s+/', ' ', $paragraph);
            $html[] = '<p>'.$paragraph.'</p>';
        }

        return implode("\n", $html);
    }
}
