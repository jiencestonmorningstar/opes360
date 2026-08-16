<?php

use App\Models\AssetLocation;
use App\Models\AssetMaintenance;
use App\Models\AssetTransfer;
use App\Models\AttendanceRecord;
use App\Models\BankAccount;
use App\Models\BusinessDocument;
use App\Models\ComplianceObligation;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\CrmActivity;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Event;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\Form;
use App\Models\FuelLog;
use App\Models\Item;
use App\Models\JobApplication;
use App\Models\JobOffer;
use App\Models\Lead;
use App\Models\PartnerClient;
use App\Models\Payment;
use App\Models\PaymentRun;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PerformanceReview;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use App\Models\Receipt;
use App\Models\Rfq;
use App\Models\Risk;
use App\Models\ServiceJob;
use App\Models\ServiceSlaPolicy;
use App\Models\ServiceTicket;
use App\Models\StockLocation;
use App\Models\Stocktake;
use App\Models\SupplierQuotation;
use App\Models\SupplierStatement;
use App\Models\Ticket;
use App\Models\Vacancy;
use App\Models\VehicleDetail;
use App\Models\VehicleTrip;
use App\Models\VipMembership;
use App\Models\VipTier;

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

    'deals' => [
        'label' => 'Sales pipeline',
        'description' => 'Leads and deals in progress, from first enquiry to the invoice.',
        'icon' => 'trending-up',
        'default' => true,
        // A deal is about somebody. Without the customer book there is nowhere
        // for a won deal to leave a customer behind.
        'requires' => ['customers'],
        'groups' => ['deals'],
        // Leads and activities are the front half of the same pipeline, so
        // they switch off with it rather than surviving as orphans.
        'models' => [Deal::class, Lead::class, CrmActivity::class],
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

    'projects' => [
        'label' => 'Projects',
        'description' => 'Chargeable and internal work: tasks, milestones, time and cost against a budget.',
        'icon' => 'briefcase',
        // On by default like everything else — a business that does project
        // work should find this without having to know to look for it. See
        // the file header: the settings screen is where a business prunes.
        'default' => true,
        'groups' => ['projects'],
        'models' => [Project::class],
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
        // Fleet lives here rather than in a module of its own. A switchable
        // `fleet` could be turned off while the vans stayed on the register,
        // orphaning their milometers and their roadworthiness dates.
        'models' => [
            FixedAsset::class, AssetLocation::class, AssetTransfer::class, AssetMaintenance::class,
            VehicleDetail::class, VehicleTrip::class, FuelLog::class,
        ],
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
        // Positions are deliberately absent from these groups, for the same
        // reason departments are: a job title outlives a business switching
        // its HR screens off, and payroll and the org chart both read it.
        'groups' => ['employees', 'leave', 'attendance', 'reviews'],
        'models' => [Employee::class, AttendanceRecord::class, PerformanceReview::class],
    ],

    'contracts' => [
        'label' => 'Contracts',
        'description' => 'Agreements with customers and suppliers, what each side owes, and the notice date before one renews itself.',
        'icon' => 'document-check',
        'default' => true,
        // The other party is a Contact, so customers must be on. Documents
        // deliberately are not required: a contract with no scanned copy
        // attached is still an agreement with a notice deadline, and that
        // deadline is the thing worth being told about.
        'requires' => ['customers'],
        'groups' => ['contracts'],
        'models' => [Contract::class],
    ],

    'service' => [
        'label' => 'Service desk',
        'description' => 'Customer tickets, the visits that resolve them, and what you have promised about response times.',
        'icon' => 'wrench-screwdriver',
        'default' => false,
        // A ticket is raised by a customer, so the customer list must exist.
        'requires' => ['customers'],
        'groups' => ['service'],
        'models' => [ServiceTicket::class, ServiceJob::class, ServiceSlaPolicy::class],
    ],

    'compliance' => [
        'label' => 'Compliance & risk',
        'description' => 'Statutory deadlines, the evidence they were met, and the register of what could go wrong.',
        'icon' => 'shield-check',
        // On by default. A business that does not know it needs this is
        // precisely the business that needs it.
        'default' => true,
        'groups' => ['compliance', 'risks'],
        'models' => [ComplianceObligation::class, Risk::class],
    ],

    'recruitment' => [
        'label' => 'Recruitment',
        'description' => 'Vacancies, applications, interviews and offers — from advert to employee.',
        'icon' => 'users',
        // Off by default: most businesses on this product hire once a year by
        // word of mouth, not through a pipeline. Switching it off also takes
        // the public application pages down — an advert for a business that
        // has turned hiring off must 404, not collect CVs into a void.
        'default' => false,
        'requires' => ['hr'],
        'groups' => ['recruitment'],
        'models' => [Vacancy::class, JobApplication::class, JobOffer::class],
    ],

    'payables' => [
        'label' => 'Payment scheduling',
        'description' => 'Decide which bills to pay this week, and reconcile a supplier’s statement against the books.',
        'icon' => 'calendar-days',
        'default' => false,
        // Scheduling means scheduling *bills*. Without expenses there is
        // nothing to schedule, and a supplier statement has nothing to
        // disagree with.
        'requires' => ['expenses'],
        'groups' => ['payables'],
        'models' => [PaymentRun::class, SupplierStatement::class],
    ],

    'procurement' => [
        'label' => 'Requisitions & sourcing',
        'description' => 'Ask before buying: requisitions, quotation requests, and comparing what suppliers offer.',
        'icon' => 'clipboard-document-check',
        'default' => false,
        // The end of this process is a purchase order, which lives with
        // expenses. Sourcing with nowhere for the order to land is a dead end.
        'requires' => ['expenses'],
        'groups' => ['procurement'],
        'models' => [PurchaseRequisition::class, Rfq::class, SupplierQuotation::class],
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

    /*
     * The one module that ships off. Every module above defaults on so a
     * business discovers what it needs rather than never finding it — but
     * VIP is meaningless until somebody has configured at least one tier,
     * and most small businesses run no membership programme at all. Leaving
     * it on by default would put an empty screen with nothing to do on it
     * in front of every business that will never use it, which is the exact
     * cost this catalogue's "everything on" default exists to avoid for the
     * businesses that need something narrower. Deliberate exception, not an
     * oversight.
     */
    'vip' => [
        'label' => 'VIP membership',
        'description' => 'Paid tiers that discount a member\'s invoices for the term they bought.',
        'icon' => 'spark',
        'default' => false,
        // A membership belongs to somebody. Without the customer book there
        // is no one to sell a tier to.
        'requires' => ['customers'],
        'groups' => ['vip'],
        'models' => [VipTier::class, VipMembership::class],
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
