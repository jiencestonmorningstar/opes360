<?php

namespace App\Support;

/**
 * The six official-seal watermark designs — "look industry standard like
 * big companies... US all sectors seal, that style but not copy or
 * duplicate." Every design is a generic circular-seal grammar (concentric
 * rings, ring text, a central emblem built from basic shapes) rather than
 * any specific agency's mark — no eagle, no specific coat of arms, nothing
 * that reads as a reproduction of a real seal.
 *
 * Deliberately generative rather than a per-design SVG blob like
 * CardCatalog's card designs: a seal has to carry the *company's own* name
 * and registration text around the ring, so the data here is just which
 * emblem and ring layout a design uses — SealComposer does the drawing.
 */
class SealCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function designs(): array
    {
        return [
            'starburst' => [
                'label' => 'Starburst',
                'description' => 'A five-point star at the centre, double ring border — the classic notarial mark.',
                'emblem' => 'star',
                'rings' => 2,
            ],
            'laurel' => [
                'label' => 'Laurel',
                'description' => 'A laurel wreath framing your initials — the mark of an established institution.',
                'emblem' => 'laurel',
                'rings' => 2,
            ],
            'shield' => [
                'label' => 'Shield',
                'description' => 'A crest shield at the centre — trust and protection, the way a bank or insurer reads.',
                'emblem' => 'shield',
                'rings' => 1,
            ],
            'compass' => [
                'label' => 'Compass',
                'description' => 'A compass-rose emblem — reach and direction, suited to logistics and trade.',
                'emblem' => 'compass',
                'rings' => 2,
            ],
            'monogram' => [
                'label' => 'Monogram',
                'description' => 'Your initials, bold and centred — minimal, the way a law firm or bank seals a page.',
                'emblem' => 'monogram',
                'rings' => 1,
            ],
            'sunburst' => [
                'label' => 'Sunburst',
                'description' => 'Radiating rays behind a small crest — the bureau-seal look, formal and busy.',
                'emblem' => 'sunburst',
                'rings' => 3,
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::designs());
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::designs());
    }
}
