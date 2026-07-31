<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
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
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\TicketSeller;
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
 *   Total Sales $1,250.00 · Paid $820.00 · Outstanding $430.00 · Invoices 18
 *   Sales Overview (this week) $5,760.00
 *   Customers 128 · Products 56 · Receipts today 24 · Expenses this month $210.00
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

    public function run(): void
    {
        $this->today = CarbonImmutable::now()->startOfDay();

        $this->owner = User::updateOrCreate(
            ['email' => 'john@opesware.com'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'phone' => '+234 801 234 5678',
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
                'address_line1' => '14 Adeola Odeku Street',
                'city' => 'Lagos',
                'region' => 'Lagos',
                'country' => 'NG',
                'latitude' => 6.4281,
                'longitude' => 3.4219,
                'website' => 'https://opesware.com',
                'email' => 'hello@opesware.com',
                'phones' => ['+234 801 234 5678'],
                'socials' => ['x' => 'opesware', 'linkedin' => 'opesware'],
                'currency' => 'USD',
                'timezone' => 'UTC',
                'owner_id' => $this->owner->id,
            ],
        );

        // A second demo account with a working-level role, so the login page's
        // demo buttons can show the product as staff see it — conditionally
        // rendered menus, no settings, no user management.
        $staff = User::updateOrCreate(
            ['email' => 'sales@opesware.com'],
            [
                'name' => 'Sarah Okafor',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'phone' => '+234 802 345 6789',
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

        $this->seedArtisans();
        $this->seedReviews();
        $this->seedForms();
        $this->seedEvent();
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
    }

    /** Three published reviews on the public business profile. */
    protected function seedReviews(): void
    {
        $reviews = [
            ['Adaeze Okafor', 5, 'They set up our invoicing and receipts in one afternoon. Support on WhatsApp actually replies.', 12],
            ['Ibrahim Musa', 4, 'Solid work on our branding and stationery. Delivery slipped by a day but the quality made up for it.', 7],
            ['Funke Adeyemi', 5, 'Professional team. The verified profile page has already brought us two new corporate clients.', 2],
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
                'reason' => 'opening',
                'occurred_at' => $this->today->subMonth(),
                'created_at' => $this->today->subMonth(),
            ]);
        });
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

        foreach ([120, 60, 30] as $offset => $amount) {
            Payment::create([
                'contact_id' => $supplier->id,
                'method' => 'bank_transfer',
                'amount' => $amount,
                'currency' => $this->company->currency,
                'reference' => 'EXP-'.($offset + 1),
                'received_at' => $date->addDays($offset),
                'received_by' => $this->owner->id,
            ]);
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
