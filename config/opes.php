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
        ['key' => 'sales', 'label' => 'Sales', 'icon' => 'sales', 'route' => null, 'primary' => true],
        ['key' => 'customers', 'label' => 'Customers', 'icon' => 'user', 'route' => null, 'primary' => true],
        ['key' => 'business', 'label' => 'Business', 'icon' => 'briefcase', 'route' => null, 'primary' => true],
        ['key' => 'products', 'label' => 'Products', 'icon' => 'cube', 'route' => null],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-bar', 'route' => null],
        ['key' => 'payments', 'label' => 'Payments', 'icon' => 'credit-card', 'route' => null],
        ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar', 'route' => null],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'cog', 'route' => null],
        ['key' => 'help', 'label' => 'Help & Support', 'icon' => 'help', 'route' => null],
    ],

    'quick_actions' => [
        ['label' => 'New Invoice', 'icon' => 'document-plus', 'accent' => 'blue', 'route' => null],
        ['label' => 'New Quotation', 'icon' => 'document', 'accent' => 'green', 'route' => null],
        ['label' => 'New Receipt', 'icon' => 'printer', 'accent' => 'orange', 'route' => null],
        ['label' => 'Add Customer', 'icon' => 'user-plus', 'accent' => 'purple', 'route' => null],
        ['label' => 'Add Product', 'icon' => 'cube', 'accent' => 'blue', 'route' => null],
        ['label' => 'Record Payment', 'icon' => 'banknotes', 'accent' => 'teal', 'route' => null],
        ['label' => 'Scan QR', 'icon' => 'qr-code', 'accent' => 'pink', 'route' => null],
        ['label' => 'More', 'icon' => 'ellipsis', 'accent' => 'slate', 'route' => null],
    ],
];
