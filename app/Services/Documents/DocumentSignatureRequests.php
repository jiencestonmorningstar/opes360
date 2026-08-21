<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentSignature;
use App\Models\VerificationToken;
use App\Notifications\SignatureRequested;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Requesting and collecting signatures.
 *
 * On completion this mints a VerificationToken exactly the way DocumentIssuer
 * and every other issuing path in the product already does — the existing QR
 * verification infrastructure, not a second one built for signatures alone.
 * §19 of the master spec says so explicitly: integrate with what already
 * verifies a document, don't build a competing identity/verification system.
 */
class DocumentSignatureRequests
{
    public const MODES = ['sequential', 'parallel'];

    /**
     * @param  array<int, array{name: string, email: string, user_id?: ?int, anchor_id?: ?string}>  $signers
     */
    public function request(
        BusinessDocument $document,
        array $signers,
        string $mode = 'parallel',
    ): Collection {
        if ($signers === []) {
            throw new RuntimeException('A signature round needs at least one signer.');
        }

        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException("Unknown signature mode [{$mode}].");
        }

        if ($document->signatures()->pending()->exists()) {
            throw new RuntimeException('This document already has a signature round in progress.');
        }

        $document->forceFill(['signature_mode' => $mode])->save();

        /*
         * 'status' set explicitly rather than left to the migration's column
         * default. create() does not re-fetch the row afterwards, so a
         * database-level default never makes it into the in-memory model —
         * the instance this method hands back would read status as null even
         * though the actual row says 'pending', and the very next isPending()
         * check on it would be wrong.
         */
        $created = collect($signers)->values()->map(fn (array $signer, int $index) => BusinessDocumentSignature::create([
            'business_document_id' => $document->id,
            'order' => $index + 1,
            'signer_name' => $signer['name'],
            'signer_email' => $signer['email'],
            'signer_user_id' => $signer['user_id'] ?? null,
            // Where in the body this signature belongs, when it belongs
            // anywhere in particular. Null signs the document as a whole,
            // which is what every signature did before §19's block placement
            // and remains the ordinary case.
            'anchor_id' => $signer['anchor_id'] ?? null,
            'signing_token' => BusinessDocumentSignature::newSigningToken(),
            'status' => 'pending',
        ]));

        // The moment a signature round opens — distinct from document.signed,
        // which is the round completing. A rule that wants to chase a slow
        // signer needs to know a request went out in the first place.
        $document->emitDomainEvent('document.signature.requested', [
            'mode' => $mode,
            'signer_count' => $created->count(),
        ]);

        // Everyone who can act right now gets mailed their link — parallel
        // means everyone, sequential means only whoever is first. Nothing
        // sent this before the notification existed; a signer had to
        // somehow already know their own signing link.
        $created
            ->filter(fn (BusinessDocumentSignature $signature) => ! $this->isBlockedBySequence($signature))
            ->each(fn (BusinessDocumentSignature $signature) => $this->notifyRequested($signature, $document));

        return $created;
    }

    public function sign(BusinessDocumentSignature $signature, ?string $ipAddress = null): BusinessDocumentSignature
    {
        if (! $signature->isPending()) {
            throw new RuntimeException('This signature has already been dealt with.');
        }

        if ($this->isBlockedBySequence($signature)) {
            throw new RuntimeException('It is not this signer\'s turn yet.');
        }

        $signature->update([
            'status' => 'signed',
            'signed_at' => now(),
            'ip_address' => $ipAddress,
        ]);

        $document = $signature->document;

        if ($this->isFullySigned($document)) {
            $this->complete($document);
        } elseif ($document->signature_mode === 'sequential') {
            // The signer this one was blocking can now act — a sequential
            // round with nobody telling person #2 their turn arrived would
            // sit exactly as stuck as one with no notifications at all.
            $next = $document->signatures()->pending()->orderBy('order')->first();

            if ($next !== null) {
                $this->notifyRequested($next, $document);
            }
        }

        return $signature->fresh();
    }

    public function decline(BusinessDocumentSignature $signature, string $reason): BusinessDocumentSignature
    {
        if (! $signature->isPending()) {
            throw new RuntimeException('This signature has already been dealt with.');
        }

        $signature->update([
            'status' => 'declined',
            'declined_at' => now(),
            'declined_reason' => $reason,
        ]);

        return $signature->fresh();
    }

    /** @return array{status: string, signed: int, total: int} */
    public function status(BusinessDocument $document): array
    {
        $signatures = $document->signatures;

        if ($signatures->isEmpty()) {
            return ['status' => 'not_requested', 'signed' => 0, 'total' => 0];
        }

        $status = match (true) {
            $signatures->contains('status', 'declined') => 'declined',
            $signatures->every(fn ($s) => $s->status === 'signed') => 'fully_signed',
            $signatures->contains('status', 'signed') => 'partially_signed',
            default => 'pending',
        };

        return [
            'status' => $status,
            'signed' => $signatures->where('status', 'signed')->count(),
            'total' => $signatures->count(),
        ];
    }

    /**
     * In a sequential round, a signer may act only once everybody ordered
     * before them has signed. A parallel round has no such gate.
     */
    protected function notifyRequested(BusinessDocumentSignature $signature, BusinessDocument $document): void
    {
        $company = $document->company;

        if ($company === null) {
            return;
        }

        Notification::route('mail', $signature->signer_email)
            ->notify(new SignatureRequested($signature, $company));
    }

    protected function isBlockedBySequence(BusinessDocumentSignature $signature): bool
    {
        if ($signature->document->signature_mode !== 'sequential') {
            return false;
        }

        return $signature->document->signatures()
            ->where('order', '<', $signature->order)
            ->where('status', '!=', 'signed')
            ->exists();
    }

    protected function isFullySigned(BusinessDocument $document): bool
    {
        return $document->signatures()->where('status', '!=', 'signed')->doesntExist();
    }

    /**
     * Mints a verification token the same way DocumentIssuer already does for
     * an issued document — not a second verification mechanism, the same one.
     */
    protected function complete(BusinessDocument $document): void
    {
        if ($document->verification_token_id !== null) {
            $document->emitDomainEvent('document.signed');

            return;
        }

        $token = VerificationToken::create([
            'token' => VerificationToken::newToken(),
            'subject_type' => BusinessDocument::class,
            'subject_id' => $document->id,
        ]);

        $document->forceFill(['verification_token_id' => $token->id])->saveQuietly();

        $document->emitDomainEvent('document.signed');
    }
}
