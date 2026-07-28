<?php

namespace App\Services;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\User;
use App\Models\VerificationToken;
use App\Support\DocumentTemplates;
use Carbon\CarbonImmutable;
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
    public function __construct(protected DocumentNumbers $numbers) {}

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
     * @param  array<string, mixed>  $fields
     */
    public function merge(string $templateKey, array $fields, Company $company): string
    {
        $template = DocumentTemplates::find($templateKey);

        if ($template === null) {
            throw new RuntimeException("Unknown template [{$templateKey}].");
        }

        $values = $this->automaticValues($company)
            + $this->presentableFields($template, $fields);

        $body = $this->resolveOptionalSegments($this->dedent($template['body']), $values);

        $body = preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
            fn (array $m) => (string) ($values[$m[1]] ?? ''),
            $body,
        );

        return $this->tidy($body);
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
    protected function automaticValues(Company $company): array
    {
        return [
            'company.name' => (string) $company->name,
            'company.address' => $this->addressLine($company),
            'company.email' => (string) ($company->email ?? ''),
            'company.phone' => (string) data_get($company->phones, 0, ''),
            'today' => now()->format('j F Y'),
        ];
    }

    /** Matches how the letterhead and public profile compose an address. */
    protected function addressLine(Company $company): string
    {
        return collect([
            $company->address_line1,
            $company->address_line2,
            $company->city,
            $company->region,
            $company->country,
        ])->filter()->implode(', ');
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
    public function toHtml(string $body): string
    {
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
     * Freezes a draft: permanent reference, content hash, verification token.
     *
     * Mirrors DocumentIssuer for the sales ledger — same guarantees, same single
     * path in and out of the immutable state.
     */
    public function issue(BusinessDocument $document, User $user): BusinessDocument
    {
        if (! $document->isDraft()) {
            throw new RuntimeException(sprintf(
                '%s is already %s and cannot be issued again.',
                $document->reference ?? $document->title,
                $document->status,
            ));
        }

        return DB::transaction(function () use ($document, $user) {
            $document->reference ??= $this->numbers->nextBusinessDocument();
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

            return $document;
        });
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

            return $document;
        });
    }
}
