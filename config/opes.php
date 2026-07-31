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

    'quick_actions' => [
        ['label' => 'New Invoice', 'icon' => 'document-plus', 'accent' => 'blue', 'route' => 'documents.create', 'params' => ['type' => 'invoice'], 'ability' => 'sales.create'],
        ['label' => 'New Quotation', 'icon' => 'document', 'accent' => 'green', 'route' => 'documents.create', 'params' => ['type' => 'quotation'], 'ability' => 'sales.create'],
        ['label' => 'New Receipt', 'icon' => 'printer', 'accent' => 'orange', 'route' => 'payments', 'ability' => 'receipts.create'],
        ['label' => 'Add Customer', 'icon' => 'user-plus', 'accent' => 'purple', 'route' => 'customers.create', 'ability' => 'customers.create'],
        ['label' => 'Add Product', 'icon' => 'cube', 'accent' => 'blue', 'route' => 'products.create', 'ability' => 'products.create'],
        ['label' => 'Record Payment', 'icon' => 'banknotes', 'accent' => 'teal', 'route' => 'sales', 'params' => ['state' => 'pending'], 'ability' => 'payments.record'],
        ['label' => 'New Contract', 'icon' => 'document', 'accent' => 'slate', 'route' => 'papers', 'ability' => 'papers.create'],
        ['label' => 'Scan QR', 'icon' => 'qr-code', 'accent' => 'pink', 'route' => 'scan'],
        ['label' => 'More', 'icon' => 'ellipsis', 'accent' => 'slate', 'route' => 'help'],
    ],
];
