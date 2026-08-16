<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentShare;
use App\Models\BusinessDocumentShareAccess;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * External links to a document — the ones a customer opens without an
 * account. See §21 of the master spec: expiry, password, view-only,
 * revocation, and an access log, never a raw storage URL.
 */
class DocumentSharing
{
    public function create(
        BusinessDocument $document,
        User $actor,
        ?Carbon $expiresAt = null,
        ?string $password = null,
        bool $allowDownload = true,
    ): BusinessDocumentShare {
        return BusinessDocumentShare::create([
            'business_document_id' => $document->id,
            'created_by' => $actor->id,
            'share_token' => BusinessDocumentShare::newShareToken(),
            'expires_at' => $expiresAt,
            'password_hash' => $password !== null ? Hash::make($password) : null,
            'allow_download' => $allowDownload,
        ]);
    }

    public function revoke(BusinessDocumentShare $share): BusinessDocumentShare
    {
        $share->update(['revoked_at' => now()]);

        return $share->fresh();
    }

    public function recordAccess(BusinessDocumentShare $share, ?string $ipAddress, ?string $userAgent): BusinessDocumentShareAccess
    {
        return BusinessDocumentShareAccess::create([
            'business_document_share_id' => $share->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
            'viewed_at' => now(),
        ]);
    }
}
