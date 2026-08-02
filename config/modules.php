<?php

use App\Models\BankAccount;
use App\Models\BusinessDocument;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Event;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\Form;
use App\Models\Item;
use App\Models\PartnerClient;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Receipt;
use App\Models\StockLocation;
use App\Models\Stocktake;
use App\Models\Ticket;

/*
 * The module catalogue — what a business can switch on and off.
 *
 * ── Why this exists ──────────────────────────────────────────────────────
 *
 * A hairdresser does not have a fixed asset register. A consultancy has no
 * stock. A two-person shop has no payroll. Every module this application
 * grows makes it heavier for the businesses that will never open it, and the
 * cost is not hypothetical: it is a longer navigation, a fuller "More" sheet
 * and a first-run experience that looks like accounting software rather than
 * like something you can use on a phone between customers.
 *
 * So every module beyond the account itself is switchable, per business.
 *
 * ── How a switch is enforced ─────────────────────────────────────────────
 *
 * In exactly one place. `AuthServiceProvider` denies every ability belonging
 * to a disabled module, and because the navigation, the routes, the quick
 * actions and the components all ask the same gate, they all go quiet
 * together. Adding a module means adding an entry here, not remembering four
 * templates — the same reasoning as config/opes.php's navigation list.
 *
 * `groups` are permission-catalogue groups (see Support\Permissions). Every
 * ability inside them belongs to the module. `abilities` names individual
 * ones for a module that shares a group with another — stock locations live
 * inside Products, but a business can have products without having
 * warehouses. `models` maps a model class to a module so that model-backed
 * policy checks (`can:view,document`) are covered too; without it a disabled
 * module's detail pages would stay reachable by their direct URL.
 *
 * ── Defaults ─────────────────────────────────────────────────────────────
 *
 * Everything is on for a new business. The alternative — shipping the
 * specialist modules off — means a business that needs one never discovers
 * it, and "why can't I find X" is a worse first day than a long menu. The
 * settings screen is where a business prunes.
 *
 * `core` modules are not listed at all: the business record, its users, its
 * devices and its settings are the account, not a feature of it.
 */
return [

    'sales' => [
        'label' => 'Sales & invoicing',
        'description' => 'Invoices, quotations, proformas, receipts and the payments against them.',
        'icon' => 'sales',
        'default' => true,
        'groups' => ['sales', 'receipts', 'payments'],
        'models' => [
            Document::class,
            Receipt::class,
            Payment::class,
        ],
    ],

    'customers' => [
        'label' => 'Customers',
        'description' => 'The customer and supplier book, with balances and statements.',
        'icon' => 'user',
        'default' => true,
        'groups' => ['customers'],
        'models' => [Contact::class],
    ],

    'products' => [
        'label' => 'Products & stock',
        'description' => 'What you sell, what it costs, and how much of it is left.',
        'icon' => 'cube',
        'default' => true,
        // manage-locations belongs to the stock-locations module below, so it
        // is excluded here rather than inherited from the group.
        'groups' => ['products'],
        'except' => ['products.manage-locations'],
        'models' => [Item::class, Stocktake::class],
    ],

    'stock_locations' => [
        'label' => 'Multiple stock locations',
        'description' => 'A shop, a warehouse and a van, each with its own stock, and transfers between them.',
        'icon' => 'cube',
        'default' => true,
        // A business with one till and one shelf does not need locations, and
        // turning them on should not be a decision it has to understand
        // before it can record a sale.
        'requires' => ['products'],
        'abilities' => ['products.manage-locations'],
        'models' => [StockLocation::class],
    ],

    'expenses' => [
        'label' => 'Purchases & expenses',
        'description' => 'Supplier bills and day-to-day spending, with the TVA you can reclaim.',
        'icon' => 'banknotes',
        'default' => true,
        'groups' => ['expenses'],
        'models' => [Expense::class],
    ],

    'accounting' => [
        'label' => 'Accounting',
        'description' => 'The SYSCOHADA chart, journals, ledgers and financial statements.',
        'icon' => 'wallet',
        'default' => true,
        'groups' => ['accounting'],
    ],

    'assets' => [
        'label' => 'Fixed assets',
        'description' => 'What the business owns, what it is still worth, and the depreciation charge.',
        'icon' => 'briefcase',
        'default' => true,
        'groups' => ['assets'],
        'models' => [FixedAsset::class],
    ],

    'banking' => [
        'label' => 'Bank reconciliation',
        'description' => 'Match a bank statement against the books and see what has not cleared.',
        'icon' => 'credit-card',
        'default' => true,
        // Reconciling means reconciling *against the ledger*. Without the
        // books there is nothing to match a statement to.
        'requires' => ['accounting'],
        'groups' => ['banking'],
        'models' => [BankAccount::class],
    ],

    'hr' => [
        'label' => 'Team & HR',
        'description' => 'Staff records, contracts, allowances and leave.',
        'icon' => 'users',
        'default' => true,
        'groups' => ['employees', 'leave'],
        'models' => [Employee::class],
    ],

    'payroll' => [
        'label' => 'Payroll',
        'description' => 'Monthly payslips with CNPS, IRPP and the employer’s own charges.',
        'icon' => 'banknotes',
        'default' => true,
        // A payroll run reads contracts. There is nothing to pay without them.
        'requires' => ['hr'],
        'groups' => ['payroll'],
        'models' => [PayrollRun::class, Payslip::class],
    ],

    'papers' => [
        'label' => 'Documents',
        'description' => 'Contracts, letters and certificates generated from templates.',
        'icon' => 'document',
        'default' => true,
        'groups' => ['papers'],
        'models' => [BusinessDocument::class],
    ],

    'forms' => [
        'label' => 'Forms',
        'description' => 'Shareable forms and the responses they collect.',
        'icon' => 'clipboard',
        'default' => true,
        'groups' => ['forms'],
        'models' => [Form::class],
    ],

    'events' => [
        'label' => 'Events & ticketing',
        'description' => 'Sell tickets, scan them at the door.',
        'icon' => 'ticket',
        'default' => true,
        'groups' => ['events'],
        'models' => [Event::class, Ticket::class],
    ],

    'loyalty' => [
        'label' => 'Loyalty',
        'description' => 'Points, cards and redemptions for repeat customers.',
        'icon' => 'spark',
        'default' => true,
        'groups' => ['loyalty'],
    ],

    'reports' => [
        'label' => 'Reports',
        'description' => 'Sales, customers and stock, summarised over a period.',
        'icon' => 'chart-bar',
        'default' => true,
        'groups' => ['reports'],
    ],

    /*
     * Not offered as a switch: the partner programme is already conditioned on
     * the account being a secretariat, and a secretariat that turned it off
     * would have signed up for nothing. Listed so the catalogue is complete
     * and so nothing else claims its abilities.
     */
    'partners' => [
        'label' => 'Secretariat programme',
        'description' => 'The client book, card issuing and commission ledger.',
        'icon' => 'printer',
        'default' => true,
        'switchable' => false,
        'groups' => ['partners'],
        'models' => [PartnerClient::class],
    ],
];
