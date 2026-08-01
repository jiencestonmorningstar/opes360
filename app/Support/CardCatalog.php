<?php

namespace App\Support;

/**
 * The industry business-card catalogue — every design from the template
 * sheets in docs/image templates (cards2 … cards18), reproduced as data.
 *
 * The sheets fall into two structural families, so the print view carries two
 * parameterised skeletons and each design here is a configuration of one:
 *
 *  - 'spotlight' (cards2, cards3, cards5): sector badge + wordmark front with
 *    a big sector motif; solid-colour back holding the QR and a row of
 *    sector-specific feature icons.
 *  - 'pro' (cards6 … cards18): brand-lockup front with a curved accent wedge
 *    and the QR on the face; dark back with a two-line headline, a service
 *    list, the QR and the company website.
 *
 * The sample brands on the sheets (LEX PARTNERS, FOCUS STUDIO, …) are
 * placeholder content: on a real card the company's own name, motto, industry
 * and website take their place. The stock photos baked into some mockups are
 * replaced by the sector's motif glyph — photography is the one thing a CSS
 * reproduction cannot carry.
 */
class CardCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function designs(): array
    {
        return [
            // ── cards2 — sector spotlight set ────────────────────────────
            'food-01' => [
                'label' => 'Bistro', 'sector' => 'Restaurant & Food', 'family' => 'spotlight',
                'face' => '#141414', 'ink' => '#ffffff', 'muted' => '#9ca3af',
                'accent' => '#f97316', 'accent2' => '#ea580c',
                'back' => '#181818', 'backInk' => '#ffffff',
                'badge' => 'chef', 'watermark' => 'fork',
                'features' => [['menu', 'Menu'], ['bag', 'Order'], ['star', 'Reviews'], ['pin', 'Location']],
            ],
            'pharma-01' => [
                'label' => 'Pharmacie', 'sector' => 'Health & Pharmacy', 'family' => 'spotlight',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#16a34a', 'accent2' => '#15803d',
                'back' => 'linear-gradient(150deg, #1f9d45, #166534)', 'backInk' => '#ffffff',
                'badge' => 'cross', 'watermark' => 'cross',
                'features' => [['pill', 'Medicines'], ['bag', 'Refill'], ['gear', 'Services'], ['pin', 'Location']],
            ],
            'build-01' => [
                'label' => 'Chantier', 'sector' => 'Construction', 'family' => 'spotlight',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#2563eb', 'accent2' => '#1e40af',
                'back' => 'linear-gradient(150deg, #1e40af, #172554)', 'backInk' => '#ffffff',
                'badge' => 'buildings', 'watermark' => 'buildings',
                'features' => [['clipboard', 'Projects'], ['gear', 'Services'], ['image', 'Gallery'], ['chat', 'Contact']],
            ],
            'beauty-01' => [
                'label' => 'Rose', 'sector' => 'Beauty & Salon', 'family' => 'spotlight',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#ec4899', 'accent2' => '#be185d',
                'back' => 'linear-gradient(150deg, #ec4899, #9d174d)', 'backInk' => '#ffffff',
                'badge' => 'scissors', 'watermark' => 'scissors',
                'features' => [['scissors', 'Services'], ['calendar', 'Booking'], ['image', 'Gallery'], ['pin', 'Location']],
            ],
            'auto-01' => [
                'label' => 'Garage', 'sector' => 'Automotive', 'family' => 'spotlight',
                'face' => '#131313', 'ink' => '#ffffff', 'muted' => '#9ca3af',
                'accent' => '#dc2626', 'accent2' => '#991b1b',
                'back' => '#161616', 'backInk' => '#ffffff',
                'badge' => 'car', 'watermark' => 'gear',
                'features' => [['wrench', 'Services'], ['calendar', 'Bookings'], ['image', 'Gallery'], ['pin', 'Location']],
            ],
            'fashion-01' => [
                'label' => 'Boutique', 'sector' => 'Fashion', 'family' => 'spotlight',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#7c3aed', 'accent2' => '#5b21b6',
                'back' => 'linear-gradient(150deg, #7c3aed, #4c1d95)', 'backInk' => '#ffffff',
                'badge' => 'dress', 'watermark' => 'dress',
                'features' => [['grid', 'Collections'], ['bag', 'Orders'], ['image', 'Gallery'], ['pin', 'Location']],
            ],
            'edu-01' => [
                'label' => 'Académie', 'sector' => 'Education', 'family' => 'spotlight',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#0d9488', 'accent2' => '#115e59',
                'back' => 'linear-gradient(150deg, #0d9488, #134e4a)', 'backInk' => '#ffffff',
                'badge' => 'grad', 'watermark' => 'grad',
                'features' => [['book', 'Courses'], ['clipboard', 'Enroll'], ['users', 'Students'], ['pin', 'Location']],
            ],
            'estate-01' => [
                'label' => 'Immobilier', 'sector' => 'Real Estate', 'family' => 'spotlight',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#b45309', 'accent2' => '#92400e',
                'back' => 'linear-gradient(150deg, #b45309, #713f12)', 'backInk' => '#ffffff',
                'badge' => 'home', 'watermark' => 'home',
                'features' => [['home', 'Properties'], ['key', 'Rent'], ['tag', 'Buy'], ['chat', 'Contact']],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function design(string $key): ?array
    {
        return self::designs()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::designs());
    }

    /** Designs grouped by sector, preserving catalogue order. */
    public static function bySector(): array
    {
        $out = [];
        foreach (self::designs() as $key => $design) {
            $out[$design['sector']][$key] = $design;
        }

        return $out;
    }

    /**
     * The sector whose designs best fit a company's declared industry — the
     * "recommended for you" hook. Null when nothing matches; the picker then
     * simply shows the catalogue unranked.
     */
    public static function sectorFor(?string $industry): ?string
    {
        if ($industry === null || trim($industry) === '') {
            return null;
        }

        $needle = mb_strtolower($industry);

        $synonyms = [
            'Restaurant & Food' => ['restaurant', 'food', 'catering', 'café', 'cafe', 'bakery', 'bar', 'chef', 'cuisine'],
            'Health & Pharmacy' => ['health', 'pharma', 'clinic', 'medical', 'hospital', 'doctor', 'santé', 'dental'],
            'Construction' => ['construction', 'building', 'btp', 'civil', 'contractor', 'architecture', 'engineering'],
            'Beauty & Salon' => ['beauty', 'salon', 'spa', 'hair', 'barber', 'coiffure', 'esthéti', 'makeup', 'nails'],
            'Automotive' => ['auto', 'car', 'garage', 'mechanic', 'vehicle', 'moto'],
            'Fashion' => ['fashion', 'boutique', 'clothing', 'tailor', 'couture', 'textile', 'mode'],
            'Education' => ['education', 'school', 'training', 'academy', 'college', 'université', 'formation', 'tutor'],
            'Real Estate' => ['real estate', 'estate', 'property', 'immobili', 'realty', 'land', 'housing'],
        ];

        foreach ($synonyms as $sector => $words) {
            foreach ($words as $word) {
                if (str_contains($needle, $word)) {
                    return $sector;
                }
            }
        }

        return null;
    }

    /**
     * Small inline stroke glyphs (24×24 viewBox) shared by badges, feature
     * rows and watermarks — the print pipeline stays dependency-free.
     */
    public static function glyph(string $name): string
    {
        $paths = [
            'chef' => '<path d="M7 9a4 4 0 1 1 1-7.9 5 5 0 0 1 8 0A4 4 0 1 1 17 9v8H7z"/><path d="M7 20h10"/>',
            'cross' => '<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>',
            'buildings' => '<path d="M3 21V9l6-3v15M9 21V3l8 3v15M21 21V10l-4-1"/><path d="M0 21h24"/>',
            'scissors' => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M8.5 7.5 20 19M8.5 16.5 20 5"/>',
            'car' => '<path d="M5 11 7 6h10l2 5"/><path d="M3 16v-3.5A1.5 1.5 0 0 1 4.5 11h15a1.5 1.5 0 0 1 1.5 1.5V16"/><circle cx="7" cy="16" r="1.6"/><circle cx="17" cy="16" r="1.6"/>',
            'dress' => '<path d="M9 2v3l-4 8 3 9h8l3-9-4-8V2"/><path d="M9 5h6"/>',
            'grad' => '<path d="m12 3 10 5-10 5L2 8z"/><path d="M6 10.5V16c0 1.5 2.7 3 6 3s6-1.5 6-3v-5.5"/>',
            'home' => '<path d="m3 11 9-8 9 8"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
            'fork' => '<path d="M7 2v8M4 2v6a3 3 0 0 0 6 0V2M7 10v12"/><path d="M17 2c-2 2-2.5 5-2.5 8H17v12"/>',
            'pill' => '<rect x="2" y="9" width="20" height="7" rx="3.5" transform="rotate(-35 12 12.5)"/><path d="m8.5 8.2 7 5"/>',
            'gear' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
            'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m3 17 5-4 4 3 4-4 5 5"/>',
            'chat' => '<path d="M21 12a8 8 0 0 1-8 8H4l1.5-3A8 8 0 1 1 21 12z"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'wrench' => '<path d="M14.5 6.5a4.5 4.5 0 0 0 6 6L13 20a2.1 2.1 0 0 1-3-3l7.5-7.5a4.5 4.5 0 0 0-3-3z"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'bag' => '<path d="M6 8h12l1.5 12a1.8 1.8 0 0 1-1.8 2H6.3a1.8 1.8 0 0 1-1.8-2z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/>',
            'book' => '<path d="M4 4a2 2 0 0 1 2-2h14v18H6a2 2 0 0 0-2 2z"/><path d="M20 16H6a2 2 0 0 0-2 2"/>',
            'users' => '<circle cx="9" cy="8" r="3.4"/><path d="M2.5 21c0-3.6 2.9-5.6 6.5-5.6s6.5 2 6.5 5.6"/><path d="M15.5 5.2a3.4 3.4 0 1 1 0 5.7M17 15.6c2.6.5 4.5 2.3 4.5 5.4"/>',
            'clipboard' => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4a3 3 0 0 1 6 0M9 11h6M9 15h4"/>',
            'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m11 12 9.5-9.5M17 6l3 3"/>',
            'tag' => '<path d="M3 3h8l10 10-8 8L3 11z"/><circle cx="8" cy="8" r="1.6"/>',
            'star' => '<path d="m12 2 3 6.6 7 .8-5.2 4.8 1.4 7-6.2-3.6L5.8 21l1.4-7L2 9.4l7-.8z"/>',
            'pin' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h10"/>',
        ];

        $body = $paths[$name] ?? $paths['star'];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$body.'</svg>';
    }
}
