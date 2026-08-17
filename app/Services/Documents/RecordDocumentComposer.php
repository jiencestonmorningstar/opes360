<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\User;
use App\Services\DocumentComposer;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * "New document" starting from an ERP record — §5 item 2 of the completion
 * plan.
 *
 * Two things happen that a hand-typed document never gets for free: the
 * DocumentFieldRegistry context is seeded from the record, so a template can
 * say `{{ contract.title }}` and mean it, and the resulting draft is
 * pre-linked to the record through DocumentLinker — the same table every
 * other "documents about this record" list already reads. Composing still
 * happens through DocumentComposer alone; this class only decides which
 * context key a given record maps to and wires the two existing services
 * together.
 */
class RecordDocumentComposer
{
    /**
     * Which DocumentFieldRegistry context key a record type answers to.
     *
     * New entries here are how a third module joins this entry point — same
     * shape as DocumentFieldRegistry itself, just naming record classes
     * instead of field prefixes.
     *
     * @var array<class-string, string>
     */
    protected array $contextKeys = [
        Contact::class => 'customer',
        Contract::class => 'contract',
    ];

    public function __construct(
        protected DocumentComposer $composer,
        protected DocumentFieldRegistry $registry,
        protected DocumentLinker $linker,
    ) {}

    /**
     * The context key for a record, registering that key's field provider
     * along the way if it needs it (see registerProviderIfNeeded()).
     *
     * A caller that only wants to *know* the key — WorkflowEngine's
     * ComposeDocumentStep and ActionRunner both do, before they have decided
     * whether they will actually compose anything — still gets the provider
     * registered here rather than needing to call a second method, since the
     * only reason either of them asks is that a merge() is coming next.
     */
    public function contextKeyFor(Model $record): ?string
    {
        $key = $this->contextKeys[$record::class] ?? null;

        if ($key !== null) {
            $this->registerProviderIfNeeded($key);
        }

        return $key;
    }

    public function supports(Model $record): bool
    {
        return $this->contextKeyFor($record) !== null;
    }

    /**
     * Compose a draft seeded with the record's fields, and link it back.
     *
     * @param  array<string, mixed>  $fields
     */
    public function composeDraft(
        Model $record,
        string $templateKey,
        array $fields,
        string $title,
        Company $company,
        User $user,
    ): BusinessDocument {
        $key = $this->contextKeyFor($record);

        if ($key === null) {
            throw new RuntimeException(class_basename($record).' has no document field context registered.');
        }

        $context = [$key => $record];

        $document = BusinessDocument::create([
            'template' => $templateKey,
            'title' => $title,
            'recipient' => $this->recipientFrom($record),
            'fields' => $fields,
            'body' => $this->composer->merge($templateKey, $fields, $company, $context),
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        // "about" — the default role, same one a manual link from the
        // document's own screen would use. See BusinessDocumentRelation::ROLES.
        $this->linker->attach($document, $record, 'about', $user);

        return $document;
    }

    protected function recipientFrom(Model $record): ?string
    {
        return match (true) {
            $record instanceof Contact => $record->name,
            $record instanceof Contract => $record->title,
            default => null,
        };
    }

    /**
     * Registers the `contract` field provider the first time it is needed.
     *
     * `customer` (Contact) already ships from AppServiceProvider — this class
     * does not own that file, so it cannot add `contract` there. Registering
     * it lazily, on first use, keeps the provider in exactly one place
     * (DocumentFieldRegistry is a singleton) without this module reaching
     * into a provider file that is somebody else's to change.
     */
    protected function registerProviderIfNeeded(string $key): void
    {
        if ($key !== 'contract' || $this->registry->has('contract')) {
            return;
        }

        $this->registry->register('contract', function (Company $company, array $context): array {
            $contract = $context['contract'] ?? null;

            if (! $contract instanceof Contract) {
                return [];
            }

            return [
                'contract.title' => (string) $contract->title,
                'contract.type' => (string) $contract->type,
                'contract.value' => $contract->value !== null ? (string) $contract->value : '',
            ];
        });
    }
}
