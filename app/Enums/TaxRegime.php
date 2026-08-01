<?php

namespace App\Enums;

/**
 * A Cameroonian business's régime d'imposition, which the DGI requires printed
 * on every invoice.
 *
 * The turnover bands below are the ones in force for Cameroon and are what
 * decides whether a business collects TVA at all:
 *
 *   Impôt libératoire   up to 10M FCFA   — does not collect TVA
 *   Régime simplifié    10M to 50M FCFA  — does not collect TVA, save for
 *                                          withholding at source
 *   Régime du réel      above 50M FCFA   — collects TVA
 *
 * `collectsVatByDefault()` only seeds the sensible answer when a business
 * picks its regime. The authority on whether TVA actually appears on an
 * invoice is the company's own `vat_registered` flag, because a business can
 * sit between regimes or hold a specific exemption, and the invoice has to
 * state what is true rather than what the band implies.
 */
enum TaxRegime: string
{
    case Liberatoire = 'liberatoire';
    case Simplifie = 'simplifie';
    case Reel = 'reel';
    case Exonere = 'exonere';

    public function label(): string
    {
        return match ($this) {
            self::Liberatoire => 'Impôt libératoire',
            self::Simplifie => 'Régime simplifié',
            self::Reel => 'Régime du réel',
            self::Exonere => 'Exonéré',
        };
    }

    /** The turnover band, for the settings screen. */
    public function turnoverBand(): string
    {
        return match ($this) {
            self::Liberatoire => 'Turnover up to 10M FCFA',
            self::Simplifie => 'Turnover 10M – 50M FCFA',
            self::Reel => 'Turnover above 50M FCFA',
            self::Exonere => 'Exempt from VAT by status or activity',
        };
    }

    public function collectsVatByDefault(): bool
    {
        return $this === self::Reel;
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
