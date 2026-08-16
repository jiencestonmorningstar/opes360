<?php

namespace App\Support;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * The in-product documentation: one guide per feature.
 *
 * A catalogue rather than a folder scan, for the same reason Permissions and
 * DomainEvents are catalogues — the order, the grouping and the summaries are
 * editorial decisions, and a directory listing makes them alphabetical
 * accidents instead. It also means a guide that has been written but not
 * catalogued fails a test rather than quietly never appearing.
 *
 * Bodies live in `resources/guides/*.md` so they can be written as prose
 * rather than escaped into a PHP string.
 *
 * **Every feature gets a guide.** A feature nobody can find out how to use is
 * not finished, and the test suite enforces the pairing in both directions:
 * a catalogued guide with no file fails, and a file with no catalogue entry
 * fails too.
 */
class Guides
{
    /**
     * slug => [title, group, summary, audience]
     *
     * `audience` is who the guide is written for. `everyone` is a business
     * user; `admin` is whoever sets the business up; `developer` is somebody
     * integrating over the API. Mixing the three in one page is how manuals
     * become unreadable.
     */
    public const CATALOGUE = [
        'getting-started' => [
            'title' => 'How this documentation works',
            'group' => 'Start here',
            'summary' => 'What is in here, who each guide is for, and how to find the one you need.',
            'audience' => 'everyone',
        ],

        'departments' => [
            'title' => 'Departments',
            'group' => 'Your business',
            'summary' => 'The org chart your staff, documents and approvals are filed under.',
            'audience' => 'admin',
        ],

        'leads' => [
            'title' => 'Leads',
            'group' => 'Working together',
            'summary' => 'Enquiries before they are deals: working them, converting them, and keeping why you lost the rest.',
            'audience' => 'everyone',
        ],

        'recruitment' => [
            'title' => 'Hiring',
            'group' => 'Your business',
            'summary' => 'From a public advert to a real employee: applications, interviews, offers and the approval behind them.',
            'audience' => 'admin',
        ],

        'service-desk' => [
            'title' => 'The service desk',
            'group' => 'Working together',
            'summary' => 'Customer tickets, the visits that resolve them, and the response times you have promised.',
            'audience' => 'everyone',
        ],

        'notification-rules' => [
            'title' => 'Who gets told what',
            'group' => 'Working together',
            'summary' => 'Rules that decide who hears about what, and how to stop the noise without going deaf.',
            'audience' => 'admin',
        ],

        'audit-trail' => [
            'title' => 'The audit trail',
            'group' => 'Your business',
            'summary' => 'Who changed what and when, and the report on who can do things nobody should be able to do alone.',
            'audience' => 'admin',
        ],

        'contracts' => [
            'title' => 'Contracts',
            'group' => 'Your business',
            'summary' => 'Agreements, what each side owes, and the notice date that decides whether one renews itself.',
            'audience' => 'everyone',
        ],

        'compliance-and-risk' => [
            'title' => 'Deadlines and risks',
            'group' => 'Your business',
            'summary' => 'The statutory calendar, the evidence you met it, and the register of what could go wrong.',
            'audience' => 'admin',
        ],

        'asset-movements' => [
            'title' => 'Where your equipment is',
            'group' => 'Your business',
            'summary' => 'Sites, who is holding what, and keeping servicing from being remembered too late.',
            'audience' => 'everyone',
        ],

        'manufacturing' => [
            'title' => 'Making things',
            'group' => 'Your business',
            'summary' => 'Recipes for what you make, and the one step that takes components off the shelf and puts finished goods on it.',
            'audience' => 'everyone',
        ],

        'replenishment' => [
            'title' => 'Reordering before you run out',
            'group' => 'Money',
            'summary' => 'Reorder levels, supplier lead times, and turning what is running low into draft requisitions.',
            'audience' => 'everyone',
        ],

        'paying-suppliers' => [
            'title' => 'Deciding which bills to pay',
            'group' => 'Money',
            'summary' => 'Building a payment run against the cash you actually have, and checking a supplier’s statement against your books.',
            'audience' => 'admin',
        ],

        'requisitions-and-quotes' => [
            'title' => 'Asking before buying',
            'group' => 'Money',
            'summary' => 'Requisitions, requests for quotation, and comparing what suppliers come back with.',
            'audience' => 'everyone',
        ],

        'attendance-and-reviews' => [
            'title' => 'Positions, attendance and reviews',
            'group' => 'Your business',
            'summary' => 'The posts people hold, the hours they work, and the reviews that go on their file.',
            'audience' => 'admin',
        ],

        'approvals' => [
            'title' => 'Approvals and workflows',
            'group' => 'Working together',
            'summary' => 'Getting something signed off: who is asked, in what order, and what happens when they answer.',
            'audience' => 'everyone',
        ],

        'my-actions' => [
            'title' => 'My actions',
            'group' => 'Working together',
            'summary' => 'The one list of everything waiting on you, from every part of the business.',
            'audience' => 'everyone',
        ],

        'automation' => [
            'title' => 'Automation rules',
            'group' => 'Working together',
            'summary' => 'When this happens, do that — without anybody having to remember.',
            'audience' => 'admin',
        ],

        'closing-the-books' => [
            'title' => 'Closing the books',
            'group' => 'Your business',
            'summary' => 'Financial years, closing a month, cost centres, and where the cash went.',
            'audience' => 'admin',
        ],

        'dossiers-and-packages' => [
            'title' => 'Dossiers, packages and checklists',
            'group' => 'Documents',
            'summary' => 'Three ways of seeing documents together that are not folders.',
            'audience' => 'everyone',
        ],

        'custom-templates' => [
            'title' => 'Your own templates',
            'group' => 'Documents',
            'summary' => 'Write, publish and version a template of your own alongside the built-in ones.',
            'audience' => 'admin',
        ],

        'projects' => [
            'title' => 'Projects',
            'group' => 'Working together',
            'summary' => 'Chargeable and internal work: tasks, time, and cost against a budget.',
            'audience' => 'everyone',
        ],

        'documents-workspace' => [
            'title' => 'Finding a document',
            'group' => 'Documents',
            'summary' => 'Search, filters and the overview counters on the Documents screen.',
            'audience' => 'everyone',
        ],

        'document-security' => [
            'title' => 'Who can see a document',
            'group' => 'Documents',
            'summary' => 'Confidentiality levels, sharing, and what restricted actually means.',
            'audience' => 'everyone',
        ],

        'insurance' => [
            'title' => 'Broking insurance',
            'group' => 'Industries',
            'summary' => 'Policies and their premiums, claims that settle through approval, and the alarm for cover lapsing unagreed.',
            'audience' => 'everyone',
        ],

        'sales-orders' => [
            'title' => 'Customer orders and delivery',
            'group' => 'Industries',
            'summary' => 'Orders confirmed against real stock, backorders you can see, delivery notes with a QR, and an invoice for what actually went.',
            'audience' => 'everyone',
        ],

        'logistics' => [
            'title' => 'Running a transport business',
            'group' => 'Industries',
            'summary' => 'Shipments, trip manifests, signed proof of delivery, and a tracking link that shows the customer their cargo and nothing else.',
            'audience' => 'everyone',
        ],

        'property-management' => [
            'title' => 'Letting a property',
            'group' => 'Industries',
            'summary' => 'Buildings and units, tenancies whose lease is a real contract, deposits in the books, and rent that bills itself.',
            'audience' => 'everyone',
        ],
    ];

    /** @return array<string, array<string, string>> */
    public static function all(): array
    {
        return self::CATALOGUE;
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::CATALOGUE);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        if (! self::exists($slug)) {
            return null;
        }

        return self::CATALOGUE[$slug] + ['slug' => $slug];
    }

    /**
     * Guides by group, in catalogue order.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::CATALOGUE as $slug => $guide) {
            $grouped[$guide['group']][] = $guide + ['slug' => $slug];
        }

        return $grouped;
    }

    public static function path(string $slug): string
    {
        return resource_path('guides/'.$slug.'.md');
    }

    /** The raw markdown. */
    public static function body(string $slug): string
    {
        $path = self::path($slug);

        if (! is_file($path)) {
            throw new RuntimeException("Guide [{$slug}] is catalogued but has no file at {$path}.");
        }

        return (string) file_get_contents($path);
    }

    /**
     * The guide as HTML.
     *
     * `Str::markdown()` is Laravel's own CommonMark wrapper — already a
     * framework dependency, so the documentation costs no new package. HTML in
     * a guide is escaped rather than rendered: these files are written by us,
     * but a documentation page that renders raw HTML is a documentation page
     * that will one day render somebody's script tag.
     */
    public static function html(string $slug): string
    {
        return Str::markdown(self::body($slug), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    /** The first paragraph, for search results and previews. */
    public static function excerpt(string $slug): string
    {
        return self::CATALOGUE[$slug]['summary'] ?? '';
    }

    /**
     * Guides whose title, summary or body mention the term.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return array_map(
                fn (string $slug) => self::find($slug),
                self::slugs(),
            );
        }

        $matches = [];

        foreach (self::CATALOGUE as $slug => $guide) {
            $haystack = $guide['title'].' '.$guide['summary'].' '.$guide['group'];

            if (is_file(self::path($slug))) {
                $haystack .= ' '.self::body($slug);
            }

            if (Str::contains($haystack, $term, ignoreCase: true)) {
                $matches[] = $guide + ['slug' => $slug];
            }
        }

        return $matches;
    }
}
