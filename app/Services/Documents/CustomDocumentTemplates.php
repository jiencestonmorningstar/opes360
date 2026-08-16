<?php

namespace App\Services\Documents;

use App\Models\BusinessDocumentTemplate;
use App\Models\User;
use App\Support\DocumentTemplates;
use RuntimeException;

/**
 * Create, edit, version and publish a business's own templates — §8.
 *
 * Kept entirely separate from the built-in catalogue in
 * App\Support\DocumentTemplates, which stays pure PHP: a curated set that
 * ships with the product and is reviewed like any other code. A business's
 * own templates are the ones this class manages, and the two are only ever
 * merged at the point something reads "all templates" — never written to the
 * same store.
 */
class CustomDocumentTemplates
{
    /** The columns a version is a version of. */
    protected const CONTENT_COLUMNS = ['name', 'summary', 'fields', 'body'];

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): BusinessDocumentTemplate
    {
        $key = $data['key'];

        if (DocumentTemplates::exists($key)) {
            throw new RuntimeException("[{$key}] is already a built-in template. Choose a different key.");
        }

        /*
         * 'is_published' set explicitly rather than left to the migration's
         * column default. create() does not re-fetch the row afterwards, so
         * the in-memory model would read the flag as null even though the
         * database correctly has false — the same gotcha the signature and
         * numbering-scheme rows hit earlier in this module.
         */
        $template = BusinessDocumentTemplate::create([
            'key' => $key,
            'name' => $data['name'],
            'summary' => $data['summary'] ?? null,
            'icon' => $data['icon'] ?? 'document',
            'accent' => $data['accent'] ?? 'blue',
            'binding' => $data['binding'] ?? false,
            'fields' => $data['fields'] ?? [],
            'body' => $data['body'],
            'created_by' => $actor->id,
            'is_published' => false,
        ]);

        $this->snapshot($template);

        return $template;
    }

    /** @param  array<string, mixed>  $data */
    public function update(BusinessDocumentTemplate $template, array $data): BusinessDocumentTemplate
    {
        $template->update(array_intersect_key(
            $data,
            array_flip(['name', 'summary', 'icon', 'accent', 'binding', 'fields', 'body']),
        ));

        $dirty = array_intersect_key($template->getChanges(), array_flip(self::CONTENT_COLUMNS));

        if ($dirty !== []) {
            $this->snapshot($template);
        }

        return $template->fresh();
    }

    public function publish(BusinessDocumentTemplate $template): BusinessDocumentTemplate
    {
        $template->update(['is_published' => true]);

        return $template->fresh();
    }

    public function unpublish(BusinessDocumentTemplate $template): BusinessDocumentTemplate
    {
        $template->update(['is_published' => false]);

        return $template->fresh();
    }

    /**
     * A published custom template, in the same array shape
     * App\Support\DocumentTemplates::find() returns — or null, so
     * DocumentComposer can fall through to the built-in catalogue exactly as
     * it did before this class existed.
     */
    public function find(string $key): ?array
    {
        return BusinessDocumentTemplate::query()
            ->where('key', $key)
            ->published()
            ->first()
            ?->toTemplateArray();
    }

    /** Every published custom template, keyed the same way DocumentTemplates::all() is. */
    public function allPublishedAsArray(): array
    {
        return BusinessDocumentTemplate::query()
            ->published()
            ->get()
            ->mapWithKeys(fn (BusinessDocumentTemplate $t) => [$t->key => $t->toTemplateArray()])
            ->all();
    }

    protected function snapshot(BusinessDocumentTemplate $template): void
    {
        $next = (int) $template->versions()->max('version_number') + 1;

        $template->versions()->create([
            'version_number' => $next,
            'name' => $template->name,
            'summary' => $template->summary,
            'fields' => $template->fields,
            'body' => $template->body,
            'created_by' => auth()->id() ?? $template->created_by,
        ]);
    }
}
