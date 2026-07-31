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
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-bar', 'route' => 'reports', 'ability' => 'reports.view'],
        ['key' => 'payments', 'label' => 'Payments', 'icon' => 'credit-card', 'route' => 'payments', 'ability' => 'payments.view'],
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

    'currencies' => ['USD', 'EUR', 'GBP', 'NGN', 'GHS', 'KES', 'ZAR', 'XOF', 'XAF'],

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

    'quick_actions' => [
        ['label' => 'New Invoice', 'icon' => 'document-plus', 'accent' => 'blue', 'route' => 'documents.create', 'params' => ['type' => 'invoice'], 'ability' => 'sales.create'],
        ['label' => 'New Quotation', 'icon' => 'document', 'accent' => 'green', 'route' => 'documents.create', 'params' => ['type' => 'quotation'], 'ability' => 'sales.create'],
        ['label' => 'New Proforma', 'icon' => 'document', 'accent' => 'teal', 'route' => 'documents.create', 'params' => ['type' => 'proforma'], 'ability' => 'sales.create'],
        ['label' => 'New Receipt', 'icon' => 'printer', 'accent' => 'orange', 'route' => 'payments', 'ability' => 'receipts.create'],
        ['label' => 'Add Customer', 'icon' => 'user-plus', 'accent' => 'purple', 'route' => 'customers.create', 'ability' => 'customers.create'],
        ['label' => 'Add Product', 'icon' => 'cube', 'accent' => 'blue', 'route' => 'products.create', 'ability' => 'products.create'],
        ['label' => 'Record Payment', 'icon' => 'banknotes', 'accent' => 'teal', 'route' => 'sales', 'params' => ['state' => 'pending'], 'ability' => 'payments.record'],
        ['label' => 'New Contract', 'icon' => 'document', 'accent' => 'slate', 'route' => 'papers', 'ability' => 'papers.create'],
        ['label' => 'New Form', 'icon' => 'clipboard', 'accent' => 'purple', 'route' => 'forms', 'ability' => 'forms.create'],
        ['label' => 'New Event', 'icon' => 'ticket', 'accent' => 'orange', 'route' => 'events.create', 'ability' => 'events.create'],
        ['label' => 'Scan QR', 'icon' => 'qr-code', 'accent' => 'pink', 'route' => 'scan'],
        ['label' => 'More', 'icon' => 'ellipsis', 'accent' => 'slate', 'route' => 'help'],
    ],
];
