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
     */
    'navigation' => [
        ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'dashboard', 'primary' => true],
        ['key' => 'sales', 'label' => 'Sales', 'icon' => 'sales', 'route' => 'sales', 'primary' => true],
        ['key' => 'customers', 'label' => 'Customers', 'icon' => 'user', 'route' => 'customers', 'primary' => true],
        ['key' => 'business', 'label' => 'Business', 'icon' => 'briefcase', 'route' => 'business', 'primary' => true],
        ['key' => 'products', 'label' => 'Products', 'icon' => 'cube', 'route' => 'products'],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-bar', 'route' => 'reports'],
        ['key' => 'payments', 'label' => 'Payments', 'icon' => 'credit-card', 'route' => 'payments'],
        ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar', 'route' => 'calendar'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'cog', 'route' => 'settings'],
        ['key' => 'help', 'label' => 'Help & Support', 'icon' => 'help', 'route' => 'help'],
    ],

    'industries' => [
        'Technology', 'Healthcare', 'Construction', 'Retail', 'Agriculture',
        'Hospitality', 'Education', 'Fashion', 'Manufacturing', 'Consulting',
        'Transport & Logistics', 'Beauty & Wellness', 'Food & Beverage',
        'Professional Services', 'Other',
    ],

    'currencies' => ['USD', 'EUR', 'GBP', 'NGN', 'GHS', 'KES', 'ZAR', 'XOF'],

    'quick_actions' => [
        ['label' => 'New Invoice', 'icon' => 'document-plus', 'accent' => 'blue', 'route' => 'documents.create', 'params' => ['type' => 'invoice']],
        ['label' => 'New Quotation', 'icon' => 'document', 'accent' => 'green', 'route' => 'documents.create', 'params' => ['type' => 'quotation']],
        ['label' => 'New Receipt', 'icon' => 'printer', 'accent' => 'orange', 'route' => null],
        ['label' => 'Add Customer', 'icon' => 'user-plus', 'accent' => 'purple', 'route' => 'customers'],
        ['label' => 'Add Product', 'icon' => 'cube', 'accent' => 'blue', 'route' => null],
        ['label' => 'Record Payment', 'icon' => 'banknotes', 'accent' => 'teal', 'route' => null],
        ['label' => 'Scan QR', 'icon' => 'qr-code', 'accent' => 'pink', 'route' => null],
        ['label' => 'More', 'icon' => 'ellipsis', 'accent' => 'slate', 'route' => null],
    ],
];
