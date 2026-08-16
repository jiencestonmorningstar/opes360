<?php

namespace App\Search;

use App\Enums\DocumentStatus;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Lead;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use App\Models\Scopes\CompanyScope;
use App\Models\SearchEntry;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The global search index: what goes in, what stays out, and how it is asked.
 *
 * A portable design on purpose. One denormalised table queried with LIKE is
 * fast enough at this product's scale, works identically on SQLite and MySQL,
 * and needs no daemon — which is the shared-hosting constraint. Everything an
 * engine like Meilisearch would need (title, body, route, ability, tenant) is
 * already shaped here, so swapping the storage later changes query() and the
 * observer write path, nothing else.
 *
 * Two rules are non-negotiable and enforced in query():
 *  - tenancy: SearchEntry is BelongsToCompany, so the CompanyScope constrains
 *    every read; a search can never cross companies.
 *  - permission: every entry carries the page-level ability of the screen a
 *    click would open, re-checked per searcher through Gate + module state,
 *    and models with a `view` policy are re-checked record-by-record.
 */
class GlobalSearch
{
    public const GROUP_LABELS = [
        Contact::class => 'Customers',
        Document::class => 'Sales documents',
        BusinessDocument::class => 'Papers',
        ServiceTicket::class => 'Service tickets',
        Contract::class => 'Contracts',
        Employee::class => 'Team',
        Item::class => 'Products',
        Project::class => 'Projects',
        Lead::class => 'Leads',
        PurchaseRequisition::class => 'Requisitions',
    ];

    /**
     * How each model becomes an entry. Returning null means "this record does
     * not belong in the index" — voided, or nothing to say — and any existing
     * entry is removed.
     *
     * @return array<class-string<Model>, callable(Model): ?array>
     */
    public static function sources(): array
    {
        return [
            Contact::class => fn (Contact $c) => [
                'title' => $c->name,
                'subtitle' => $c->phone ?: $c->email,
                'body' => trim(($c->email ?? '').' '.($c->phone ?? '')) ?: null,
                'route_name' => 'customers.show',
                'route_params' => ['contact' => $c->getKey()],
                'ability' => 'customers.view',
            ],

            Document::class => function (Document $d) {
                if ($d->status === DocumentStatus::Void) {
                    return null;
                }

                // Explicit query, not a lazy load: the observer often holds a
                // model whose relations were never loaded.
                $contactName = $d->contact()->value('name');

                return [
                    'title' => (string) $d->number,
                    'subtitle' => trim($d->type->label().' · '.($contactName ?? ''), ' ·'),
                    'body' => $contactName,
                    'route_name' => 'documents.show',
                    'route_params' => ['document' => $d->getKey()],
                    'ability' => 'sales.view',
                ];
            },

            BusinessDocument::class => function (BusinessDocument $p) {
                if ($p->status === 'void') {
                    return null;
                }

                return [
                    'title' => $p->title,
                    'subtitle' => $p->reference ?: $p->kind,
                    /*
                     * The body of anything carrying a security level is not
                     * indexed AT ALL: a paper marked confidential must never
                     * leak its contents through a search snippet, and the
                     * cheapest way to guarantee that is for the words to not
                     * exist in the index. Title only.
                     */
                    'body' => $p->security === null
                        ? Str::limit(strip_tags((string) $p->body), 5000, '')
                        : null,
                    'route_name' => 'papers.show',
                    'route_params' => ['paper' => $p->getKey()],
                    'ability' => 'papers.view',
                ];
            },

            ServiceTicket::class => fn (ServiceTicket $t) => [
                'title' => $t->subject,
                'subtitle' => $t->reference,
                'body' => Str::limit((string) $t->description, 2000, ''),
                'route_name' => 'service.show',
                'route_params' => ['ticket' => $t->getKey()],
                'ability' => 'service.view',
            ],

            Contract::class => fn (Contract $c) => [
                'title' => $c->title,
                'subtitle' => ucfirst((string) $c->type),
                'body' => null,
                'route_name' => 'contracts.show',
                'route_params' => ['contract' => $c->getKey()],
                'ability' => 'contracts.view',
            ],

            // Name and staff number only. No pay, no personal details: the
            // staff file screen is gated, and so is this entry — but an index
            // should still never hold what nobody searched a palette for.
            Employee::class => fn (Employee $e) => [
                'title' => trim($e->first_name.' '.$e->last_name),
                'subtitle' => $e->number,
                'body' => null,
                'route_name' => 'team.show',
                'route_params' => ['employee' => $e->getKey()],
                'ability' => 'employees.view',
            ],

            Item::class => fn (Item $i) => [
                'title' => $i->name,
                'subtitle' => $i->sku,
                'body' => trim(($i->sku ?? '').' '.($i->barcode ?? '')) ?: null,
                'route_name' => 'products',
                'route_params' => [],
                'ability' => 'products.view',
            ],

            Project::class => fn (Project $p) => [
                'title' => $p->name,
                'subtitle' => $p->code,
                'body' => null,
                'route_name' => 'projects',
                'route_params' => [],
                'ability' => 'projects.view',
            ],

            Lead::class => fn (Lead $l) => [
                'title' => $l->name,
                'subtitle' => $l->company_name ?: $l->phone,
                'body' => trim(($l->email ?? '').' '.($l->phone ?? '').' '.($l->company_name ?? '')) ?: null,
                'route_name' => 'leads',
                'route_params' => [],
                'ability' => 'deals.view',
            ],

            PurchaseRequisition::class => fn (PurchaseRequisition $r) => [
                'title' => $r->title ?: $r->number,
                'subtitle' => $r->number,
                'body' => null,
                'route_name' => 'procurement.requisitions',
                'route_params' => [],
                'ability' => 'procurement.requisition-view',
            ],
        ];
    }

    /**
     * Attach the index-maintaining observer to every source model. Call once
     * per boot — from a service provider in production (see the integration
     * handoff) and from each test's setUp, where the dispatcher is fresh.
     * No static guard: the event dispatcher is rebuilt per test, so a guard
     * that survives it would leave later tests silently unobserved.
     */
    public static function observe(): void
    {
        foreach (array_keys(self::sources()) as $class) {
            $class::observe(SearchIndexObserver::class);
        }
    }

    /** Write (or remove) the index row for one record. */
    public static function index(Model $model): void
    {
        $build = self::sources()[$model::class] ?? null;

        if ($build === null || $model->company_id === null) {
            return;
        }

        $entry = $build($model);

        if ($entry === null || blank($entry['title'])) {
            self::forget($model);

            return;
        }

        SearchEntry::query()->withoutGlobalScope(CompanyScope::class)->updateOrCreate(
            [
                'searchable_type' => $model->getMorphClass(),
                'searchable_id' => (string) $model->getKey(),
            ],
            $entry + ['company_id' => $model->company_id],
        );
    }

    public static function forget(Model $model): void
    {
        SearchEntry::query()->withoutGlobalScope(CompanyScope::class)
            ->where('searchable_type', $model->getMorphClass())
            ->where('searchable_id', (string) $model->getKey())
            ->delete();
    }

    /**
     * What this user may see for this term, grouped by type label.
     *
     * @return array<string, array<int, array{title: string, subtitle: ?string, url: string}>>
     */
    public static function query(User $user, Company $company, string $term, int $limit = 15): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $prefix = addcslashes($term, '%_\\').'%';

        // Tenancy is the CompanyScope's doing: SearchEntry is BelongsToCompany,
        // so this query is already fenced to the current company.
        $candidates = SearchEntry::query()
            ->with('searchable')
            ->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('subtitle', 'like', $like)
                    ->orWhere('body', 'like', $like);
            })
            ->orderByRaw('(case when title like ? then 0 else 1 end)', [$prefix])
            ->orderBy('title')
            ->limit($limit * 4)
            ->get();

        // The permission fence. An ability is checked once per distinct value,
        // through both the role gate and the company's module switches.
        $allowed = [];
        $results = [];

        foreach ($candidates as $entry) {
            $ability = $entry->ability;

            if (! array_key_exists($ability, $allowed)) {
                $allowed[$ability] = Modules::allowsAbility($company, $ability)
                    && Gate::forUser($user)->allows($ability);
            }

            if (! $allowed[$ability]) {
                continue;
            }

            /*
             * Second, record-level fence for models whose screens are gated by
             * a policy rather than the page ability alone — a restricted paper
             * is readable by its owner and the managers, not by everyone with
             * papers.view. Hydrating also drops stale rows whose record has
             * since been trashed.
             */
            $model = $entry->searchable;

            if ($model === null) {
                continue;
            }

            if (Gate::getPolicyFor($model) !== null && ! Gate::forUser($user)->allows('view', $model)) {
                continue;
            }

            $results[] = $entry;

            if (count($results) >= $limit) {
                break;
            }
        }

        $groups = [];

        foreach ($results as $entry) {
            $label = self::GROUP_LABELS[$entry->searchable_type] ?? 'Other';

            $groups[$label][] = [
                'title' => $entry->title,
                'subtitle' => $entry->subtitle,
                'url' => route($entry->route_name, $entry->route_params),
            ];
        }

        return $groups;
    }
}
