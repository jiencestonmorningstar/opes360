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
