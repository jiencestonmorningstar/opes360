<?php

namespace App\Support;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentShare;
use App\Models\Company;

/**
 * §2.17 — what a printed page must say about itself.
 *
 * One pipeline: these are answers for the existing print and share views to
 * overlay in CSS, not a second renderer. Two kinds of mark, deliberately kept
 * apart because they answer different questions:
 *
 * - The STATUS mark (DRAFT / VOID / COPY) speaks to validity. It is not
 *   configurable and never will be: a draft that can print clean is
 *   indistinguishable from an issued document, and a voided one that prints
 *   clean is a live instrument again. The one printout a business would want
 *   the mark off is precisely the one it must be on.
 *
 * - The CONFIDENTIAL footer speaks to provenance — whose paper this is and
 *   whose hands it passed through — so a leaked photocopy names its source.
 *   That one is a per-company preference (default on), because some
 *   businesses have their own stationery conventions for it.
 */
class Watermarks
{
    /**
     * The diagonal mark for this document's real status, or null for a clean
     * page. $isCopy is the share path: an issued document viewed through a
     * share link is a copy, never the original — but a draft or void stays
     * DRAFT or VOID there too, because COPY would upgrade its apparent
     * validity.
     */
    public static function statusMark(BusinessDocument $paper, bool $isCopy = false): ?string
    {
        return match (true) {
            $paper->status === 'void' => 'VOID',
            $paper->isDraft() => 'DRAFT',
            $isCopy => 'COPY',
            default => null,
        };
    }

    /**
     * "CONFIDENTIEL — {company} · {viewer} · {timestamp}" for confidential
     * and restricted papers, or null.
     *
     * $viewer is whoever this rendering is for: the signed-in user's name on
     * the authenticated print route, the share identity on the public share
     * route — never anything read from outside the paper's own tenant, so a
     * token-based share can never print another company's name.
     */
    public static function confidentialFooter(BusinessDocument $paper, ?Company $company, string $viewer): ?string
    {
        if ($company === null || ! $paper->isConfidential()) {
            return null;
        }

        // Missing column or unsaved company reads as the default: on.
        if (! (bool) ($company->prints_confidential_footer ?? true)) {
            return null;
        }

        return sprintf(
            'CONFIDENTIEL — %s · %s · %s',
            $company->name,
            $viewer,
            now()->format('d/m/Y H:i'),
        );
    }

    /**
     * How an external copy names its recipient. The share has no account and
     * often no name, so the link itself is the identity: enough to trace a
     * leaked printout back to the specific link that produced it via the
     * share's access log, without printing a token somebody could reuse.
     */
    public static function shareIdentity(BusinessDocumentShare $share): string
    {
        return 'Lien '.substr($share->share_token, 0, 8);
    }
}
