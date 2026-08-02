<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Artisan;
use App\Models\ArtisanTestimonial;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Item;
use App\Models\NumberLease;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Stocktake;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Services\DocumentIssuer;
use App\Services\ExpenseRecorder;
use App\Services\LoyaltyLedger;
use App\Services\PaymentRecorder;
use App\Services\Stock\Stocktaker;
use App\Services\TicketSeller;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the exact dataset shown in the product design.
 *
 * Every figure on the dashboard is computed from these rows by the same queries
 * that will run in production — nothing on the screen is a hardcoded string. If a
 * number here changes, the dashboard changes with it.
 *
 * Target figures (range = today):
 *   Total Sales FCFA1,250 · Paid FCFA820 · Outstanding FCFA430 · Invoices 18
 *   Sales Overview (this week) FCFA5,760
 *   Customers 128 · Products 56 · Receipts today 24 · Expenses this month FCFA210
 */
class DemoCompanySeeder extends Seeder
{
    /**
     * Filler customers are handed out in non-overlapping blocks so no single one
     * accumulates enough revenue to displace a named account in Top Customers.
     */
    protected const POOL_TODAY = 0;

    protected const POOL_YESTERDAY = 15;

    protected const POOL_WEEK = 30;

    protected CarbonImmutable $today;

    protected Company $company;

    protected User $owner;

    /** Number counter for background invoices; starts above the design's 1–18. */
    protected int $sequence = 1000;

    protected RecordsBusinessEvents $books;

    public function run(): void
    {
        $this->today = CarbonImmutable::now()->startOfDay();
        $this->books = app(RecordsBusinessEvents::class);

        $this->owner = User::updateOrCreate(
            ['email' => 'john@opesware.com'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'phone' => '+237 6 70 41 62 38',
                'avatar_path' => null,
                'theme' => 'system',
            ],
        );

        $this->company = Company::updateOrCreate(
            ['slug' => 'opesware-technologies'],
            [
                'name' => 'Opesware Technologies',
                'motto' => 'Business made simple',
                'description' => 'Software, branding and business systems for growing organisations.',
                'industry' => 'Technology',
                'registration_number' => 'RC-1042288',
                'tax_id' => '12345678-0001',
                'tax_id_index' => hash('sha256', '123456780001'),
                // The showcase account runs on the top plan. On Basic the nav
                // correctly hides Papers, Forms and Events — which is right for
                // a real Basic customer and wrong for the account whose whole
                // job is showing what the product does.
                'plan' => 'business',
                'tax_regime' => 'reel',
                'tax_centre' => 'CDI Douala 1er',
                'vat_registered' => true,
                'vat_rate' => 19.25,
                /*
                 * Douala, in francs. The demo used to trade from Lagos in
                 * dollars while carrying a Douala tax centre and a 19.25% TVA
                 * rate — a business that cannot exist, showing "$" against
                 * every figure on a product whose invoices, payroll and
                 * accounting are all built for Cameroon.
                 */
                'address_line1' => 'Rue Njo-Njo, Bonapriso',
                'city' => 'Douala',
                'region' => 'Littoral',
                'country' => 'CM',
                'latitude' => 4.0383,
                'longitude' => 9.7085,
                'website' => 'https://opesware.com',
                'email' => 'hello@opesware.com',
                'phones' => ['+237 6 70 41 62 38'],
                'socials' => ['x' => 'opesware', 'linkedin' => 'opesware'],
                'currency' => 'XAF',
                'timezone' => 'Africa/Douala',
                'owner_id' => $this->owner->id,
                'loyalty_enabled' => true,
                'loyalty_points_per_amount' => 100,
                'loyalty_point_value' => 1,
            ],
        );

        // The books start with the business.
        ChartOfAccounts::seed($this->company);

        // A second demo account with a working-level role, so the login page's
        // demo buttons can show the product as staff see it — conditionally
        // rendered menus, no settings, no user management.
        $staff = User::updateOrCreate(
            ['email' => 'sales@opesware.com'],
            [
                'name' => 'Sarah Okafor',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'phone' => '+237 6 99 82 14 07',
                'avatar_path' => null,
                'theme' => 'system',
            ],
        );

        $this->company->users()->syncWithoutDetaching([
            $this->owner->id => [
                'role_id' => Role::where('slug', Role::OWNER)->value('id'),
                'job_title' => 'Business Owner',
                'status' => 'active',
                'joined_at' => now(),
            ],
            $staff->id => [
                'role_id' => Role::where('slug', Role::SALES_OFFICER)->value('id'),
                'job_title' => 'Sales Officer',
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);

        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        $staff->forceFill(['current_company_id' => $this->company->id])->save();

        // Everything below is tenant-scoped, so the seeder must act as this company.
        app(CurrentCompany::class)->set($this->company);

        $this->wipeExistingDemoData();

        $customers = $this->seedCustomers();
        $this->seedProducts();

        $lease = $this->seedNumberLease();

        $this->seedEarlierThisMonth($customers);
        $this->seedRestOfWeek();
        $this->seedYesterday();
        $this->seedToday($customers, $lease);
        $this->seedExpenses($customers);
        // After the sales, so the count sees shelves the invoices have already
        // drawn down — which is the whole reason a business counts.
        $this->seedStocktake();

        $this->seedArtisans();
        $this->seedReviews();
        $this->seedForms();
        $this->seedEvent();
        $this->seedJudeNshomeAccount();
        $this->recomputeContactBalances();
    }

    /** One open form with a few responses, so Forms is not an empty room. */
    protected function seedForms(): void
    {
        $fields = [
            ['id' => 'demo-name', 'type' => 'short_text', 'label' => 'Full name', 'help' => '', 'required' => true, 'options' => []],
            ['id' => 'demo-email', 'type' => 'email', 'label' => 'Email address', 'help' => 'We will send the joining details here.', 'required' => true, 'options' => []],
            ['id' => 'demo-session', 'type' => 'choice', 'label' => 'Which session will you attend?', 'help' => '', 'required' => true, 'options' => ['Morning (9am)', 'Afternoon (2pm)']],
            ['id' => 'demo-topics', 'type' => 'checkboxes', 'label' => 'Topics you care about', 'help' => 'Pick as many as you like.', 'required' => false, 'options' => ['Invoicing', 'Inventory', 'Offline mode', 'Verification QR']],
            ['id' => 'demo-notes', 'type' => 'long_text', 'label' => 'Anything else?', 'help' => '', 'required' => false, 'options' => []],
        ];

        $form = Form::create([
            'title' => 'Client Workshop Registration',
            'description' => 'Register for the free OPES360 client workshop. Spaces are limited.',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => $fields,
            'created_by' => $this->owner->id,
        ]);

        foreach ([
            ['Chiamaka Eze', 'chiamaka@example.com', 'Morning (9am)', ['Invoicing', 'Offline mode'], ''],
            ['Tunde Bakare', 'tunde@example.com', 'Afternoon (2pm)', ['Inventory'], 'Will there be a recording?'],
            ['Ngozi Ade', 'ngozi@example.com', 'Morning (9am)', ['Invoicing', 'Verification QR'], ''],
            // Jude Nshome (OPESWARE llc.) — a real named client also seeded
            // into the commerce side, registered here for the same workshop
            // so Forms is not the only module without his activity.
            ['Jude Nshome', 'nshomejude@gmail.com', 'Afternoon (2pm)', ['Invoicing', 'Inventory', 'Offline mode'], 'Running OPESWARE llc. out of Douala — mostly interested in offline mode for spotty connections and the invoicing workflow.'],
        ] as [$name, $email, $session, $topics, $notes]) {
            FormResponse::create([
                'form_id' => $form->id,
                'answers' => array_filter([
                    'demo-name' => $name,
                    'demo-email' => $email,
                    'demo-session' => $session,
                    'demo-topics' => $topics,
                    'demo-notes' => $notes,
                ]),
            ]);
        }
    }

    /** One published event, part-sold, one attendee already through the door. */
    protected function seedEvent(): void
    {
        $event = Event::create([
            'title' => 'Product Showcase Evening',
            'description' => "An evening walk-through of what's new, with live demos and Q&A.",
            'venue' => 'Landmark Centre, Victoria Island',
            'starts_at' => $this->today->addDays(12)->setTime(18, 0),
            'ends_at' => $this->today->addDays(12)->setTime(21, 0),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
            'created_by' => $this->owner->id,
        ]);

        $general = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General admission',
            'price' => 25,
            'quantity' => 100,
            'sort' => 0,
        ]);

        $vip = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'VIP (front row + meet the team)',
            'price' => 75,
            'quantity' => 20,
            'sort' => 1,
        ]);

        $seller = app(TicketSeller::class);

        $tickets = [
            ...$seller->sell($event, [$general->id => 2], 'Chiamaka Eze', 'chiamaka@example.com', null),
            ...$seller->sell($event, [$general->id => 1], 'Tunde Bakare', 'tunde@example.com', '+234 803 555 0111'),
            ...$seller->sell($event, [$vip->id => 1], 'Ngozi Ade', 'ngozi@example.com', null),
        ];

        // Jude Nshome (OPESWARE llc.) buys a VIP ticket through the real
        // TicketSeller, exactly the way the public /e/{token} page would —
        // his own row, his own price snapshot, his own verification token.
        $judeTicket = $seller->sell(
            $event,
            [$vip->id => 1],
            'Jude Nshome',
            'nshomejude@gmail.com',
            '+237670416238',
        )[0];

        // Most have paid; one VIP is through the door already so the check-in
        // counter on the event page has something to say.
        foreach ($tickets as $index => $ticket) {
            if ($index < 3) {
                $ticket->forceFill(['paid_at' => now()])->save();
            }
        }

        end($tickets)->forceFill([
            'status' => 'checked_in',
            'checked_in_at' => now()->subDay(),
            'checked_in_by' => $this->owner->id,
            'paid_at' => now()->subDays(2),
        ])->save();

        // Jude's ticket is paid but not yet checked in — he is coming to the
        // showcase, the verification page just hasn't seen him at the door.
        $judeTicket->forceFill(['paid_at' => now()->subHours(3)])->save();
    }

    /**
     * The same named client as seedForms()/seedEvent()/seedReviews() above,
     * now with an actual account: a contact, an issued annual invoice, a
     * full payment, and the receipt that payment produces. Every commerce
     * module (contacts, sales documents, payments, receipts) touches this
     * same record instead of a generic placeholder, so the product's own
     * demo can be pointed at as a working example end to end.
     */
    protected function seedJudeNshomeAccount(): void
    {
        // Dated a couple of months back: a real client outside the crafted
        // today/week/month figures the rest of this seeder targets, so it adds
        // a genuine account without shifting any of those numbers out from
        // under the dashboard tests.
        $signedUp = $this->today->subMonths(2);

        $contact = Contact::create([
            'type' => 'customer',
            'name' => 'Jude Nshome',
            'company_name' => 'OPESWARE llc.',
            'email' => 'nshomejude@gmail.com',
            'phones' => ['+237670416238'],
            'whatsapp' => '+237670416238',
            'address' => [
                'street' => 'Rue Tokoto, Bonapriso',
                'city' => 'Douala',
                'country' => 'Cameroon',
            ],
            'notes' => 'Company website: https://opesware.com',
            'created_at' => $signedUp,
            'updated_at' => $signedUp,
        ]);

        $invoice = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => $signedUp->toDateString(),
            'due_date' => $signedUp->addDays(14)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 30000,
            'total' => 30000,
            'amount_paid' => 0,
            'balance' => 30000,
            'notes' => 'Annual subscription — OPES 360 (Opes Business 360).',
            'created_by' => $this->owner->id,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'description' => 'OPES 360 (Opes Business 360) — Annual Subscription',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => 30000,
            'line_total' => 30000,
            'sort_order' => 0,
        ]);

        // Real issuing flow: leases the final INV- number, freezes the content
        // hash and mints the public verification token — the same path the
        // Documents UI uses, not a hand-rolled shortcut.
        $invoice = app(DocumentIssuer::class)->issue($invoice, $this->owner);

        // Full payment through the real recorder, so the invoice is marked
        // Paid, the contact balance is decremented, and a real numbered,
        // hashed, verifiable receipt is issued alongside it.
        /*
         * Paid when the invoice was raised, not when the seeder ran. Recording
         * it as "now" put 30 000 into today's takings and moved every delta on
         * the dashboard — which went unnoticed only while the demo traded in
         * dollars and this figure was in francs, so the totals dropped it as a
         * foreign currency. A bug hidden behind another bug.
         */
        app(PaymentRecorder::class)->record(
            document: $invoice,
            cashier: $this->owner,
            amount: 30000,
            method: PaymentMethod::MobileMoney,
            reference: 'Annual plan — OPES 360 subscription',
            receiptFormat: 'a4',
            receivedAt: $signedUp,
        );

        // Points were already earned automatically by the payment above
        // (PaymentRecorder calls LoyaltyLedger::earn); a real client with an
        // account naturally has a physical card too.
        app(LoyaltyLedger::class)->issueCard($contact->fresh());
    }

    /** Three published reviews on the public business profile. */
    protected function seedReviews(): void
    {
        $reviews = [
            ['Adaeze Okafor', 5, 'They set up our invoicing and receipts in one afternoon. Support on WhatsApp actually replies.', 12],
            ['Ibrahim Musa', 4, 'Solid work on our branding and stationery. Delivery slipped by a day but the quality made up for it.', 7],
            ['Funke Adeyemi', 5, 'Professional team. The verified profile page has already brought us two new corporate clients.', 2],
            // Jude Nshome (OPESWARE llc., opesware.com) — a real named client,
            // also seeded with a paid invoice and a ticket, leaving a review
            // in his own name.
            ['Jude Nshome', 5, 'OPES360 runs the day-to-day at OPESWARE llc. now — invoicing, receipts, the works. Set up took an afternoon and our clients in Douala get a verified invoice they can check themselves. Exactly what a small outfit like ours needed.', 1],
        ];

        foreach ($reviews as [$author, $rating, $body, $daysAgo]) {
            CompanyReview::create([
                'author_name' => $author,
                'rating' => $rating,
                'body' => $body,
                'is_published' => true,
                'created_at' => $this->today->subDays($daysAgo),
                'updated_at' => $this->today->subDays($daysAgo),
            ]);
        }
    }

    /** Two artisans with public, verified profiles (Module 5). */
    protected function seedArtisans(): void
    {
        $people = [
            [
                'full_name' => 'Emeka Nwosu',
                'slug' => 'emeka-nwosu',
                'occupation' => 'Master Electrician',
                'trade_category' => 'Construction',
                'skills' => ['Wiring', 'Solar installation', 'Fault diagnosis'],
                'years' => 12,
                'bio' => 'Twelve years wiring homes and small factories across Lagos. Solar-certified since 2021.',
                'phone' => '+234 803 555 0101',
                'testimonial' => ['Chika A.', 5, 'Rewired our whole shop in two days. Neat work, fair price.'],
            ],
            [
                'full_name' => 'Amina Bello',
                'slug' => 'amina-bello',
                'occupation' => 'Carpenter & Joiner',
                'trade_category' => 'Furniture',
                'skills' => ['Cabinetry', 'Fitted wardrobes', 'Restoration'],
                'years' => 8,
                'bio' => 'Bespoke cabinetry and fitted furniture, built to measure in a Lagos workshop.',
                'phone' => '+234 809 555 0202',
                'testimonial' => ['Tunde O.', 5, 'The wardrobe fits perfectly and the finish is excellent.'],
            ],
        ];

        foreach ($people as $person) {
            $artisan = Artisan::create([
                'slug' => $person['slug'],
                'artisan_number' => 'ART-'.Str::upper(Str::random(6)),
                'full_name' => $person['full_name'],
                'occupation' => $person['occupation'],
                'trade_category' => $person['trade_category'],
                'skills' => $person['skills'],
                'biography' => $person['bio'],
                'years_experience' => $person['years'],
                'phones' => [$person['phone']],
                'whatsapp' => $person['phone'],
                'email' => Str::slug($person['full_name']).'@opesware.com',
                'address' => ['city' => 'Lagos'],
                'coverage_area' => ['Lagos', 'Ikeja', 'Lekki'],
                'languages' => ['English', 'Yoruba'],
                'is_published' => true,
            ]);

            $token = VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => Artisan::class,
                'subject_id' => $artisan->id,
            ]);

            $artisan->forceFill([
                'verification_token_id' => $token->id,
                'is_verified' => true,
            ])->save();

            [$author, $rating, $body] = $person['testimonial'];

            ArtisanTestimonial::create([
                'artisan_id' => $artisan->id,
                'author_name' => $author,
                'rating' => $rating,
                'body' => $body,
                'is_published' => true,
            ]);
        }
    }

    /**
     * contacts.balance is a cached rollup of outstanding document balances; the
     * customer list sorts and displays it, so it must reflect the seeded rows.
     */
    protected function recomputeContactBalances(): void
    {
        $owed = Document::query()
            ->invoices()
            ->outstanding()
            ->selectRaw('contact_id, SUM(balance) as owed')
            ->groupBy('contact_id')
            ->pluck('owed', 'contact_id');

        foreach ($owed as $contactId => $balance) {
            Contact::query()->whereKey($contactId)->update(['balance' => $balance]);
        }
    }

    /** @return Collection<int, Contact> */
    protected function fillerPool(int $offset, int $count)
    {
        return Contact::query()
            ->customers()
            ->where('name', 'like', 'Customer %')
            ->orderBy('name')
            ->skip($offset)
            ->take($count)
            ->get();
    }

    /** Keeps re-running the seeder idempotent without touching other tenants. */
    protected function wipeExistingDemoData(): void
    {
        FormResponse::query()->delete();
        Form::query()->forceDelete();
        Ticket::query()->delete();
        TicketType::query()->delete();
        Event::query()->forceDelete();
        CompanyReview::query()->delete();
        ArtisanTestimonial::query()->delete();
        Artisan::query()->forceDelete();
        PaymentAllocation::query()->delete();
        Receipt::query()->delete();
        Payment::query()->forceDelete();
        DocumentLine::query()->delete();
        Document::query()->forceDelete();
        NumberLease::query()->delete();
        // Before the items: a count sheet points at products, and its lines
        // go with it.
        Stocktake::query()->delete();
        Item::query()->forceDelete();
        Contact::query()->forceDelete();
    }

    /**
     * 128 customers, of which 5 were created this month (the "5 this month"
     * caption), plus the four named accounts from the design.
     *
     * @return array<string, Contact>
     */
    protected function seedCustomers(): array
    {
        $named = [];

        foreach (['Tech Core Ltd', 'Alpha Builders', 'Mega Shop', 'Daily Needs'] as $index => $name) {
            $named[$name] = $this->makeContact($name, $this->today->subMonths(4)->addDays($index));
        }

        // 5 signed up this month; the remaining 119 predate it.
        for ($i = 0; $i < 5; $i++) {
            $this->makeContact('New Client '.($i + 1), $this->today->startOfMonth()->addDays($i + 1));
        }

        for ($i = 0; $i < 119; $i++) {
            $this->makeContact('Customer '.Str::padLeft((string) ($i + 1), 3, '0'), $this->today->subMonths(3));
        }

        // Suppliers sit outside the 128 customer count.
        $named['supplier'] = $this->makeContact('Prime Supplies Ltd', $this->today->subMonths(5), 'supplier');

        return $named;
    }

    protected function makeContact(string $name, CarbonImmutable $createdAt, string $type = 'customer'): Contact
    {
        return Contact::create([
            'type' => $type,
            'name' => $name,
            'company_name' => $name,
            'email' => Str::slug($name).'@example.com',
            'phones' => ['+234 '.random_int(700, 909).' '.random_int(1000000, 9999999)],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** 56 active products, a handful tracked and in stock. */
    protected function seedProducts(): void
    {
        for ($i = 1; $i <= 56; $i++) {
            Item::create([
                'type' => 'product',
                'sku' => 'SKU-'.Str::padLeft((string) $i, 4, '0'),
                'name' => 'Product '.$i,
                'unit' => 'unit',
                'price' => 10 * $i,
                'cost' => 6 * $i,
                'track_stock' => $i <= 10,
                'reorder_level' => $i <= 10 ? 5 : null,
                'is_active' => true,
            ]);
        }

        // Opening stock keeps the tracked products above their reorder level, so
        // the Products tile reads "In stock" rather than a low-stock warning.
        Item::query()->where('track_stock', true)->get()->each(function (Item $item) {
            $item->movements()->create([
                'company_id' => $item->company_id,
                'quantity' => 50,
                // What it cost is what makes the stock worth anything on the
                // balance sheet. A demo whose inventory values at zero shows
                // the feature working and demonstrates nothing.
                'unit_cost' => (float) $item->cost,
                'reason' => 'opening',
                'occurred_at' => $this->today->subMonth(),
                'created_at' => $this->today->subMonth(),
            ]);
        });
    }

    /**
     * Last month's inventory, counted and posted.
     *
     * Without it the demo's balance sheet carries no stock and its income
     * statement charges every delivery to the month it arrived — which is the
     * state the software is there to get a business out of, so showing it is
     * showing the wrong thing.
     */
    protected function seedStocktake(): void
    {
        $company = $this->company->refresh();
        $taker = app(Stocktaker::class);

        $stocktake = $taker->start($company, null, $this->today->copy()->subDays(3)->toDateString(), $this->owner);

        if ($stocktake->lines->isEmpty()) {
            return;
        }

        // Counted honestly: most shelves agree, a couple are short. A demo in
        // which every count is exact teaches nobody what the variance column
        // is for.
        $counts = [];

        foreach ($stocktake->lines->values() as $index => $line) {
            $counts[$line->item_id] = max(0, (float) $line->book_quantity - ($index % 4 === 0 ? 2 : 0));
        }

        $taker->save($stocktake, $counts, $this->owner);
        $taker->post($stocktake, $this->owner);
    }

    protected function seedNumberLease(): NumberLease
    {
        return NumberLease::create([
            'document_type' => DocumentType::Invoice->value,
            'year' => (int) $this->today->format('Y'),
            'range_start' => 1,
            'range_end' => 200,
            'next_available' => 19,
            'status' => 'active',
            'issued_at' => $this->today->subDays(7),
            'expires_at' => $this->today->addDays(23),
        ]);
    }

    /**
     * Revenue from earlier in the month, before the current week. Drives the Top
     * Customers panel; all of it is settled so it never reaches Outstanding.
     *
     * Month totals: Tech Core 1,250 · Alpha 980 · Mega Shop 760 · Daily Needs 450.
     * Today contributes 250 / 340 / 180 / 0, so the earlier balance is below.
     *
     * @param  array<string, Contact>  $customers
     */
    protected function seedEarlierThisMonth(array $customers): void
    {
        $earlier = [
            'Tech Core Ltd' => 1000,
            'Alpha Builders' => 640,
            'Mega Shop' => 580,
            'Daily Needs' => 450,
        ];

        $day = $this->today->startOfMonth()->addDays(5);

        foreach ($earlier as $name => $amount) {
            // Dated before the current week so these never enter the weekly chart.
            $date = min($day, $this->today->startOfWeek()->subDay());

            $invoice = $this->makeInvoice(
                contact: $customers[$name],
                total: $amount,
                date: $date,
                status: DocumentStatus::Paid,
            );

            $this->settle($invoice, $date, receipt: false);

            $day = $day->addDay();
        }
    }

    /**
     * Fills the rest of the current week so the Sales Overview totals $5,760.
     * Today supplies 1,250; these six days supply the remaining 4,510, with
     * Sunday the strongest day — the bar the design highlights.
     *
     * Each day is split across several filler customers so that no filler ever
     * accumulates more than Daily Needs' 450, which would push it into the Top
     * Customers panel and displace one of the four named accounts.
     */
    protected function seedRestOfWeek(): void
    {
        // Six day-groups summing to 4,510; with today's 1,250 the week is always
        // exactly 5,760 whichever weekday the seeder runs on.
        $dayGroups = [
            [280, 280],
            [280, 280],
            [260, 260, 260],
            [210, 210],
            [250, 250, 250],
            [240, 240, 240, 240, 240, 240],
        ];

        // seedToday() owns today, so the groups go to the other six days of the
        // week in order. On a Monday run this reproduces the design's Tue–Sun
        // shape with Sunday the peak.
        $days = collect(range(0, 6))
            ->map(fn (int $offset) => $this->today->startOfWeek()->addDays($offset))
            ->reject(fn (CarbonImmutable $date) => $date->isSameDay($this->today))
            ->values();

        $fillers = $this->fillerPool(self::POOL_WEEK, 18);
        $cursor = 0;

        foreach ($days as $index => $date) {
            foreach ($dayGroups[$index] as $amount) {
                $invoice = $this->makeInvoice(
                    contact: $fillers[$cursor++ % $fillers->count()],
                    total: $amount,
                    date: $date,
                    status: DocumentStatus::Paid,
                );

                $this->settle($invoice, $date, receipt: false);
            }
        }
    }

    /**
     * Yesterday, sized so the stat cards' comparisons land on the design's
     * figures: sales +12%, paid +8%, invoice count +3.
     *
     * Yesterday's invoices are fully settled, but only $759.26 of that lands on
     * the day itself — the remainder is collected two days later. Without that
     * split, the unpaid balance would leak into the Outstanding card, which has
     * to stay at exactly $430.
     */
    protected function seedYesterday(): void
    {
        $yesterday = $this->today->subDay();
        $fillers = $this->fillerPool(self::POOL_YESTERDAY, 15);

        // 14 × 74.00 + 80.07 = 1,116.07, which is 1,250 ÷ 1.12.
        // Settled 14 × 50.00 + 59.26 = 759.26, which is 820 ÷ 1.08.
        for ($i = 0; $i < 15; $i++) {
            $isLast = $i === 14;
            $total = $isLast ? 80.07 : 74.00;
            $onTheDay = $isLast ? 59.26 : 50.00;

            $invoice = $this->makeInvoice(
                contact: $fillers[$i % $fillers->count()],
                total: $total,
                date: $yesterday,
                status: DocumentStatus::Paid,
            );

            $this->pay($invoice, $onTheDay, $yesterday, receipt: false);
            $this->pay($invoice, round($total - $onTheDay, 2), $this->today->addDay(), receipt: false);
        }
    }

    /**
     * Today: 18 invoices totalling 1,250, of which 820 is settled by 24 payments
     * (several taken as part-payments, which is the normal case in this market),
     * leaving 430 outstanding. Each of those payments issues a receipt.
     *
     * @param  array<string, Contact>  $customers
     */
    protected function seedToday(array $customers, NumberLease $lease): void
    {
        $fillers = $this->fillerPool(self::POOL_TODAY, 15);

        // 15 smaller invoices summing to 480; the three from the design add 770.
        $smallAmounts = [20, 25, 30, 35, 40, 45, 50, 15, 18, 22, 28, 32, 38, 42, 40];

        // 20 + 25 + 45 stay unpaid, contributing 90 to Outstanding alongside
        // Alpha Builders' 340, for the 430 the design shows.
        $unpaidSmall = [0, 1, 5];

        $settled = [];

        foreach ($smallAmounts as $index => $amount) {
            $invoice = $this->makeInvoice(
                contact: $fillers[$index % $fillers->count()],
                total: $amount,
                date: $this->today,
                status: in_array($index, $unpaidSmall, true) ? DocumentStatus::Sent : DocumentStatus::Paid,
                number: 'INV-'.$this->today->format('Y').'-'.Str::padLeft((string) ($index + 1), 5, '0'),
                lease: $lease,
            );

            if (! in_array($index, $unpaidSmall, true)) {
                $settled[] = $invoice;
            }
        }

        // The three rows shown in Recent Invoices, newest last so 00018 is latest.
        $headline = [
            ['number' => 16, 'contact' => 'Mega Shop', 'total' => 180, 'status' => DocumentStatus::Paid],
            ['number' => 17, 'contact' => 'Alpha Builders', 'total' => 340, 'status' => DocumentStatus::Sent],
            ['number' => 18, 'contact' => 'Tech Core Ltd', 'total' => 250, 'status' => DocumentStatus::Paid],
        ];

        foreach ($headline as $row) {
            $invoice = $this->makeInvoice(
                contact: $customers[$row['contact']],
                total: $row['total'],
                date: $this->today,
                status: $row['status'],
                number: 'INV-'.$this->today->format('Y').'-'.Str::padLeft((string) $row['number'], 5, '0'),
                lease: $lease,
            );

            if ($row['status'] === DocumentStatus::Paid) {
                $settled[] = $invoice;
            }
        }

        // 14 settled invoices; splitting 10 of them into two instalments each
        // yields the 24 receipts issued today without changing the 820 total.
        foreach ($settled as $index => $invoice) {
            $this->settle($invoice, $this->today, receipt: true, instalments: $index < 10 ? 2 : 1);
        }
    }

    /** Supplier payments this month, totalling the $210.00 Expenses tile. */
    protected function seedExpenses(array $customers): void
    {
        $supplier = $customers['supplier'];
        $date = $this->today->startOfMonth()->addDays(3);
        $rate = (float) $this->company->vat_rate / 100;

        /*
         * Real expenses, through the recorder, so they reach the books and the
         * Expenses screen rather than being three bare payments against a
         * supplier — which is what they were, from before the expenses module
         * existed. Each one carries recoverable TVA, so the demo's declaration
         * screen has both halves of a TVA return rather than only the sales.
         *
         * The HT figures are worked back from the round totals the design
         * pins, which is also how a real receipt reads: 120 000 at the counter,
         * an odd number before tax.
         */
        $spending = [
            ['Carburant véhicule de livraison', 'fuel', 120],
            ['Facture ENEO', 'electricity', 60],
            ['Crédit téléphone et internet', 'telecoms', 30],
        ];

        foreach ($spending as $offset => [$description, $category, $gross]) {
            app(ExpenseRecorder::class)->record([
                'supplier_id' => $supplier->id,
                'description' => $description,
                'category' => $category,
                'issue_date' => $date->addDays($offset)->toDateString(),
                'amount' => round($gross / (1 + $rate), 2),
                'vat_rate' => $rate,
                'payment_method' => 'bank',
            ], $this->owner);
        }
    }

    protected function makeInvoice(
        Contact $contact,
        float $total,
        CarbonImmutable $date,
        DocumentStatus $status,
        ?string $number = null,
        ?NumberLease $lease = null,
    ): Document {
        // Background invoices take numbers from a high block so they never collide
        // with the 1–18 sequence the design's own invoices occupy.
        $number ??= 'INV-'.$date->format('Y').'-'.Str::padLeft((string) (++$this->sequence), 5, '0');

        $paid = $status === DocumentStatus::Paid ? $total : 0.0;

        $invoice = Document::create([
            'type' => DocumentType::Invoice,
            'number' => $number,
            'number_lease_id' => $lease?->id,
            'contact_id' => $contact->id,
            'status' => $status,
            'issue_date' => $date->toDateString(),
            'due_date' => $date->addDays(14)->toDateString(),
            'currency' => $this->company->currency,
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => $paid,
            'balance' => $total - $paid,
            'issued_at' => $date->setTime(9, 30),
            'issued_by' => $this->owner->id,
            'created_by' => $this->owner->id,
            'created_at' => $date->setTime(9, 30),
            'updated_at' => $date->setTime(9, 30),
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'description' => 'Professional services',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        // Refresh before hashing: a just-created model still holds raw PHP values
        // (250), while verification recomputes from database-cast ones ("250.00").
        // Hashing the raw form would flag every document as tampered.
        $invoice->refresh()->load('lines');

        // Frozen at issue — the hash the verification page compares against.
        $invoice->forceFill(['content_hash' => hash('sha256', $invoice->canonicalPayload())])->saveQuietly();

        $invoice->forceFill([
            'verification_token_id' => VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => Document::class,
                'subject_id' => $invoice->id,
            ])->id,
        ])->saveQuietly();

        /*
         * And into the books, which is the part this used to skip.
         *
         * These invoices are built rather than issued — the seeder backdates
         * issued_at and created_at to hit the exact figures the design pins,
         * and DocumentIssuer quite rightly stamps `now()`. The consequence went
         * unnoticed: fifty-six issued invoices and three journal entries, so
         * the demo's Accounting screen, its financial statements and its tax
         * declarations were all but empty on the account whose whole job is
         * showing what the product does. Posting here uses the document's own
         * issue_date, so March's sale lands in March.
         */
        $this->books->recordQuietly(
            fn () => $this->books->recordIssuedDocument($invoice, $this->company, $this->owner)
        );

        return $invoice;
    }

    /** Settles an invoice in full, optionally split into instalments. */
    protected function settle(
        Document $invoice,
        CarbonImmutable $date,
        bool $receipt,
        int $instalments = 1,
    ): void {
        $total = (float) $invoice->total;

        // Split so the parts always sum back to the invoice total exactly.
        $first = round($total / $instalments, 2);
        $amounts = $instalments === 1
            ? [$total]
            : [$first, round($total - $first, 2)];

        foreach ($amounts as $index => $amount) {
            $this->pay($invoice, $amount, $date, $receipt, $index);
        }
    }

    /** Records a single payment of a given amount against an invoice. */
    protected function pay(
        Document $invoice,
        float $amount,
        CarbonImmutable $date,
        bool $receipt,
        int $sequence = 0,
    ): void {
        $payment = Payment::create([
            'contact_id' => $invoice->contact_id,
            'method' => $sequence === 0 ? 'cash' : 'mobile_money',
            'amount' => $amount,
            'currency' => $invoice->currency,
            'received_at' => $date->setTime(10, 15)->addMinutes($sequence * 20),
            'received_by' => $this->owner->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => $amount,
        ]);

        // The settlement too: money off the customer's account and into the
        // till, on the day it actually arrived.
        $this->books->recordQuietly(
            fn () => $this->books->recordPayment($payment, $this->company, $this->owner)
        );

        if ($receipt) {
            Receipt::create([
                'payment_id' => $payment->id,
                'contact_id' => $invoice->contact_id,
                'number' => 'RCP-'.$date->format('Y').'-'.Str::upper(Str::random(6)),
                'format' => 'thermal80',
                'total' => $amount,
                'currency' => $invoice->currency,
                'status' => 'issued',
                'issued_at' => $payment->received_at,
                'cashier_id' => $this->owner->id,
            ]);
        }
    }
}
