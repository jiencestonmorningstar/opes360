<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single path from draft to issued.
 *
 * Issuing is the moment a document becomes immutable, so everything that must be
 * true of an immutable document happens here in one transaction: final number,
 * frozen dates, content hash, verification token. There is deliberately no other
 * code path that flips a document out of draft.
 */
class DocumentIssuer
{
    public function __construct(protected DocumentNumbers $numbers) {}

    /**
     * @param  string|null  $number  A number already put on paper by an offline
     *                               device. The caller is responsible for having
     *                               verified it against that device's lease; the
     *                               unique index on (company, type, number) is
     *                               the last line of defence if it has not.
     */
    public function issue(Document $document, User $user, ?string $number = null): Document
    {
        if ($document->status !== DocumentStatus::Draft) {
            throw new RuntimeException(sprintf(
                'Document %s is already %s and cannot be issued again.',
                $document->number ?? $document->id,
                $document->status->value,
            ));
        }

        return DB::transaction(function () use ($document, $user, $number) {
            $document->issue_date ??= now()->toDateString();
            $document->number ??= $number ?? $this->numbers->next($document->type);
            $document->status = DocumentStatus::Issued;
            $document->issued_at = now();
            $document->issued_by = $user->id;
            $document->save();

            // Hash from a refreshed model so stored casts ("250.00"), not raw PHP
            // values (250), are what verification recomputes against later.
            $document->refresh()->load('lines');
            $document->forceFill([
                'content_hash' => hash('sha256', $document->canonicalPayload()),
            ])->saveQuietly();

            $token = VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => Document::class,
                'subject_id' => $document->id,
            ]);

            $document->forceFill(['verification_token_id' => $token->id])->saveQuietly();

            // The sale enters the books here rather than at draft: nothing is
            // owed until a document is issued, and a draft can still change.
            // Wrapped so a half-configured chart of accounts cannot be the
            // reason an invoice fails with a customer standing at the counter.
            $events = app(RecordsBusinessEvents::class);
            $company = app(CurrentCompany::class)->get();

            if ($company !== null) {
                $events->recordQuietly(fn () => $events->recordIssuedDocument($document, $company, $user));
            }

            return $document;
        });
    }
}
