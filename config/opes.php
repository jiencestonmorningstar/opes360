<?php

/*
 * Navigation and quick actions live in config so the desktop sidebar, the mobile
 * bottom bar and the "See All" sheet all render from one list. Adding a module in
 * a later phase means adding one entry here, not editing three templates.
 */
return [

    'brand' => [
        'name' => 'Opes360',
        // The wordmark is two-toned: "Opes" in ink, "360" in brand blue.
        'name_prefix' => 'Opes',
        'name_suffix' => '360',
        'tagline' => 'Business made simple',
        'vendor' => 'Opesware Technologies',
        'vendor_url' => 'https://opesware.com',
    ],

    /*
     * One-tap demo sign-ins on the login page. These are ordinary POSTs of the
     * seeded demo credentials through the real login flow — same throttling,
     * same session handling, no separate auth path — so switching them off is
     * purely cosmetic. Off is the right setting the moment real businesses
     * hold data in this install.
     */
    'demo' => [
        'enabled' => env('OPES_DEMO_LOGINS', true),
        'password' => 'password',
        'accounts' => [
            ['label' => 'Business Owner', 'detail' => 'Full access to everything', 'email' => 'john@opesware.com'],
            ['label' => 'Sales Officer', 'detail' => 'Day-to-day sales and customers', 'email' => 'sales@opesware.com'],
            /*
             * The secretariat. DemoSecretariatSeeder has always built this
             * account in full — a client book, issued cards, earnings, four
             * employees and a paid payroll run — and the login page never
             * offered it, so the only way in was knowing the address by heart.
             * A demo nobody can reach is a demo that does not exist.
             *
             * "Partner" and "secretariat" are the same thing in this product:
             * the partner programme is what a secretariat account gets, which
             * is why there is one entry here and not two.
             */
            ['label' => 'Secretariat', 'detail' => 'Client book, cards and earnings', 'email' => 'secretariat@opesware.com'],
        ],
        // Seeded by PlatformAdminSeeder — shares the flag and password above so
        // turning demo logins off hides this one-tap sign-in too.
        'admin_email' => 'admin@opes360.com',
    ],

    /*
     * Full navigation. `primary` marks the five entries that appear in the mobile
     * bottom bar; everything else is reachable from the sidebar on desktop and the
     * "More" sheet on mobile.
     *
     * `ability` is the gate that must pass for the entry to be rendered at all —
     * the same one the route enforces, so the nav can never offer a link that
     * answers 403. Entries with no `ability` are open to every member.
     */
    'navigation' => [
        ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'dashboard', 'primary' => true],
        ['key' => 'sales', 'label' => 'Sales', 'icon' => 'sales', 'route' => 'sales', 'primary' => true, 'ability' => 'sales.view'],
        ['key' => 'customers', 'label' => 'Customers', 'icon' => 'user', 'route' => 'customers', 'primary' => true, 'ability' => 'customers.view'],
        ['key' => 'business', 'label' => 'Business', 'icon' => 'briefcase', 'route' => 'business', 'primary' => true, 'ability' => 'business.view'],
        ['key' => 'products', 'label' => 'Products', 'icon' => 'cube', 'route' => 'products', 'ability' => 'products.view'],
        ['key' => 'papers', 'label' => 'Documents', 'icon' => 'document', 'route' => 'papers', 'ability' => 'papers.view'],
        ['key' => 'forms', 'label' => 'Forms', 'icon' => 'clipboard', 'route' => 'forms', 'ability' => 'forms.view'],
        ['key' => 'events', 'label' => 'Events', 'icon' => 'ticket', 'route' => 'events', 'ability' => 'events.view'],
        // Secretariat only. The ability itself carries that condition (see
        // AuthServiceProvider), so no extra flag is needed here — a plain
        // business simply never renders these two.
        ['key' => 'partners', 'label' => 'Clients', 'icon' => 'printer', 'route' => 'partners.clients', 'ability' => 'partners.view'],
        ['key' => 'partner-earnings', 'label' => 'Earnings', 'icon' => 'banknotes', 'route' => 'partners.earnings', 'ability' => 'partners.view'],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-bar', 'route' => 'reports', 'ability' => 'reports.view'],
        ['key' => 'accounting', 'label' => 'Accounting', 'icon' => 'wallet', 'route' => 'accounting', 'ability' => 'accounting.view'],
        ['key' => 'payments', 'label' => 'Payments', 'icon' => 'credit-card', 'route' => 'payments', 'ability' => 'payments.view'],
        ['key' => 'assets', 'label' => 'Assets', 'icon' => 'briefcase', 'route' => 'assets', 'ability' => 'assets.view'],
        ['key' => 'banking', 'label' => 'Banking', 'icon' => 'credit-card', 'route' => 'banking', 'ability' => 'banking.view'],
        ['key' => 'stock', 'label' => 'Stock value', 'icon' => 'cube', 'route' => 'products.stock', 'ability' => 'products.view'],
        ['key' => 'locations', 'label' => 'Stock locations', 'icon' => 'cube', 'route' => 'products.locations', 'ability' => 'products.manage-locations'],
        ['key' => 'expenses', 'label' => 'Expenses', 'icon' => 'banknotes', 'route' => 'expenses', 'ability' => 'expenses.view'],
        ['key' => 'team', 'label' => 'Team', 'icon' => 'users', 'route' => 'team', 'ability' => 'employees.view'],
        ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'wallet', 'route' => 'payroll', 'ability' => 'payroll.view'],
        ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar', 'route' => 'calendar', 'ability' => 'sales.view'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'cog', 'route' => 'settings'],
        ['key' => 'help', 'label' => 'Help & Support', 'icon' => 'help', 'route' => 'help'],
    ],

    'industries' => [
        'Technology', 'Healthcare', 'Construction', 'Retail', 'Agriculture',
        'Hospitality', 'Education', 'Fashion', 'Manufacturing', 'Consulting',
        'Transport & Logistics', 'Beauty & Wellness', 'Food & Beverage',
        'Professional Services', 'Other',
    ],

    // CFA-zone first: these are the currencies this product's businesses
    // actually trade in, and a select is read from the top.
    'currencies' => ['XAF', 'XOF', 'NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'],

    /*
     * Where the public contact form's messages go. Defaults to the mail
     * "from" address so a fresh install works without extra configuration.
     */
    'contact' => [
        'recipient' => env('CONTACT_RECIPIENT', env('MAIL_FROM_ADDRESS', 'support@opes360.com')),
        'support_email' => '360@opes360.com',
        'sales_email' => 'nshomejude@gmail.com',
        'phone' => '+237 670 41 62 38',
        'whatsapp' => '+237670416238',
        'address' => 'Petite Terrain, Bonamoussadi, Douala, Cameroon',
    ],

    /*
     * The secretariat / print-shop partner programme.
     *
     * These are commercial terms, not preferences, so they live in one place:
     * the marketing page that advertises them, the ledger that charges the card
     * fee and the biller that credits the commission all read from here. A rate
     * quoted on the website and a rate applied to a payment drifting apart is
     * the kind of bug nobody notices until a partner does the arithmetic.
     */
    'partners' => [
        // Charged to the partner each time they generate a card for a client.
        'card_fee' => 500,

        // Share of every successful subscription payment made by a business the
        // partner enrolled. Recurring, for as long as that business keeps paying.
        'commission_rate' => 0.10,

        // Nothing is paid out below this; it is not worth a mobile-money fee.
        'payout_minimum' => 10000,

        'currency' => 'XAF',
    ],

    'quick_actions' => [
        ['label' => 'New Invoice', 'icon' => 'document-plus', 'accent' => 'blue', 'route' => 'documents.create', 'params' => ['type' => 'invoice'], 'ability' => 'sales.create'],
        ['label' => 'New Quotation', 'icon' => 'document', 'accent' => 'green', 'route' => 'documents.create', 'params' => ['type' => 'quotation'], 'ability' => 'sales.create'],
        ['label' => 'New Proforma', 'icon' => 'document', 'accent' => 'teal', 'route' => 'documents.create', 'params' => ['type' => 'proforma'], 'ability' => 'sales.create'],
        ['label' => 'New Receipt', 'icon' => 'printer', 'accent' => 'orange', 'route' => 'payments', 'ability' => 'receipts.create'],
        ['label' => 'Add Customer', 'icon' => 'user-plus', 'accent' => 'purple', 'route' => 'customers.create', 'ability' => 'customers.create'],
        ['label' => 'Add Product', 'icon' => 'cube', 'accent' => 'blue', 'route' => 'products.create', 'ability' => 'products.create'],
        ['label' => 'Record Payment', 'icon' => 'banknotes', 'accent' => 'teal', 'route' => 'sales', 'params' => ['state' => 'pending'], 'ability' => 'payments.record'],
        ['label' => 'Record Expense', 'icon' => 'wallet', 'accent' => 'orange', 'route' => 'expenses', 'ability' => 'expenses.create'],
        ['label' => 'Run Payroll', 'icon' => 'banknotes', 'accent' => 'green', 'route' => 'payroll', 'ability' => 'payroll.run'],
        ['label' => 'Add Employee', 'icon' => 'user-plus', 'accent' => 'teal', 'route' => 'team', 'ability' => 'employees.create'],
        ['label' => 'New Contract', 'icon' => 'document', 'accent' => 'slate', 'route' => 'papers', 'ability' => 'papers.create'],
        ['label' => 'New Form', 'icon' => 'clipboard', 'accent' => 'purple', 'route' => 'forms', 'ability' => 'forms.create'],
        ['label' => 'New Event', 'icon' => 'ticket', 'accent' => 'orange', 'route' => 'events.create', 'ability' => 'events.create'],
        ['label' => 'Scan QR', 'icon' => 'qr-code', 'accent' => 'pink', 'route' => 'scan'],
        ['label' => 'More', 'icon' => 'ellipsis', 'accent' => 'slate', 'route' => 'help'],
    ],
];
