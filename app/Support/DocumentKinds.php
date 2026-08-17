<?php

namespace App\Support;

use App\Services\Documents\DocumentTypeRegistry;

/**
 * What kind of thing a document is.
 *
 * Deliberately absent: invoice, quotation, proforma, receipt, purchase order.
 * Those are transactional records owned by the sales module and they live in
 * `documents`, not here. A Documents copy of an invoice would be a second
 * source of truth for money, which is the one thing this module must never
 * become — it references those records instead, through relations.
 *
 * The catalogue is a flat map rather than an enum because other modules will
 * want to register their own kinds later, and an enum cannot be extended at
 * runtime.
 */
class DocumentKinds
{
    public const FALLBACK_LABEL = 'Document';

    /**
     * The built-in catalogue plus whatever other modules have registered
     * through DocumentTypeRegistry. A built-in key always wins a collision —
     * built-ins are listed second in the merge below, on purpose — so a
     * registered kind can never silently reassign what one already means.
     *
     * @return array<string, array{label: string, group: string}>
     */
    public static function all(): array
    {
        return self::builtIn() + DocumentTypeRegistry::all();
    }

    /** @return array<string, array{label: string, group: string}> */
    protected static function builtIn(): array
    {
        return [
            // General
            'document' => ['label' => 'Document', 'group' => 'General'],
            'letter' => ['label' => 'Letter', 'group' => 'General'],
            'memo' => ['label' => 'Memo', 'group' => 'General'],
            'report' => ['label' => 'Report', 'group' => 'General'],
            'policy' => ['label' => 'Policy', 'group' => 'General'],
            'procedure' => ['label' => 'Procedure', 'group' => 'General'],
            'minutes' => ['label' => 'Minutes', 'group' => 'General'],
            'proposal' => ['label' => 'Proposal', 'group' => 'General'],

            // Legal
            'contract' => ['label' => 'Contract', 'group' => 'Legal'],
            'agreement' => ['label' => 'Agreement', 'group' => 'Legal'],
            'amendment' => ['label' => 'Amendment', 'group' => 'Legal'],
            'nda' => ['label' => 'Non-disclosure agreement', 'group' => 'Legal'],
            'legal_notice' => ['label' => 'Legal notice', 'group' => 'Legal'],
            'resolution' => ['label' => 'Resolution', 'group' => 'Legal'],
            'declaration' => ['label' => 'Declaration', 'group' => 'Legal'],

            // People
            'employment_contract' => ['label' => 'Employment contract', 'group' => 'People'],
            'offer_letter' => ['label' => 'Offer letter', 'group' => 'People'],
            'warning_letter' => ['label' => 'Warning letter', 'group' => 'People'],
            'promotion_letter' => ['label' => 'Promotion letter', 'group' => 'People'],
            'termination_letter' => ['label' => 'Termination letter', 'group' => 'People'],
            'performance_review' => ['label' => 'Performance review', 'group' => 'People'],
            'certificate' => ['label' => 'Certificate', 'group' => 'People'],

            // Procurement
            'supplier_agreement' => ['label' => 'Supplier agreement', 'group' => 'Procurement'],
            'tender' => ['label' => 'Tender document', 'group' => 'Procurement'],
            'evaluation' => ['label' => 'Evaluation', 'group' => 'Procurement'],

            // Finance
            'financial_report' => ['label' => 'Financial report', 'group' => 'Finance'],
            'audit_document' => ['label' => 'Audit document', 'group' => 'Finance'],
            'budget' => ['label' => 'Budget', 'group' => 'Finance'],
            'supporting_document' => ['label' => 'Supporting document', 'group' => 'Finance'],

            // Operations
            'specification' => ['label' => 'Specification', 'group' => 'Operations'],
            'meeting_minutes' => ['label' => 'Meeting minutes', 'group' => 'Operations'],
            'progress_report' => ['label' => 'Progress report', 'group' => 'Operations'],
            'acceptance' => ['label' => 'Acceptance document', 'group' => 'Operations'],
        ];
    }

    /**
     * Never throws.
     *
     * A document whose kind was renamed or removed still has to appear in a
     * list; losing a row from a search because its label could not be resolved
     * would be a far worse failure than showing it generically.
     */
    public static function label(?string $key): string
    {
        return self::all()[$key]['label'] ?? self::FALLBACK_LABEL;
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::all());
    }

    /** @return array<string, array<string, string>> group => [key => label] */
    public static function groups(): array
    {
        $out = [];

        foreach (self::all() as $key => $kind) {
            $out[$kind['group']][$key] = $kind['label'];
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * How confidential a document is. Ordered, so a policy can ask "at least
     * this sensitive" rather than matching each level by name.
     *
     * @return array<string, array{label: string, rank: int}>
     */
    public static function securityLevels(): array
    {
        return [
            'public' => ['label' => 'Public', 'rank' => 0],
            'internal' => ['label' => 'Internal', 'rank' => 1],
            'confidential' => ['label' => 'Confidential', 'rank' => 2],
            'restricted' => ['label' => 'Restricted', 'rank' => 3],
        ];
    }

    public static function securityRank(?string $level): int
    {
        return self::securityLevels()[$level]['rank'] ?? 1;
    }
}
