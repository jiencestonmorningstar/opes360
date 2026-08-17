<?php

namespace App\Services;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\User;
use App\Models\VerificationToken;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Documents\CustomDocumentTemplates;
use App\Services\Documents\DocumentFieldRegistry;
use App\Services\Documents\DocumentLinker;
use App\Services\Workflow\WorkflowEngine;
use App\Support\DocumentTemplates;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Composes and issues business documents (Module 13).
 *
 * Merging happens once, when the document is saved, and the result is stored.
 * Rendering on read would mean a later edit to the template library silently
 * rewriting a contract someone has already signed.
 */
class DocumentComposer
{
    public function __construct(
        protected DocumentNumbers $numbers,
        protected CustomDocumentTemplates $customTemplates,
        protected DocumentLinker $linker,
    ) {}

    /**
     * Model class => DocumentFieldRegistry context key, for resolving field
     * chips (§3.2 of the master spec) against whatever ERP record a document
     * is linked to. Mirrors RecordDocumentComposer's own map — kept separate
     * rather than shared, since that class already depends on this one and a
     * shared dependency back the other way would be circular.
     *
     * @var array<class-string, string>
     */
    protected const LIVE_CONTEXT_KEYS = [
        \App\Models\Contact::class => 'customer',
        \App\Models\Employee::class => 'employee',
        \App\Models\Project::class => 'project',
        \App\Models\Contract::class => 'contract',
    ];

    /**
     * The registry context built from whichever ERP record $document is
     * linked to with the "about" role — the same role RecordDocumentComposer
     * attaches on compose, and the one a manual "Documents" panel link uses
     * by default. A document with no such link, or one about a record type
     * with no registered context key, simply resolves no live fields.
     *
     * @return array<string, mixed>
     */
    protected function liveContextFor(BusinessDocument $document): array
    {
        $relation = $this->linker->relationsOf($document)
            ->firstWhere('role', 'about');

        $related = $relation?->related;

        if ($related === null) {
            return [];
        }

        $key = self::LIVE_CONTEXT_KEYS[$related::class] ?? null;

        return $key === null ? [] : [$key => $related];
    }

    /**
     * Every field chip $document's editor can offer to insert, as
     * `token => human label` pairs — what drives the `@` autocomplete in the
     * rich editor. Values come from whatever ERP record the document is
     * linked to (see liveContextFor()); a document with no link, or none of
     * whose providers have anything to say, offers an empty list rather than
     * an error.
     *
     * @return array<string, string>
     */
    public function availableTokens(BusinessDocument $document): array
    {
        $context = $this->liveContextFor($document);

        if ($context === []) {
            return [];
        }

        $values = $this->automaticValues($document->company, $context);

        return collect($values)
            ->keys()
            ->mapWithKeys(fn (string $token) => [$token => $this->labelFor($token)])
            ->all();
    }

    /** "customer.name" -> "Customer name" — good enough for a picker; nothing here claims to be translated. */
    protected function labelFor(string $token): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $token));
    }

    /**
     * Resolves a template by key from either catalogue.
     *
     * A business's own published template is checked first — a business
     * cannot be prevented from choosing the same key as a built-in template,
     * since App\Support\DocumentTemplates::exists() already refuses that at
     * creation, so there is never a real collision to arbitrate here.
     *
     * $language only ever affects a custom template's body (§44): if it names
     * one of the template's variants, that variant's body is substituted for
     * the array's `body` key before anything else touches it — merge() and
     * everything it calls stay entirely unaware that variants exist. The
     * built-in catalogue has no variants, so $language is simply ignored for
     * those keys, exactly as before.
     */
    protected function resolveTemplate(string $templateKey, ?string $language = null): ?array
    {
        $custom = $this->customTemplates->findModel($templateKey);

        if ($custom !== null) {
            $template = $custom->toTemplateArray();
            $template['body'] = $this->customTemplates->bodyFor($custom, $language);

            return $template;
        }

        return DocumentTemplates::find($templateKey);
    }

    /**
     * Every language a template offers a picker for — empty for a built-in
     * template or a custom one with no variants, in which case Compose has
     * nothing to pick between and shows no picker.
     *
     * @return array<int, string>
     */
    public function availableLanguages(string $templateKey): array
    {
        $custom = $this->customTemplates->findModel($templateKey);

        return $custom === null ? [] : $this->customTemplates->languagesFor($custom);
    }

    /**
     * Fills a template's placeholders.
     *
     * Blank answers are the normal case, not an edge one, and a document with
     * visible holes in it is worse than one that simply omits what it was not
     * told. Three mechanisms handle that, in order:
     *
     *  - `[optional segment]` disappears entirely if any placeholder inside it
     *    is empty. That is what keeps "of {{ company.address }}" from leaving a
     *    dangling "of" mid-sentence.
     *  - A whole line that reduces to a bare label or bullet is dropped.
     *  - Runs of blank lines left behind are collapsed.
     *
     * $context carries whatever ERP record compose already knows — a
     * document started from a customer already knows its customer (§67) —
     * keyed by the relation a registered field provider expects:
     * `['customer' => $contact]`. See DocumentFieldRegistry.
     *
     * $language picks a translated body from a custom template that has
     * more than one (§44) — built-in templates and single-body custom ones
     * ignore it entirely, which is what keeps this opt-in per template
     * rather than a behaviour change for every existing caller.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $context
     */
    public function merge(string $templateKey, array $fields, Company $company, array $context = [], ?string $language = null): string
    {
        $template = $this->resolveTemplate($templateKey, $language);

        if ($template === null) {
            throw new RuntimeException("Unknown template [{$templateKey}].");
        }

        $values = $this->automaticValues($company, $context)
            + $this->presentableFields($template, $fields);

        $body = $this->resolveOptionalSegments(
            $this->dedent($this->bodyFor($templateKey, $template, $language, $company)),
            $values,
        );

        $body = preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
            fn (array $m) => (string) ($values[$m[1]] ?? ''),
            $body,
        );

        return $this->tidy($body);
    }

    /**
     * The raw body merge() should fill in, before either resolveOptionalSegments()
     * or placeholder substitution run.
     *
     * Only a custom template with at least one translation row ever reads
     * anything but its own `body` column — a built-in template, or a custom
     * one nobody has translated yet, is untouched by this method.
     *
     * @param  array<string, mixed>  $template
     */
    protected function bodyFor(string $templateKey, array $template, ?string $language, Company $company): string
    {
        $model = $this->customTemplates->findModel($templateKey);

        if ($model === null) {
            return $template['body'];
        }

        $variants = $this->customTemplates->languagesFor($model);

        if ($variants === []) {
            return $template['body'];
        }

        $target = $language ?? $this->defaultLanguage($company);

        return $this->customTemplates->bodyFor($model, in_array($target, $variants, true) ? $target : null);
    }

    /**
     * The language actually used to produce the body merge() just returned,
     * for the caller to record on BusinessDocument->language.
     *
     * Null for a built-in template or a custom one with no translations —
     * exactly the templates §44 leaves alone. For a translated template it
     * is never null: either the requested (or company-default) language had
     * a variant and that is what is reported, or it did not and the body
     * fell back to the template's own default — which this attributes to
     * the company's configured language, the closest thing a body with no
     * per-language row has to one.
     */
    public function resolveLanguage(string $templateKey, ?string $language, Company $company): ?string
    {
        $model = $this->customTemplates->findModel($templateKey);

        if ($model === null) {
            return null;
        }

        $variants = $this->customTemplates->languagesFor($model);

        if ($variants === []) {
            return null;
        }

        $target = $language ?? $this->defaultLanguage($company);

        return in_array($target, $variants, true) ? $target : $this->defaultLanguage($company);
    }

    /** The company's configured language (§44), defaulting like every other unset company setting. */
    protected function defaultLanguage(Company $company): string
    {
        return $company->language ?? config('app.locale', 'en');
    }

    /**
     * The answers as a reader should see them.
     *
     * Dates in particular: an HTML date input hands back `2026-08-01`, and a
     * contract that says an agreement begins on 2026-08-01 reads like a
     * database dump rather than a document.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    protected function presentableFields(array $template, array $fields): array
    {
        $dateKeys = collect($template['fields'])
            ->filter(fn (array $field) => ($field['type'] ?? null) === 'date')
            ->pluck('key')
            ->all();

        return collect($fields)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->map(function ($value, string $key) use ($dateKeys) {
                if (! in_array($key, $dateKeys, true) || blank($value)) {
                    return (string) $value;
                }

                try {
                    return CarbonImmutable::parse($value)->format('j F Y');
                } catch (Throwable) {
                    // An unparseable date is shown as typed rather than dropped:
                    // the user wrote something, and hiding it would be worse.
                    return (string) $value;
                }
            })
            ->all();
    }

    /**
     * Drops `[bracketed segments]` whose placeholders have no value.
     *
     * @param  array<string, string>  $values
     */
    protected function resolveOptionalSegments(string $body, array $values): string
    {
        return preg_replace_callback('/\[([^\[\]]*\{\{[^\[\]]*)\]/', function (array $m) use ($values) {
            preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $m[1], $found);

            foreach ($found[1] as $key) {
                if (blank($values[$key] ?? null)) {
                    return '';
                }
            }

            return $m[1];
        }, $body);
    }

    /** @return array<string, string> */
    /**
     * Every field a registered provider offers — §7: not hard-coded here.
     * `company` and `today` are themselves just the default provider
     * registered in AppServiceProvider; nothing about this method needs to
     * know that.
     *
     * @param  array<string, mixed>  $context
     */
    protected function automaticValues(Company $company, array $context = []): array
    {
        return app(DocumentFieldRegistry::class)->all($company, $context);
    }

    /** Heredocs in the template library are indented for readability. */
    protected function dedent(string $body): string
    {
        return preg_replace('/^[ \t]+/m', '', $body);
    }

    /**
     * Drops lines that ended up empty because their only content was an unfilled
     * placeholder, and collapses the runs of blank lines that leaves behind.
     */
    protected function tidy(string $body): string
    {
        $lines = collect(explode("\n", $body))
            ->reject(function (string $line) {
                $trimmed = trim($line);

                // A bullet or a "**Label:**" line with nothing after it is a
                // field the user skipped, not something to print.
                return preg_match('/^[-*]\s*(\*\*[^*]+:?\*\*)?\s*:?\s*$/', $trimmed) === 1
                    || preg_match('/^\*\*[^*]+:\*\*\s*$/', $trimmed) === 1;
            });

        return trim(preg_replace("/\n{3,}/", "\n\n", $lines->implode("\n")));
    }

    /**
     * Renders the stored body to HTML for screen and print.
     *
     * A deliberately small syntax — headings, bullets, bold, paragraphs — so
     * there is no markdown dependency and no path by which user text becomes
     * markup. Everything is escaped before any tag is introduced.
     */
    public function toHtml(string $body, ?BusinessDocument $document = null): string
    {
        /*
         * A body the rich editor wrote is already HTML; re-sanitize on the
         * way out (defence in depth — the save path sanitizes too) and pass
         * it through, so the show screen and the print/PDF views render it
         * unchanged. Everything else is the plain template prose this method
         * has always converted.
         *
         * $document, when given, additionally resolves any field-chip spans
         * against whatever ERP record it is linked to — see
         * resolveFieldChips(). Optional: a caller with only a body string in
         * hand (Compose's live preview, ahead of the document existing) gets
         * the chip's last-rendered text, exactly like before this existed.
         */
        if (str_starts_with(trim($body), '<')) {
            $clean = app(Documents\HtmlSanitizer::class)->clean($body);

            return $document === null ? $clean : $this->resolveFieldChips($clean, $document);
        }

        $blocks = preg_split("/\n\s*\n/", trim($body));
        $html = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            if (str_starts_with($block, '# ')) {
                $html[] = '<h1>'.$this->inline(substr($block, 2)).'</h1>';

                continue;
            }

            if (str_starts_with($block, '## ')) {
                $html[] = '<h2>'.$this->inline(substr($block, 3)).'</h2>';

                continue;
            }

            if (str_starts_with($block, '- ')) {
                $items = collect(explode("\n", $block))
                    ->map(fn (string $line) => ltrim(trim($line), '- '))
                    ->filter()
                    ->map(fn (string $line) => '<li>'.$this->inline($line).'</li>')
                    ->implode('');

                $html[] = "<ul>{$items}</ul>";

                continue;
            }

            $html[] = '<p>'.nl2br($this->inline($block)).'</p>';
        }

        return implode("\n", $html);
    }

    /** Escapes first, then applies the one inline form the syntax allows. */
    protected function inline(string $text): string
    {
        return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e($text));
    }

    /**
     * Field chips (`<span data-token="customer.name">…</span>`) carry only
     * the token, not the value — resolving it here is what makes a chip
     * "living" per §3.3 of the master spec, rather than text frozen at the
     * moment it was inserted. A token that resolves to nothing (record
     * unlinked, or its provider has nothing to say) keeps its already
     * rendered text: a document mid-edit should never show a blank hole
     * where a value used to be.
     */
    protected function resolveFieldChips(string $html, BusinessDocument $document): string
    {
        if (! str_contains($html, 'data-token')) {
            return $html;
        }

        $context = $this->liveContextFor($document);

        if ($context === []) {
            return $html;
        }

        $values = $this->automaticValues($document->company, $context);

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return $html;
        }

        foreach (iterator_to_array($doc->getElementsByTagName('span')) as $span) {
            if (! $span instanceof DOMElement || ! $span->hasAttribute('data-token')) {
                continue;
            }

            $token = $span->getAttribute('data-token');

            if (! array_key_exists($token, $values)) {
                continue;
            }

            foreach (iterator_to_array($span->childNodes) as $child) {
                $span->removeChild($child);
            }

            $span->appendChild($doc->createTextNode((string) $values[$token]));
        }

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Freezes a draft: permanent reference, content hash, verification token.
     *
     * Mirrors DocumentIssuer for the sales ledger — same guarantees, same single
     * path in and out of the immutable state.
     */
    /**
     * Put a draft to the approval workflow before it is issued.
     *
     * Approval is advice sought before issuing, not a gate on it — a business
     * that wants every letter signed off can rely on this path, and one that
     * does not can issue directly, exactly as before. The verdict comes back
     * through the engine (TranslateDocumentWorkflowEvents restates it as
     * document.approved / document.rejected for the rules screens).
     */
    public function submitForApproval(BusinessDocument $document, User $submitter): WorkflowInstance
    {
        if (! $document->isDraft()) {
            throw new RuntimeException('Only a draft can be sent for approval. This one is already '.$document->status.'.');
        }

        if ($document->isAwaitingApproval()) {
            throw new RuntimeException('This document is already with somebody for a decision.');
        }

        $workflow = Workflow::defaultFor(BusinessDocument::class);

        if ($workflow === null) {
            throw new RuntimeException(
                'No approval workflow is set up for documents. Define one on the approval paths screen, or issue it directly.'
            );
        }

        return app(WorkflowEngine::class)->start($document, $workflow, $submitter);
    }

    public function issue(BusinessDocument $document, User $user): BusinessDocument
    {
        if (! $document->isDraft()) {
            throw new RuntimeException(sprintf(
                '%s is already %s and cannot be issued again.',
                $document->reference ?? $document->title,
                $document->status,
            ));
        }

        // Not a demand that approval happen — only that one already asked
        // for is answered before the document becomes immutable.
        if ($document->isAwaitingApproval()) {
            throw new RuntimeException(
                'This document is with somebody for approval. Let that finish before issuing it.'
            );
        }

        return DB::transaction(function () use ($document, $user) {
            // Respects whatever the business has configured for this kind —
            // its own prefix, the shared DOC series, or no number at all.
            // See DocumentNumbers::nextForBusinessDocumentKind().
            $document->reference ??= $this->numbers->nextForBusinessDocumentKind($document->kind);
            $document->status = 'issued';
            $document->issued_at = now();
            $document->issued_by = $user->id;
            $document->save();

            $document->refresh();
            $document->forceFill([
                'content_hash' => hash('sha256', $document->canonicalPayload()),
            ])->saveQuietly();

            $token = VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => BusinessDocument::class,
                'subject_id' => $document->id,
            ]);

            $document->forceFill(['verification_token_id' => $token->id])->saveQuietly();

            // §59's name for this moment — the draft becomes the permanent,
            // numbered record a rule can act on (file it, notify a customer,
            // start a countdown).
            $document->emitDomainEvent('document.published');

            return $document;
        });
    }

    /**
     * A fresh draft that starts from an existing document's content.
     *
     * The only route by which a "duplicate" document may exist — everything
     * that makes a BusinessDocument a BusinessDocument (id, numbering,
     * versioning, hash) is produced by BusinessDocument::create() /
     * DocumentVersioner exactly as it would be for a document typed in by
     * hand. Nothing here reaches into another table.
     *
     * Deliberately narrow about what carries over. Content (template,
     * title, recipient, fields, body, kind) is what somebody duplicating a
     * document is asking to reuse. Filing metadata — folder, department,
     * owner, security, tags, expiry — is reset rather than copied, because
     * "start a new one like this" is a statement about the words, not a
     * request to also inherit where the original happened to be filed.
     * Comments, versions and shares are relations of the *original* row and
     * are never touched: a new id means there is nothing to copy them onto
     * that would still mean the same thing.
     */
    public function duplicate(BusinessDocument $source, User $user): BusinessDocument
    {
        return BusinessDocument::create([
            'template' => $source->template,
            'title' => 'Copy of '.$source->title,
            'recipient' => $source->recipient,
            'fields' => $source->fields,
            'body' => $source->body,
            'kind' => $source->kind,
            'language' => $source->language,
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
    }

    public function void(BusinessDocument $document, User $user, ?string $reason = null): BusinessDocument
    {
        if (! $document->isIssued()) {
            throw new RuntimeException('Only an issued document can be voided.');
        }

        return DB::transaction(function () use ($document, $user, $reason) {
            $document->forceFill([
                'status' => 'void',
                // The reason lives on the document rather than the token: the
                // token only ever answers "is this still valid", and the reason
                // is nobody's business but the company's.
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => $user->id,
            ])->save();

            // A scan of the paper already handed over must report it as void
            // rather than valid.
            $document->verificationToken?->forceFill(['revoked_at' => now()])->save();

            // The one other thing that can happen to an issued document. A
            // business that reacts to `document.published` almost certainly
            // wants to react to its undoing too.
            $document->emitDomainEvent('document.voided', ['reason' => $reason]);

            return $document;
        });
    }
}
