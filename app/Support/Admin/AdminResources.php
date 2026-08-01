<?php

namespace App\Support\Admin;

use App\Models\Artisan;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Document;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\StockMovement;
use App\Models\SubscriptionPayment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Every tenant-owned table the platform admin can browse, in one registry.
 *
 * The alternative was a bespoke page per model, which is how an admin panel
 * ends up seeing five of twenty tables: each new module ships its own screens
 * and quietly forgets the back office. Adding a resource here is one entry,
 * and it is immediately searchable, sortable, paginated and reachable both
 * platform-wide and scoped to a single company.
 *
 * Everything here is READ ONLY on purpose. A platform admin needs to see a
 * business's records to answer a support question or investigate abuse; they
 * have no business silently editing a customer's invoice, and the actions that
 * do belong to them — suspend, plan change, member removal — already live on
 * the company screen where they are logged.
 *
 * `columns` are closures rather than field names so a row can render a
 * relation, an enum label or a formatted amount without the view knowing which
 * model it is looking at.
 */
class AdminResources
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'documents' => [
                'label' => 'Documents',
                'model' => Document::class,
                'icon' => 'document',
                'with' => ['contact'],
                'search' => ['number', 'reference'],
                'columns' => [
                    'Number' => fn ($r) => $r->number ?? '— draft —',
                    'Type' => fn ($r) => $r->type?->label(),
                    'Customer' => fn ($r) => $r->contact?->displayName(),
                    'Status' => fn ($r) => $r->status?->label(),
                    'Total' => fn ($r) => self::money($r->total, $r->currency),
                    'Issued' => fn ($r) => self::date($r->issue_date),
                ],
            ],

            'payments' => [
                'label' => 'Payments',
                'model' => Payment::class,
                'icon' => 'banknotes',
                'with' => ['contact'],
                'search' => ['reference', 'provider_reference'],
                'columns' => [
                    'Received' => fn ($r) => self::date($r->received_at),
                    'Customer' => fn ($r) => $r->contact?->displayName(),
                    'Method' => fn ($r) => $r->method?->label(),
                    'Reference' => fn ($r) => $r->reference,
                    'Amount' => fn ($r) => self::money($r->amount, $r->currency),
                ],
            ],

            'receipts' => [
                'label' => 'Receipts',
                'model' => Receipt::class,
                'icon' => 'receipt',
                'with' => ['contact'],
                'search' => ['number'],
                'columns' => [
                    'Number' => fn ($r) => $r->number,
                    'Customer' => fn ($r) => $r->contact?->displayName(),
                    'Status' => fn ($r) => ucfirst((string) $r->status),
                    'Total' => fn ($r) => self::money($r->total, $r->currency),
                    'Issued' => fn ($r) => self::date($r->issued_at),
                ],
            ],

            'subscription-payments' => [
                'label' => 'Subscription payments',
                'model' => SubscriptionPayment::class,
                'icon' => 'credit-card',
                'search' => ['external_id', 'provider_reference', 'phone'],
                'columns' => [
                    'Date' => fn ($r) => self::date($r->created_at),
                    'Plan' => fn ($r) => ucfirst((string) $r->plan).' · '.ucfirst((string) $r->billing_cycle),
                    'Provider' => fn ($r) => $r->provider === 'mtn_momo' ? 'MTN MoMo' : 'Orange Money',
                    'Phone' => fn ($r) => $r->phone,
                    'Status' => fn ($r) => ucfirst((string) $r->status),
                    'Amount' => fn ($r) => self::money($r->amount, $r->currency),
                ],
            ],

            'customers' => [
                'label' => 'Customers',
                'model' => Contact::class,
                'icon' => 'user',
                'search' => ['name', 'company_name', 'email'],
                'columns' => [
                    'Name' => fn ($r) => $r->displayName(),
                    'Email' => fn ($r) => $r->email,
                    'Type' => fn ($r) => ucfirst((string) $r->type),
                    'Balance' => fn ($r) => self::money($r->balance, null),
                    'Loyalty' => fn ($r) => (int) $r->loyalty_points.' pts',
                    'Added' => fn ($r) => self::date($r->created_at),
                ],
            ],

            'products' => [
                'label' => 'Products',
                'model' => Item::class,
                'icon' => 'cube',
                'search' => ['name', 'sku'],
                'columns' => [
                    'Name' => fn ($r) => $r->name,
                    'SKU' => fn ($r) => $r->sku,
                    'Type' => fn ($r) => ucfirst((string) $r->type),
                    'Price' => fn ($r) => self::money($r->price, null),
                    'Stock' => fn ($r) => $r->track_stock ? (string) $r->stockOnHand() : '—',
                    'Active' => fn ($r) => $r->is_active ? 'Yes' : 'No',
                ],
            ],

            'papers' => [
                'label' => 'Business documents',
                'model' => BusinessDocument::class,
                'icon' => 'clipboard',
                'search' => ['title', 'number', 'recipient'],
                'columns' => [
                    'Number' => fn ($r) => $r->number ?? '— draft —',
                    'Title' => fn ($r) => $r->title,
                    'Recipient' => fn ($r) => $r->recipient,
                    'Status' => fn ($r) => ucfirst((string) $r->status),
                    'Created' => fn ($r) => self::date($r->created_at),
                ],
            ],

            'forms' => [
                'label' => 'Forms',
                'model' => Form::class,
                'icon' => 'clipboard',
                'withCount' => ['responses'],
                'search' => ['title'],
                'columns' => [
                    'Title' => fn ($r) => $r->title,
                    'Status' => fn ($r) => ucfirst((string) $r->status),
                    'Responses' => fn ($r) => (string) ($r->responses_count ?? 0),
                    'Created' => fn ($r) => self::date($r->created_at),
                ],
            ],

            'form-responses' => [
                'label' => 'Form responses',
                'model' => FormResponse::class,
                'icon' => 'clipboard',
                'with' => ['form'],
                'columns' => [
                    'Form' => fn ($r) => $r->form?->title,
                    'Answers' => fn ($r) => (string) count((array) $r->answers).' field(s)',
                    'Received' => fn ($r) => self::date($r->created_at),
                ],
            ],

            'events' => [
                'label' => 'Events',
                'model' => Event::class,
                'icon' => 'ticket',
                'withCount' => ['tickets'],
                'search' => ['title', 'venue'],
                'columns' => [
                    'Title' => fn ($r) => $r->title,
                    'Venue' => fn ($r) => $r->venue,
                    'Status' => fn ($r) => $r->state()['label'],
                    'Tickets' => fn ($r) => (string) ($r->tickets_count ?? 0),
                    'Starts' => fn ($r) => self::date($r->starts_at),
                ],
            ],

            'tickets' => [
                'label' => 'Tickets',
                'model' => Ticket::class,
                'icon' => 'ticket',
                'with' => ['event', 'ticketType'],
                'search' => ['serial', 'buyer_name', 'buyer_email'],
                'columns' => [
                    'Serial' => fn ($r) => $r->serial,
                    'Event' => fn ($r) => $r->event?->title,
                    'Type' => fn ($r) => $r->ticketType?->name,
                    'Buyer' => fn ($r) => $r->buyer_name,
                    'Status' => fn ($r) => $r->state()['label'],
                    'Price' => fn ($r) => self::money($r->price, null),
                ],
            ],

            'loyalty' => [
                'label' => 'Loyalty ledger',
                'model' => LoyaltyTransaction::class,
                'icon' => 'spark',
                'with' => ['contact'],
                'columns' => [
                    'Date' => fn ($r) => self::date($r->created_at),
                    'Customer' => fn ($r) => $r->contact?->displayName(),
                    'Type' => fn ($r) => ucfirst((string) $r->type),
                    'Points' => fn ($r) => ($r->points > 0 ? '+' : '').(int) $r->points,
                    'Balance after' => fn ($r) => (string) (int) $r->balance_after,
                    'Note' => fn ($r) => $r->note,
                ],
            ],

            'stock' => [
                'label' => 'Stock movements',
                'model' => StockMovement::class,
                'icon' => 'cube',
                'with' => ['item'],
                'columns' => [
                    'Date' => fn ($r) => self::date($r->created_at),
                    'Product' => fn ($r) => $r->item?->name,
                    'Type' => fn ($r) => ucfirst((string) $r->type),
                    'Quantity' => fn ($r) => (string) $r->quantity,
                    'Note' => fn ($r) => $r->note,
                ],
            ],

            'devices' => [
                'label' => 'Devices',
                'model' => Device::class,
                'icon' => 'sync',
                'with' => ['user'],
                'search' => ['name'],
                'columns' => [
                    'Name' => fn ($r) => $r->name,
                    'User' => fn ($r) => $r->user?->name,
                    'Last synced' => fn ($r) => self::date($r->last_synced_at),
                    'Pending' => fn ($r) => (string) (int) $r->pending_count,
                    'Revoked' => fn ($r) => $r->revoked_at ? self::date($r->revoked_at) : 'No',
                ],
            ],

            'reviews' => [
                'label' => 'Public reviews',
                'model' => CompanyReview::class,
                'icon' => 'spark',
                'search' => ['author_name', 'body'],
                'columns' => [
                    'Author' => fn ($r) => $r->author_name,
                    'Rating' => fn ($r) => (string) (int) $r->rating.'/5',
                    'Published' => fn ($r) => $r->is_published ? 'Yes' : 'No',
                    'Body' => fn ($r) => Str::limit((string) $r->body, 80),
                    'Received' => fn ($r) => self::date($r->created_at),
                ],
            ],

            'ledger-accounts' => [
                'label' => 'Chart of accounts',
                'model' => LedgerAccount::class,
                'icon' => 'chart-bar',
                'search' => ['number', 'name'],
                'columns' => [
                    'Number' => fn ($r) => $r->number,
                    'Name' => fn ($r) => $r->name,
                    'Class' => fn ($r) => $r->class.' — '.(ChartOfAccounts::CLASSES[$r->class] ?? ''),
                    'Normal side' => fn ($r) => ucfirst((string) $r->normal_balance),
                    'Active' => fn ($r) => $r->is_active ? 'Yes' : 'No',
                ],
            ],

            'journal' => [
                'label' => 'Journal entries',
                'model' => JournalEntry::class,
                'icon' => 'document',
                'withCount' => ['lines'],
                'search' => ['reference', 'narration'],
                'columns' => [
                    'Date' => fn ($r) => self::date($r->entry_date),
                    'Journal' => fn ($r) => $r->journal.' — '.$r->journalName(),
                    'Reference' => fn ($r) => $r->reference,
                    'Narration' => fn ($r) => $r->narration,
                    'Lines' => fn ($r) => (string) ($r->lines_count ?? 0),
                    'Debit' => fn ($r) => self::money($r->totalDebit(), null),
                    'Credit' => fn ($r) => self::money($r->totalCredit(), null),
                ],
            ],

            'artisans' => [
                'label' => 'Artisans',
                'model' => Artisan::class,
                'icon' => 'user',
                'search' => ['full_name', 'occupation'],
                'columns' => [
                    'Name' => fn ($r) => $r->full_name,
                    'Occupation' => fn ($r) => $r->occupation,
                    'Published' => fn ($r) => $r->is_published ? 'Yes' : 'No',
                    'Added' => fn ($r) => self::date($r->created_at),
                ],
            ],
        ];
    }

    /**
     * Resources that make sense platform-wide as well as per company. Users and
     * companies are not tenant-owned and have their own screens.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * A query for one resource, optionally narrowed to a single company.
     *
     * Global scopes are dropped because the tenant scope fails closed when no
     * company is current — and no company is ever current in the admin guard,
     * which has no membership anywhere. Soft-deleted rows are included: a
     * support question about a vanished invoice is answered by seeing it.
     */
    public static function query(array $resource, ?Company $company = null): Builder
    {
        /** @var class-string<Model> $model */
        $model = $resource['model'];

        $query = $model::query()->withoutGlobalScopes();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        if ($company !== null) {
            $query->where('company_id', $company->id);
        } else {
            // The platform-wide list names the business on every row, so the
            // relation is loaded rather than lazily hit fifty times a page.
            $query->with('company');
        }

        if ($resource['with'] ?? null) {
            $query->with($resource['with']);
        }

        if ($resource['withCount'] ?? null) {
            $query->withCount($resource['withCount']);
        }

        return $query;
    }

    protected static function money(mixed $amount, ?string $currency): string
    {
        return Money::format((float) $amount, $currency ?: 'XAF', false);
    }

    protected static function date(mixed $value): string
    {
        return $value instanceof Carbon || $value instanceof \DateTimeInterface
            ? Carbon::parse($value)->format('d M Y')
            : '—';
    }

    /** Models with their own admin screens, excluded from the generic browser. */
    public static function excluded(): array
    {
        return [Company::class, User::class];
    }
}
