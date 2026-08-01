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

            // ── cards3 — brand-lockup set: QR on the face, tagline back ──
            'coffee-01' => [
                'label' => 'Arabica', 'sector' => 'Restaurant & Food', 'family' => 'spotlight', 'variant' => 'brand',
                'face' => '#f6f0e7', 'ink' => '#2b1d12', 'muted' => '#8d7a66',
                'accent' => '#8b5a2b', 'accent2' => '#5e3a1c',
                'back' => 'linear-gradient(160deg, #4a2d1c, #2e1b10)', 'backInk' => '#f6ead9',
                'badge' => 'coffee', 'watermark' => 'coffee',
                'tagline' => ['Good Coffee', 'Good Day'], 'scan' => "Scan to view\nour menu",
                'features' => [['menu', 'Menu'], ['bag', 'Order Online'], ['star', 'Rewards'], ['pin', 'Location']],
            ],
            'fit-01' => [
                'label' => 'Vigueur', 'sector' => 'Fitness & Sport', 'family' => 'spotlight', 'variant' => 'brand',
                'face' => '#101010', 'ink' => '#ffffff', 'muted' => '#9ca3af',
                'accent' => '#a3e635', 'accent2' => '#65a30d',
                'back' => '#0d0d0d', 'backInk' => '#ffffff',
                'badge' => 'dumbbell', 'watermark' => 'dumbbell',
                'tagline' => ['Train Hard.', 'Live Strong.'], 'scan' => "Scan to view\nour programs",
                'features' => [['dumbbell', 'Workouts'], ['leaf', 'Nutrition'], ['users', 'Training'], ['star', 'Join Now']],
            ],
            'clinic-01' => [
                'label' => 'Clinique', 'sector' => 'Health & Pharmacy', 'family' => 'spotlight', 'variant' => 'brand',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#0d9488', 'accent2' => '#115e59',
                'back' => 'linear-gradient(160deg, #0d9488, #134e4a)', 'backInk' => '#ffffff',
                'badge' => 'cross', 'watermark' => 'cross',
                'tagline' => ['Your Health,', 'Our Priority'], 'scan' => "Scan to book\nan appointment",
                'features' => [['chat', 'Consultation'], ['flask', 'Lab Test'], ['pill', 'Pharmacy'], ['cross', 'Emergency']],
            ],
            'law-01' => [
                'label' => 'Cabinet', 'sector' => 'Legal', 'family' => 'spotlight', 'variant' => 'brand',
                'face' => '#ffffff', 'ink' => '#16283f', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#8f6f14',
                'back' => 'linear-gradient(160deg, #1e3a5f, #101f33)', 'backInk' => '#f4e9c8',
                'badge' => 'scales', 'watermark' => 'scales',
                'tagline' => ['Experience. Integrity.', 'Results.'], 'scan' => "Scan to view\nour services",
                'features' => [['briefcase', 'Corporate'], ['scales', 'Litigation'], ['chat', 'Consultation'], ['pin', 'Contact Us']],
            ],
            'estate-02' => [
                'label' => 'Verdure', 'sector' => 'Real Estate', 'family' => 'spotlight', 'variant' => 'brand',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#166534', 'accent2' => '#14532d',
                'back' => 'linear-gradient(160deg, #1c7a3f, #14532d)', 'backInk' => '#ffffff',
                'badge' => 'home', 'watermark' => 'home',
                'tagline' => ["We Don't Just Sell Homes,", 'We Help You Find Yours.'], 'scan' => "Scan to view\nproperties",
                'features' => [['home', 'Buy'], ['tag', 'Sell'], ['key', 'Rent'], ['chart', 'Invest']],
            ],
            'spa-01' => [
                'label' => 'Sérénité', 'sector' => 'Beauty & Salon', 'family' => 'spotlight', 'variant' => 'brand',
                'face' => '#faf7ff', 'ink' => '#3b2b58', 'muted' => '#8b7fa8',
                'accent' => '#8b5cf6', 'accent2' => '#6d28d9',
                'back' => 'linear-gradient(160deg, #c4b5fd, #8b5cf6)', 'backInk' => '#ffffff',
                'badge' => 'lotus', 'watermark' => 'lotus',
                'tagline' => ['Glow Naturally,', 'Live Beautifully'], 'scan' => "Scan to book\nyour session",
                'features' => [['lotus', 'Massage'], ['star', 'Facial'], ['scissors', 'Nails'], ['bag', 'Packages']],
            ],
            'school-01' => [
                'label' => 'Fondation', 'sector' => 'Education', 'family' => 'spotlight', 'variant' => 'brand',
                'face' => '#ffffff', 'ink' => '#1e3a8a', 'muted' => '#64748b',
                'accent' => '#1e40af', 'accent2' => '#f59e0b',
                'back' => 'linear-gradient(160deg, #1e3a8a, #172554)', 'backInk' => '#ffffff',
                'badge' => 'grad', 'watermark' => 'grad',
                'tagline' => ['Shaping Minds.', 'Building Futures.'], 'scan' => "Scan to learn\nmore",
                'features' => [['clipboard', 'Admissions'], ['book', 'Programs'], ['calendar', 'Events'], ['chat', 'Contact']],
            ],
            'travel-01' => [
                'label' => 'Horizon', 'sector' => 'Travel & Tours', 'family' => 'spotlight', 'variant' => 'brand',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#0891b2', 'accent2' => '#155e75',
                'back' => 'linear-gradient(160deg, #14a8c9, #0e7490)', 'backInk' => '#ffffff',
                'badge' => 'plane', 'watermark' => 'plane',
                'tagline' => ['Explore. Experience.', 'Enjoy.'], 'scan' => "Scan to plan\nyour trip",
                'features' => [['plane', 'Flights'], ['home', 'Hotels'], ['pin', 'Tours'], ['clipboard', 'Visa Support']],
            ],

            // ── cards5 — edge set: vertical sector word, five features ──
            'travel-02' => [
                'label' => 'Odyssée', 'sector' => 'Travel & Tours', 'family' => 'spotlight', 'variant' => 'edge',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#1d4ed8', 'accent2' => '#1e3a8a',
                'back' => 'linear-gradient(160deg, #1e40af, #172554)', 'backInk' => '#ffffff',
                'badge' => 'plane', 'watermark' => 'plane', 'vertical' => 'TRAVEL',
                'scan' => "Scan to explore\nour packages",
                'features' => [['plane', 'Flights'], ['home', 'Hotels'], ['pin', 'Tours'], ['clipboard', 'Visa'], ['shield', 'Insurance']],
            ],
            'food-02' => [
                'label' => 'Gourmet', 'sector' => 'Restaurant & Food', 'family' => 'spotlight', 'variant' => 'edge',
                'face' => '#131313', 'ink' => '#ffffff', 'muted' => '#9ca3af',
                'accent' => '#f97316', 'accent2' => '#c2410c',
                'back' => '#161616', 'backInk' => '#ffffff',
                'badge' => 'chef', 'watermark' => 'chef', 'vertical' => 'FOOD',
                'scan' => "Scan to view\nour menu",
                'features' => [['menu', 'Menu'], ['bag', 'Order'], ['chef', 'Catering'], ['calendar', 'Reservations'], ['star', 'Reviews']],
            ],
            'build-02' => [
                'label' => 'Ouvrage', 'sector' => 'Construction', 'family' => 'spotlight', 'variant' => 'edge',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#f59e0b', 'accent2' => '#b45309',
                'back' => '#131313', 'backInk' => '#ffffff',
                'badge' => 'buildings', 'watermark' => 'buildings', 'vertical' => 'BUILD',
                'scan' => "Scan to view\nour projects",
                'features' => [['clipboard', 'Projects'], ['gear', 'Services'], ['grid', 'Materials'], ['users', 'Team'], ['chat', 'Contact']],
            ],
            'health-01' => [
                'label' => 'Vitalité', 'sector' => 'Health & Pharmacy', 'family' => 'spotlight', 'variant' => 'edge',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#0d9488', 'accent2' => '#115e59',
                'back' => 'linear-gradient(160deg, #0f9c8f, #0f766e)', 'backInk' => '#ffffff',
                'badge' => 'cross', 'watermark' => 'cross', 'vertical' => 'HEALTH',
                'scan' => "Scan to book\nan appointment",
                'features' => [['chat', 'Consultation'], ['flask', 'Laboratory'], ['pill', 'Pharmacy'], ['cross', 'Emergency'], ['heart', 'Wellness']],
            ],
            'estate-03' => [
                'label' => 'Domaine', 'sector' => 'Real Estate', 'family' => 'spotlight', 'variant' => 'edge',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#15803d', 'accent2' => '#14532d',
                'back' => 'linear-gradient(160deg, #166534, #0f3d22)', 'backInk' => '#ffffff',
                'badge' => 'home', 'watermark' => 'home', 'vertical' => 'PROPERTY',
                'scan' => "Scan to view\nproperties",
                'features' => [['home', 'Buy'], ['tag', 'Sell'], ['key', 'Rent'], ['chart', 'Invest'], ['chat', 'Consultancy']],
            ],
            'beauty-02' => [
                'label' => 'Éclat', 'sector' => 'Beauty & Salon', 'family' => 'spotlight', 'variant' => 'edge',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#be185d', 'accent2' => '#831843',
                'back' => 'linear-gradient(160deg, #be185d, #831843)', 'backInk' => '#ffffff',
                'badge' => 'scissors', 'watermark' => 'lotus', 'vertical' => 'BEAUTY',
                'scan' => "Scan to book\nyour session",
                'features' => [['scissors', 'Hair'], ['star', 'Nails'], ['lotus', 'Makeup'], ['heart', 'Spa'], ['bag', 'Packages']],
            ],
            'edu-02' => [
                'label' => 'Campus', 'sector' => 'Education', 'family' => 'spotlight', 'variant' => 'edge',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#1d4ed8', 'accent2' => '#1e3a8a',
                'back' => 'linear-gradient(160deg, #1e40af, #1e3a8a)', 'backInk' => '#ffffff',
                'badge' => 'grad', 'watermark' => 'grad', 'vertical' => 'EDUCATION',
                'scan' => "Scan to visit\nour school",
                'features' => [['clipboard', 'Admissions'], ['book', 'Courses'], ['calendar', 'Events'], ['users', 'Students'], ['chat', 'Contact']],
            ],
            'auto-02' => [
                'label' => 'Moteur', 'sector' => 'Automotive', 'family' => 'spotlight', 'variant' => 'edge',
                'face' => '#131313', 'ink' => '#ffffff', 'muted' => '#9ca3af',
                'accent' => '#dc2626', 'accent2' => '#7f1d1d',
                'back' => '#151515', 'backInk' => '#ffffff',
                'badge' => 'car', 'watermark' => 'gear', 'vertical' => 'AUTO',
                'scan' => "Scan to book\na service",
                'features' => [['gear', 'Diagnostics'], ['wrench', 'Repair'], ['calendar', 'Maintenance'], ['disc', 'Tires'], ['drop', 'Oil Change']],
            ],

            // ── cards7 — Law Firm pro set ────────────────────────────────
            'law-02' => [
                'label' => 'Partenaires', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'swoop',
                'face' => '#ffffff', 'ink' => '#16283f', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#16283f',
                'back' => 'linear-gradient(160deg, #16283f, #0c1626)', 'backInk' => '#f2ead2',
                'badge' => 'scales', 'watermark' => 'scales',
                'headline' => ['Justice. Integrity.', 'Results.'],
                'services' => [['briefcase', 'Corporate Law'], ['scales', 'Litigation'], ['book', 'Legal Advisory'], ['clipboard', 'Contract Drafting'], ['buildings', 'Company Registration']],
            ],
            'law-03' => [
                'label' => 'Justus', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'facet',
                'face' => '#ffffff', 'ink' => '#3d0f1d', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#5d1626',
                'back' => 'linear-gradient(160deg, #5d1626, #380d17)', 'backInk' => '#f4e6d5',
                'badge' => 'buildings', 'watermark' => 'scales',
                'headline' => ['Your Law Partner.', 'Your Success.'],
                'services' => [['scales', 'Legal Representation'], ['chat', 'Dispute Resolution'], ['briefcase', 'Corporate Advisory'], ['home', 'Real Estate Law'], ['users', 'Family Law']],
            ],
            'law-04' => [
                'label' => 'Équité', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'pillar',
                'face' => '#121212', 'ink' => '#ffffff', 'muted' => '#9b9483',
                'accent' => '#c9a227', 'accent2' => '#8f6f14', 'chipInk' => '#171204',
                'back' => '#101010', 'backInk' => '#f0e9d8',
                'badge' => 'scales', 'watermark' => 'scales',
                'headline' => ['Experience. Integrity.', 'Excellence.'],
                'services' => [['scales', 'Litigation'], ['chat', 'Legal Consultancy'], ['briefcase', 'Business Law'], ['book', 'Intellectual Property'], ['users', 'Arbitration']],
            ],
            'law-05' => [
                'label' => 'Prima', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'swoop',
                'face' => '#ffffff', 'ink' => '#0f1b2d', 'muted' => '#64748b',
                'accent' => '#b8860b', 'accent2' => '#0f1b2d',
                'back' => 'linear-gradient(160deg, #12213a, #0a1322)', 'backInk' => '#f2ead2',
                'badge' => 'buildings', 'watermark' => 'scales',
                'headline' => ['Good lawyers know the law.', 'Great lawyers know their clients.'],
                'services' => [['shield', 'Criminal Defense'], ['scales', 'Civil Litigation'], ['briefcase', 'Corporate Law'], ['users', 'Employment Law'], ['chat', 'Legal Mediation']],
            ],
            'law-06' => [
                'label' => 'Victoria', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'facet',
                'face' => '#ffffff', 'ink' => '#0f3d22', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#14532d',
                'back' => 'linear-gradient(160deg, #14532d, #0a2d18)', 'backInk' => '#eaf4e2',
                'badge' => 'shield', 'watermark' => 'scales',
                'headline' => ['We protect rights.', 'We deliver justice.'],
                'services' => [['book', 'Legal Advisory'], ['clipboard', 'Contract Review'], ['home', 'Property Law'], ['plane', 'Immigration Law'], ['briefcase', 'Company Secretarial']],
            ],
            'law-07' => [
                'label' => 'Souverain', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'pillar',
                'face' => '#15181d', 'ink' => '#ffffff', 'muted' => '#8f8b80',
                'accent' => '#c9a227', 'accent2' => '#8f6f14', 'chipInk' => '#171204',
                'back' => '#101318', 'backInk' => '#f0e9d8',
                'badge' => 'shield', 'watermark' => 'scales',
                'headline' => ['The right advice today.', 'A better tomorrow.'],
                'services' => [['briefcase', 'Business Law'], ['chart', 'Tax Law'], ['buildings', 'Mergers & Acquisitions'], ['shield', 'Risk Management'], ['clipboard', 'Regulatory Compliance']],
            ],

            // ── cards14 — Law & Legal Services pro set ───────────────────
            'law-08' => [
                'label' => 'Juris', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'swoop',
                'face' => '#ffffff', 'ink' => '#16283f', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#1e3a5f',
                'back' => 'linear-gradient(160deg, #101f33, #0a1424)', 'backInk' => '#f2ead2',
                'badge' => 'shield', 'watermark' => 'scales',
                'headline' => ['Your rights. Our expertise.', 'Justice delivered.'],
                'services' => [['chat', 'Legal Consultation'], ['scales', 'Litigation & Representation'], ['clipboard', 'Contract Drafting'], ['briefcase', 'Corporate Law'], ['book', 'Legal Advisory']],
            ],
            'law-09' => [
                'label' => 'Dignitas', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'facet',
                'face' => '#ffffff', 'ink' => '#38101c', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#5d1626',
                'back' => '#3d1020', 'backInk' => '#f4e6d5',
                'badge' => 'buildings', 'watermark' => 'buildings',
                'headline' => ['Experience. Integrity.', 'Results.'],
                'services' => [['scales', 'Civil Litigation'], ['shield', 'Criminal Defense'], ['users', 'Family Law'], ['home', 'Real Estate Law'], ['clipboard', 'Legal Compliance']],
            ],
            'law-10' => [
                'label' => 'Dévoué', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'pillar',
                'face' => '#ffffff', 'ink' => '#0f2e1d', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#123524',
                'back' => 'linear-gradient(160deg, #123524, #0a2115)', 'backInk' => '#eaf4e2',
                'badge' => 'scales', 'watermark' => 'scales',
                'headline' => ['Dedicated to law.', 'Committed to you.'],
                'services' => [['book', 'Legal Advice'], ['chat', 'Dispute Resolution'], ['clipboard', 'Contracts & Agreements'], ['users', 'Employment Law'], ['star', 'Intellectual Property']],
            ],
            'law-11' => [
                'label' => 'Chambres', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'swoop',
                'face' => '#ffffff', 'ink' => '#1a1a1a', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#1a1a1a',
                'back' => '#141414', 'backInk' => '#f0e9d8',
                'badge' => 'scales', 'watermark' => 'scales',
                'headline' => ['Law. Justice.', 'Solutions.'],
                'services' => [['scales', 'Legal Representation'], ['briefcase', 'Corporate Advisory'], ['chat', 'Mediation & Arbitration'], ['shield', 'Regulatory Compliance'], ['home', 'Estate Planning']],
            ],
            'law-12' => [
                'label' => 'Veritas', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'facet',
                'face' => '#ffffff', 'ink' => '#101f33', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#16283f',
                'back' => 'linear-gradient(160deg, #14263d, #0c1626)', 'backInk' => '#f2ead2',
                'badge' => 'buildings', 'watermark' => 'buildings',
                'headline' => ['Trusted advice.', 'Proven results.'],
                'services' => [['chat', 'Legal Consultation'], ['briefcase', 'Business Law'], ['home', 'Property Law'], ['chart', 'Debt Recovery'], ['clipboard', 'Legal Documentation']],
            ],
            'law-13' => [
                'label' => 'Magna', 'sector' => 'Legal', 'family' => 'pro', 'variant' => 'pillar',
                'face' => '#ffffff', 'ink' => '#2a1440', 'muted' => '#64748b',
                'accent' => '#c9a227', 'accent2' => '#3b1d54',
                'back' => 'linear-gradient(160deg, #3b1d54, #241033)', 'backInk' => '#f0e6f7',
                'badge' => 'shield', 'watermark' => 'scales',
                'headline' => ['Excellence in law.', 'Service in justice.'],
                'services' => [['scales', 'Litigation Support'], ['book', 'Legal Research'], ['clipboard', 'Contract Management'], ['plane', 'Immigration Law'], ['chart', 'Tax Law']],
            ],

            // ── cards6 — designs unique to the mixed sheet ───────────────
            'arch-01' => [
                'label' => 'Studio', 'sector' => 'Architecture', 'family' => 'pro', 'variant' => 'swoop',
                'face' => '#ffffff', 'ink' => '#0f172a', 'muted' => '#64748b',
                'accent' => '#1d4ed8', 'accent2' => '#172554',
                'back' => 'linear-gradient(160deg, #101c33, #0a1222)', 'backInk' => '#e7edf8',
                'badge' => 'buildings', 'watermark' => 'buildings',
                'headline' => ['Designing spaces,', 'creating futures.'],
                'services' => [['buildings', 'Architecture Design'], ['home', 'Interior Design'], ['clipboard', 'Project Management'], ['grid', '3D Visualization'], ['chat', 'Building Consultation']],
            ],
            'auto-03' => [
                'label' => 'Atelier', 'sector' => 'Automotive', 'family' => 'pro', 'variant' => 'pillar',
                'face' => '#131313', 'ink' => '#ffffff', 'muted' => '#9ca3af',
                'accent' => '#dc2626', 'accent2' => '#7f1d1d',
                'back' => '#111111', 'backInk' => '#f3f4f6',
                'badge' => 'car', 'watermark' => 'gear',
                'headline' => ['We keep you moving', 'safely & smoothly.'],
                'services' => [['gear', 'Engine Diagnostics'], ['wrench', 'General Repair'], ['drop', 'Oil Change'], ['disc', 'Tire Services'], ['car', 'Car Maintenance']],
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
            'Restaurant & Food' => ['restaurant', 'food', 'catering', 'café', 'cafe', 'bakery', 'bar', 'chef', 'cuisine', 'coffee'],
            'Fitness & Sport' => ['fitness', 'gym', 'sport', 'coach', 'wellness'],
            'Legal' => ['law', 'legal', 'avocat', 'attorney', 'notaire', 'juridique'],
            'Architecture' => ['architect', 'design studio', 'urbanis'],
            'Travel & Tours' => ['travel', 'tour', 'voyage', 'tourism', 'agence de voyage'],
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
            'coffee' => '<path d="M4 8h12v7a5 5 0 0 1-5 5h-2a5 5 0 0 1-5-5z"/><path d="M16 9h2.5a2.5 2.5 0 0 1 0 5H16M7 2c0 1.2 1 1.4 1 2.5M11 2c0 1.2 1 1.4 1 2.5"/>',
            'dumbbell' => '<path d="M7 8v8M17 8v8M3.5 10v4M20.5 10v4M7 12h10"/>',
            'leaf' => '<path d="M5 19C4 9 10 4 20 4c0 10-5 16-13 15z"/><path d="M5 19c2-5 6-9 10-11"/>',
            'flask' => '<path d="M10 2v6L4.5 18a2.2 2.2 0 0 0 2 3.2h11a2.2 2.2 0 0 0 2-3.2L14 8V2"/><path d="M8 2h8M7 14h10"/>',
            'scales' => '<path d="M12 3v18M8 21h8M12 6H6M12 6h6"/><path d="M6 6 3.5 12a2.8 2.8 0 0 0 5 0zM18 6l-2.5 6a2.8 2.8 0 0 0 5 0z"/>',
            'chart' => '<path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 6-6"/><path d="M20 7h-4M20 7v4"/>',
            'lotus' => '<path d="M12 4c1.8 2 2.6 4.2 2.6 6.4 0 3-1.2 5-2.6 6.1-1.4-1.1-2.6-3.1-2.6-6.1C9.4 8.2 10.2 6 12 4z"/><path d="M4 10c3.2.6 5.4 2.2 6.6 4.4M20 10c-3.2.6-5.4 2.2-6.6 4.4M3 15.5C6 19 9 20 12 20s6-1 9-4.5"/>',
            'plane' => '<path d="M10.5 13.5 3 11l1.5-2 5.5.5L15 4l2.5.8-3.4 5.7 5.4 1.5 1.5 2.5-2.5.5-4-1.5-3 4.5H9z"/><path d="M4 20h16"/>',
            'shield' => '<path d="M12 2 4.5 5v6c0 5 3.2 8.8 7.5 11 4.3-2.2 7.5-6 7.5-11V5z"/><path d="m8.8 12 2.2 2.2 4.2-4.4"/>',
            'heart' => '<path d="M12 21C6.5 16.7 3 13.2 3 9.3A4.6 4.6 0 0 1 7.6 4.7c1.8 0 3.4.9 4.4 2.4a5.2 5.2 0 0 1 4.4-2.4A4.6 4.6 0 0 1 21 9.3c0 3.9-3.5 7.4-9 11.7z"/>',
            'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 12h18"/>',
            'drop' => '<path d="M12 3c3.5 4.2 6 7.6 6 10.7A6 6 0 0 1 6 13.7C6 10.6 8.5 7.2 12 3z"/>',
            'disc' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.4"/><path d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M18.4 5.6l-1.8 1.8M7.4 16.6l-1.8 1.8"/>',
            'fb' => '<path d="M13.5 21v-7h2.5l.5-3h-3V9.2c0-.9.3-1.5 1.6-1.5H16.7V5.1C16.4 5 15.5 5 14.5 5 12.3 5 10.8 6.3 10.8 8.8V11H8.3v3h2.5v7z"/>',
            'ig' => '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r=".6"/>',
            'ln' => '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M8 10.5V17M8 7.5v.1M12 17v-4a2 2 0 0 1 4 0v4"/>',
        ];

        $body = $paths[$name] ?? $paths['star'];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$body.'</svg>';
    }
}
