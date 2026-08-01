@php
    use App\Support\Money;
    use Illuminate\Support\Facades\Storage;

    $phone = data_get($company->phones, 0);
    $isA3 = $size === 'a3';
    $brand = $company->brandToken('primary', '#2563eb');
    $logoUrl = $company->logo_path ? Storage::disk('public')->url($company->logo_path) : null;
    // Null means the original look, so existing letterheads print unchanged.
    $design = in_array($company->letterhead_design, ['rule', 'banner', 'sidebar', 'crest'], true)
        ? $company->letterhead_design
        : 'rule';
    $cardDesign = $company->cardDesign();

    /*
     * The six premium designs carry their own front AND back; the legacy four
     * share the original dark back. Everything the premium faces need is
     * derived here once: the two-tone wordmark (the template's sample brand is
     * a two-tone logo, so the last word of the company name takes the accent),
     * the contact rows, and the four feature icons the back advertises.
     */
    $premiumDesigns = ['azure', 'onyx', 'jade', 'cyber', 'violet', 'sunrise'];
    $isPremiumCard = in_array($cardDesign, $premiumDesigns, true);

    $nameWords = preg_split('/\s+/', trim($company->name)) ?: [$company->name];
    $nameAccent = count($nameWords) > 1 ? array_pop($nameWords) : null;
    $nameBase = implode(' ', $nameWords);

    $contactRows = array_filter([
        'phone' => $phone,
        'mail' => $company->email,
        'web' => $company->website,
        'pin' => implode(', ', array_filter([$company->city, $company->country])),
    ]);

    // Tiny inline glyphs for the contact chips and the back's feature row —
    // the print pipeline stays dependency-free on purpose.
    $glyphs = [
        'phone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3A19.5 19.5 0 0 1 5.1 13 19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2z"/></svg>',
        'mail' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>',
        'web' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/></svg>',
        'pin' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        'person' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5.5 20c.8-3.2 3.4-5 6.5-5s5.7 1.8 6.5 5"/></svg>',
    ];

    $features = [
        ['label' => 'Invoicing', 'glyph' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>'],
        ['label' => 'Customers', 'glyph' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>'],
        ['label' => 'Payments', 'glyph' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>'],
        ['label' => 'Reports', 'glyph' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15v-4M12 17V8M17 13v-2"/></svg>'],
    ];

@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ ucfirst($asset) }} · {{ $company->name }}</title>
    <style>
        /* Page size follows the asset: stationery is cut to its own trim size. */
        @page {
            size: {{ $asset === 'letterhead' ? ($isA3 ? 'A3' : 'A4') : ($asset === 'card' ? '91mm 61mm' : 'A4') }};
            margin: {{ $asset === 'card' ? '0' : '0' }};
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; background: #eef2f7; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { box-shadow: none !important; margin: 0 !important; } }

        .sheet { background: #fff; margin: 24px auto; box-shadow: 0 4px 24px rgba(15,23,42,.12); }

        /* Letterhead — the four designs share the sheet; .design-* restyles the
           head and foot without ever intruding on the writable body. */
        .letterhead { width: {{ $isA3 ? '297mm' : '210mm' }}; min-height: {{ $isA3 ? '420mm' : '297mm' }}; padding: 18mm 16mm; display: flex; flex-direction: column; }
        .lh-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16mm; border-bottom: 1.5pt solid #0f172a; padding-bottom: 6mm; }
        .lh-name { font-size: {{ $isA3 ? '26pt' : '20pt' }}; font-weight: 800; letter-spacing: -0.02em; }
        .lh-motto { font-size: 9.5pt; color: #64748b; }
        .lh-meta { display: flex; justify-content: space-between; gap: 10mm; font-size: 8pt; color: #64748b; padding-top: 3mm; }
        .lh-body { flex: 1; }
        .lh-foot { border-top: 0.5pt solid #cbd5e1; padding-top: 4mm; text-align: center; font-size: 7.5pt; color: #94a3b8; }

        /* Banner: a full-bleed brand band across the top, name reversed out. */
        .design-banner { padding-top: 0; }
        .lh-band { margin: 0 -16mm; padding: 12mm 16mm 9mm; background: var(--brand); display: flex; justify-content: space-between; align-items: center; gap: 16mm; }
        .lh-band .lh-name { color: #fff; }
        .lh-band .lh-motto { color: rgba(255,255,255,.78); }
        /* The QR keeps its white quiet zone even on the coloured band. */
        .lh-qr-chip { background: #fff; padding: 1.5mm; flex-shrink: 0; }

        /* Sidebar: a slim brand bar carries the name; content indents past it. */
        .design-sidebar { position: relative; padding-left: 30mm; }
        .lh-bar { position: absolute; left: 0; top: 0; bottom: 0; width: 14mm; background: var(--brand); display: flex; justify-content: center; padding: 16mm 0; }
        .lh-bar-name { writing-mode: vertical-rl; transform: rotate(180deg); color: #fff; font-size: {{ $isA3 ? '17pt' : '13pt' }}; font-weight: 800; letter-spacing: 0.04em; white-space: nowrap; }
        .design-sidebar .lh-head { border-bottom-color: var(--brand); border-bottom-width: 0.75pt; }
        .lh-meta-col { font-size: 8pt; color: #64748b; line-height: 1.8; padding-top: 2mm; }

        /* Crest: everything centred between symmetric rules; the meta and the
           QR move to a centred footer so the page itself stays formal. */
        .lh-crest { text-align: center; border-top: 1.5pt solid #0f172a; border-bottom: 1.5pt solid #0f172a; padding: 6mm 0; }
        .lh-foot-crest { display: flex; flex-direction: column; align-items: center; gap: 1.5mm; }
        .lh-foot-crest svg { display: block; margin-bottom: 1.5mm; }

        /* Business card — bleed included, trim marks at 3mm. Dimensions are
           fixed mm and overflow is clipped: a long name truncates on its line
           rather than distorting the trim box. */
        .card { width: 91mm; height: 61mm; padding: 3mm; page-break-after: always; }
        .card-inner { width: 85mm; height: 55mm; padding: 6mm; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; }
        .card-dark { background: #0f172a; color: #fff; align-items: center; justify-content: center; text-align: center; }
        .card-name { font-size: 12pt; font-weight: 800; letter-spacing: -0.02em; }
        .card-small { font-size: 7pt; color: #64748b; line-height: 1.6; }
        .trunc { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Card designs restyle the inside of the same trim box. Every design
           keeps the verification QR: an unverifiable card defeats the module. */
        .card-bold { background: {{ $brand }}; color: #fff; }
        .card-bold .card-small { color: rgba(255,255,255,.78); }
        .qr-chip { background: #fff; padding: 1.5mm; flex-shrink: 0; }
        .qr-chip svg { display: block; width: 16mm; height: 16mm; }

        .card-minimal { padding: 5mm 6mm; }
        .card-minimal .rule { height: 0.35pt; background: #cbd5e1; flex-shrink: 0; }
        .min-name { font-size: 10.5pt; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; }
        .min-small { font-size: 6.5pt; color: #64748b; line-height: 1.7; letter-spacing: 0.02em; }
        .card-minimal .qr svg { display: block; width: 13mm; height: 13mm; }

        .card-split { padding: 0; flex-direction: row; }
        .split-left { width: 28mm; flex-shrink: 0; background: {{ $brand }}; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2mm; padding: 4mm; }
        .split-logo { max-width: 18mm; max-height: 18mm; }
        .split-initials { width: 15mm; height: 15mm; border: 0.75pt solid rgba(255,255,255,.7); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13pt; font-weight: 800; }
        .split-right { flex: 1; min-width: 0; padding: 5mm; display: flex; flex-direction: column; justify-content: space-between; }
        .card-split .qr svg { display: block; width: 15mm; height: 15mm; }

        /* Stamp */
        .stamp-page { width: 210mm; min-height: 120mm; display: flex; align-items: center; justify-content: center; padding: 20mm; }
        .stamp { border: 4pt solid #1d4ed8; color: #1d4ed8; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
        .stamp-circular { width: 52mm; height: 52mm; border-radius: 50%; }
        .stamp-square { width: 52mm; height: 52mm; border-radius: 3mm; }
        .stamp-oval { width: 62mm; height: 42mm; border-radius: 50%; }
        .stamp-name { font-size: 8.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; padding: 0 5mm; line-height: 1.2; }
        .stamp-rule { width: 14mm; height: 0.75pt; background: #1d4ed8; margin: 1.5mm 0; }
        .stamp-sub { font-size: 6pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; }

        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }

        /* Screen preview only: the 91mm card sheet (~344px) shrinks to fit
           narrow phones. Scoped to screen media, so paper keeps true mm. */
        @media screen and (max-width: 380px) { .card { zoom: 0.85; } }
        @media screen and (max-width: 320px) { .card { zoom: 0.7; } }

        /* Narrow screens scale the letterhead/stamp preview down to fit;
           @media print above is untouched, so paper keeps its true mm. */
        @media screen and (max-width: {{ $isA3 ? '1200px' : '880px' }}) {
            body { overflow-x: hidden; }
            .letterhead, .stamp-page { transform: scale({{ $isA3 ? '.5' : '.7' }}); transform-origin: top center; }
            .letterhead { margin-bottom: calc({{ $isA3 ? '420mm * -.5' : '297mm * -.3' }}); }
            .stamp-page { margin-bottom: calc(120mm * -.3); }
        }
        @media screen and (max-width: 560px) {
            .letterhead, .stamp-page { transform: scale({{ $isA3 ? '.3' : '.44' }}); }
            .letterhead { margin-bottom: calc({{ $isA3 ? '420mm * -.7' : '297mm * -.56' }}); }
            .stamp-page { margin-bottom: calc(120mm * -.56); }
        }

        /* ── Premium cards (template sheet designs 01-06) ─────────────────
           One skeleton, six skins. The skeleton fixes what every design
           shares — left column of identity + contact rows, right panel with
           the QR and its "scan" caption, decorated back — and each skin
           supplies colour, curvature and texture. All shapes are pure CSS so
           the print stays vector at any DPI. Colours are sampled from the
           template sheet itself (docs/image templates/cards1.png). */
        .pc, .pcb { position: relative; width: 100%; height: 100%; overflow: hidden; background: #fff; }
        .pc-left { position: absolute; left: 5.5mm; top: 5mm; bottom: 5mm; width: 50mm; z-index: 4; display: flex; flex-direction: column; }
        .pc-brand { font-size: 10.5pt; font-weight: 800; letter-spacing: -0.02em; line-height: 1.05; }
        .pc-brand-accent { color: var(--pc-accent); }
        .pc-motto { font-size: 5.2pt; color: #94a3b8; padding-top: 0.6mm; }
        .pc-person-wrap { display: flex; align-items: center; gap: 1.8mm; padding-top: 3.2mm; min-width: 0; }
        .pc-person-badge { display: none; width: 5mm; height: 5mm; border-radius: 50%; border: 0.55pt solid var(--pc-accent); color: var(--pc-accent); flex-shrink: 0; align-items: center; justify-content: center; }
        .pc-person-badge svg { width: 2.8mm; height: 2.8mm; }
        .pc-person { font-size: 8.6pt; font-weight: 700; }
        .pc-title { font-size: 5.6pt; color: var(--pc-title-fg, #94a3b8); padding-top: 0.4mm; }
        .pc-rows { margin-top: auto; display: flex; flex-direction: column; gap: 1.6mm; }
        .pc-row { display: flex; align-items: center; gap: 1.8mm; min-width: 0; }
        /* Solid accent chips with reversed-out glyphs, as on every template. */
        .pc-chip { width: 3.8mm; height: 3.8mm; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--pc-chip-bg, var(--pc-accent)); color: var(--pc-chip-fg, #fff); }
        .pc-chip svg { width: 2mm; height: 2mm; }
        .pc-row-text { font-size: 6.2pt; color: var(--pc-row-fg, #334155); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pc-panel { position: absolute; z-index: 2; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.6mm; }
        .pc-panel > * { position: relative; }
        .pc-qr { background: #fff; border-radius: 1.6mm; padding: 1.4mm; line-height: 0; }
        .pc-qr svg { width: 15mm; height: 15mm; display: block; }
        .pc-scan { font-size: 5pt; font-weight: 600; text-align: center; line-height: 1.35; color: var(--pc-panel-fg, #fff); }
        .pc-deco-a, .pc-deco-b, .pc-deco-c, .pc-deco-d { position: absolute; }

        /* Back skeleton: centred stack of wordmark, boxed feature row and
           tagline — the feature glyphs sit inside rounded-square outlines on
           every template back. */
        .pcb { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 5mm 6mm; }
        .pcb > * { position: relative; z-index: 3; }
        /* The decos are corner ornaments, not flow content — re-assert the
           absolute positioning that `.pcb > *` above would otherwise flatten
           into the centred column, and keep them under the text. */
        .pcb .pcb-deco-a, .pcb .pcb-deco-b, .pcb .pcb-deco-c { position: absolute; z-index: 1; }
        .pcb-brand { font-size: 13pt; font-weight: 800; letter-spacing: -0.02em; }
        .pcb-brand .pc-brand-accent { color: var(--pc-accent); }
        .pcb-motto { font-size: 5.6pt; color: var(--pcb-muted, #94a3b8); padding-top: 0.6mm; }
        .pcb-features { display: flex; gap: 5mm; padding: 4.4mm 0 4mm; }
        .pcb-feature { display: flex; flex-direction: column; align-items: center; gap: 1.1mm; color: var(--pcb-icon); }
        .pcb-feature-box { width: 7.4mm; height: 7.4mm; border: 0.55pt solid currentColor; border-radius: 2mm; display: flex; align-items: center; justify-content: center; }
        .pcb-feature svg { width: 4.2mm; height: 4.2mm; }
        .pcb-feature span:last-child { font-size: 4.8pt; font-weight: 600; color: var(--pcb-label, var(--pcb-icon)); }
        .pcb-dash { display: none; width: 5.6mm; height: 0.7mm; border-radius: 1mm; margin-bottom: 2.6mm; background: var(--pcb-dash, currentColor); }
        .pcb-tagline { font-size: 6pt; font-weight: 600; color: var(--pcb-tagline); }

        /* 01 · Azure — white face; full-bleed blue block on the right with a
           lighter tab and pale crescent layered behind a concave white bite;
           circled person badge beside the name. */
        .pc-azure { --pc-accent: #1464fc; --pc-chip-bg: #0a5cf5; }
        .pc-azure .pc-person-badge { display: flex; }
        .pc-azure .pc-panel { top: 0; right: 0; bottom: 0; width: 29mm; border-radius: 4.5mm 0 0 4.5mm; background: linear-gradient(155deg, #0f6bfa 0%, #0a4fd6 45%, #04338c 100%); }
        .pc-azure .pc-deco-c { top: 0; right: 26.5mm; width: 6.5mm; height: 30mm; border-radius: 2.2mm; background: #4f8bfb; z-index: 1; }
        .pc-azure .pc-deco-d { bottom: -6mm; right: 24mm; width: 16mm; height: 26mm; border-radius: 50%; background: #e5effd; z-index: 1; }
        .pc-azure .pc-deco-a { top: 50%; right: 26mm; width: 16mm; height: 26mm; transform: translateY(-50%); background: #fff; border-radius: 50%; z-index: 3; }
        .pc-azure .pc-deco-b { left: -10mm; bottom: -14mm; width: 30mm; height: 30mm; border-radius: 50%; background: #e8f0fd; }
        .pcb-azure { background: radial-gradient(60mm 40mm at 50% 0%, rgba(255,255,255,.14), transparent 60%), linear-gradient(160deg, #0f4ab9 0%, #0a3ba2 40%, #032169 100%); color: #fff; --pc-accent: #fff; --pcb-muted: rgba(255,255,255,.8); --pcb-icon: #fff; --pcb-tagline: #fff; }

        /* 02 · Onyx — hatched black face; a leaning gold-edged panel bleeds
           off the bottom-right corner; gold wordmark spark. */
        .pc-onyx { background: repeating-linear-gradient(115deg, rgba(255,255,255,.014) 0 2px, transparent 2px 7px), #0c1114; color: #fff; --pc-accent: #e2b34d; --pc-chip-bg: #dcab4b; --pc-chip-fg: #17130a; --pc-row-fg: #cfcbc2; --pc-title-fg: #8d8778; --pc-panel-fg: #f2e7c9; }
        .pc-onyx .pc-person { color: #fff; }
        .pc-onyx .pc-motto { color: #8d8778; }
        .pc-onyx .pc-brand::after { content: '\2726'; color: #e9c766; font-size: 4.6pt; vertical-align: top; margin-left: 0.5mm; }
        .pc-onyx .pc-deco-a { top: 4mm; right: -4mm; bottom: -7mm; width: 37mm; background: #151a1e; border: 0.5pt solid rgba(222,173,74,.9); border-radius: 4mm; transform: skewX(-8deg); }
        .pc-onyx .pc-panel { top: 8mm; right: 4mm; bottom: 8mm; width: 27mm; }
        .pcb-onyx { background: repeating-linear-gradient(120deg, rgba(255,255,255,.016) 0 3px, transparent 3px 9px), #101214; color: #f4e3bd; --pc-accent: #e2b34d; --pcb-muted: #a9a291; --pcb-icon: #e2b34d; --pcb-label: #f5f2ea; --pcb-tagline: #f0ede4; }
        .pcb-onyx .pcb-brand { color: #e2b34d; }
        .pcb-onyx .pcb-brand::after { content: '\2726'; color: #e9c766; font-size: 5pt; vertical-align: top; margin-left: 0.5mm; }
        .pcb-onyx .pcb-deco-a { top: -3mm; right: -9mm; width: 34mm; height: 8mm; background: linear-gradient(90deg, #c0903f, #f0c060); transform: rotate(24deg); }
        .pcb-onyx .pcb-deco-c { top: 4.2mm; right: -10mm; width: 34mm; height: 1.1mm; background: rgba(222,173,74,.45); transform: rotate(24deg); }

        /* 03 · Jade — white face over layered green blooms; the QR panel
           floats inset; green title; back closes on a curved green band. */
        .pc-jade { --pc-accent: #2e9440; --pc-title-fg: #2e9440; }
        .pc-jade .pc-deco-c { top: 0; right: 0; bottom: 0; width: 33mm; border-radius: 6mm 0 0 6mm; background: linear-gradient(200deg, #e9f3e6 0%, #cfe3c9 60%, #bcd9b4 100%); }
        .pc-jade .pc-deco-d { top: -10mm; right: -10mm; width: 36mm; height: 42mm; border-radius: 50%; background: rgba(79,159,51,.28); z-index: 1; }
        .pc-jade .pc-panel { top: 9mm; right: 2mm; bottom: 9mm; width: 27mm; border-radius: 3.5mm; background: linear-gradient(160deg, #5aa93e 0%, #2c8632 55%, #1f7028 100%); }
        .pc-jade .pc-qr { border-radius: 2.5mm; }
        .pc-jade .pc-deco-a { top: 54%; right: 26mm; width: 14mm; height: 22mm; transform: translateY(-50%); background: #fff; border-radius: 50%; z-index: 3; }
        .pcb-jade { --pc-accent: #2e9440; --pcb-muted: #94a3b8; --pcb-icon: #2e9440; --pcb-label: #1f2937; --pcb-tagline: #fff; padding-bottom: 12mm; }
        .pcb-jade .pcb-brand { color: #0f172a; }
        .pcb-jade .pcb-deco-b { top: -8mm; left: -8mm; width: 24mm; height: 24mm; border-radius: 50%; background: #eef6ec; }
        .pcb-jade .pcb-deco-a { left: -10mm; right: -10mm; bottom: -9mm; height: 16mm; background: linear-gradient(90deg, #278a30, #1d6b26); border-radius: 50% 50% 0 0; }
        .pcb-jade .pcb-tagline { position: absolute; left: 0; right: 0; bottom: 2.6mm; }

        /* 04 · Cyber — deep navy with a cyan glow, faceted corner and dot
           grid; the QR held in a crisply outlined hexagon. */
        .pc-cyber { background: radial-gradient(44mm 40mm at 86% 8%, rgba(34,211,238,.16), transparent 70%), #0a1c2a; color: #fff; --pc-accent: #2fd6f0; --pc-chip-bg: #17c3e4; --pc-chip-fg: #062033; --pc-row-fg: #cbd5e1; --pc-title-fg: #64748b; --pc-panel-fg: #55d7ea; }
        .pc-cyber .pc-person { color: #fff; }
        .pc-cyber .pc-motto { color: #64748b; }
        .pc-cyber .pc-deco-b { bottom: 0; right: 0; width: 34mm; height: 26mm; background: rgba(3,10,18,.55); clip-path: polygon(100% 34%, 100% 100%, 30% 100%); }
        .pc-cyber .pc-deco-c { right: 1mm; bottom: 1mm; width: 26mm; height: 16mm; background-image: radial-gradient(rgba(34,211,238,.3) 13%, transparent 15%); background-size: 2.6mm 2.6mm; opacity: .45; }
        .pc-cyber .pc-panel { top: 50%; right: 3.5mm; width: 30mm; height: 42mm; transform: translateY(-50%); background: #2fd6f0; clip-path: polygon(50% 0, 97% 24%, 97% 76%, 50% 100%, 3% 76%, 3% 24%); }
        .pc-cyber .pc-panel::before { content: ''; position: absolute; inset: 0.45mm; background: #0d2334; clip-path: polygon(50% 0, 97% 24%, 97% 76%, 50% 100%, 3% 76%, 3% 24%); }
        .pcb-cyber { background: radial-gradient(50mm 40mm at 82% 0%, rgba(34,211,238,.1), transparent 70%), #0a1c2a; color: #fff; --pc-accent: #2fd6f0; --pcb-muted: #64748b; --pcb-icon: #2fd6f0; --pcb-label: #cfeef5; --pcb-tagline: #e6fbff; --pcb-dash: #2fd6f0; }
        .pcb-cyber .pcb-dash { display: block; }
        .pcb-cyber .pcb-deco-a { top: -12mm; right: -12mm; width: 34mm; height: 34mm; background: transparent; clip-path: polygon(50% 0, 96% 25%, 96% 75%, 50% 100%, 4% 75%, 4% 25%); box-shadow: inset 0 0 0 0.5pt rgba(34,211,238,.4); }
        .pcb-cyber .pcb-deco-b { bottom: -10mm; left: -8mm; width: 26mm; height: 26mm; background: transparent; clip-path: polygon(50% 0, 96% 25%, 96% 75%, 50% 100%, 4% 75%, 4% 25%); box-shadow: inset 0 0 0 0.5pt rgba(34,211,238,.25); }
        .pcb-cyber .pcb-deco-c { bottom: 0; left: 0; width: 30mm; height: 20mm; background: rgba(3,10,18,.45); clip-path: polygon(0 30%, 70% 100%, 0 100%); }

        /* 05 · Violet — white face; indigo-to-magenta wave layered over
           lighter crescents; the QR and its caption share a floating white
           card; purple dot grid. */
        .pc-violet { --pc-accent: #7c3aed; }
        .pc-violet .pc-deco-c { top: -16mm; right: -6mm; width: 42mm; height: 44mm; border-radius: 50%; background: #c9a8f0; z-index: 1; }
        .pc-violet .pc-deco-d { bottom: -18mm; right: -6mm; width: 40mm; height: 36mm; border-radius: 50%; background: #b78ae8; z-index: 1; }
        .pc-violet .pc-deco-a { top: -8mm; right: -14mm; bottom: -8mm; width: 44mm; background: linear-gradient(190deg, #5a28bd 0%, #7c2fb4 55%, #8a33a5 100%); border-radius: 42% 0 0 46%; z-index: 2; }
        .pc-violet .pc-deco-b { left: 38mm; bottom: 3mm; width: 9mm; height: 9mm; background-image: radial-gradient(#8b5cf6 22%, transparent 24%); background-size: 2.2mm 2.2mm; z-index: 3; }
        .pc-violet .pc-panel { top: 50%; right: 5mm; width: 26mm; transform: translateY(-50%); background: #fff; border-radius: 3mm; padding: 2.2mm 2.2mm 1.8mm; gap: 1.4mm; box-shadow: 0 1.2mm 3mm rgba(50,20,90,.35); z-index: 3; }
        .pc-violet .pc-qr { padding: 0.8mm; border: 0.45pt solid #7c3aed; border-radius: 1.8mm; }
        .pc-violet .pc-qr svg { width: 14mm; height: 14mm; }
        .pc-violet .pc-scan { color: #241640; }
        .pcb-violet { background: linear-gradient(135deg, #a558cf 0%, #6d2fb2 45%, #3b2496 80%, #282788 100%); color: #fff; --pc-accent: #fff; --pcb-muted: rgba(255,255,255,.8); --pcb-icon: #fff; --pcb-tagline: #fff; --pcb-dash: rgba(255,255,255,.6); }
        .pcb-violet .pcb-dash { display: block; }
        .pcb-violet .pcb-deco-b { left: 4mm; bottom: 2mm; width: 9mm; height: 9mm; background-image: radial-gradient(rgba(255,255,255,.35) 20%, transparent 22%); background-size: 2.2mm 2.2mm; }

        /* 06 · Sunrise — white face; navy-black and orange crescents with a
           thin orange arc ahead of them; the QR in an orange-ringed circle
           riding the swoosh. */
        .pc-sunrise { --pc-accent: #f9741d; }
        .pc-sunrise .pc-deco-c { top: -18mm; right: -26mm; width: 60mm; height: 96mm; border-radius: 50%; border-left: 0.6pt solid #f9741d; }
        .pc-sunrise .pc-deco-a { top: -16mm; right: -34mm; width: 62mm; height: 94mm; border-radius: 50%; background: #0d1823; z-index: 1; }
        .pc-sunrise .pc-deco-b { top: -14mm; right: -38mm; width: 60mm; height: 90mm; border-radius: 50%; background: linear-gradient(180deg, #f88c34 0%, #ee6d10 60%, #e35f08 100%); z-index: 2; }
        .pc-sunrise .pc-panel { top: 50%; right: 4.5mm; width: 24mm; transform: translateY(-50%); }
        /* The square code must fit the round chip: 11.5mm side ≈ 16.3mm
           diagonal inside the 16.7mm circle, so the quiet zone survives. */
        .pc-sunrise .pc-qr { border-radius: 50%; padding: 2.6mm; box-shadow: 0 0 0 1.3mm #ee6d10, 0 0 0 1.8mm #fff; }
        .pc-sunrise .pc-qr svg { width: 11.5mm; height: 11.5mm; }
        .pc-sunrise .pc-scan { color: #1f2937; margin-top: 4mm; }
        .pcb-sunrise { --pc-accent: #f9741d; --pcb-muted: #94a3b8; --pcb-icon: #f9741d; --pcb-label: #303a46; --pcb-tagline: #17202b; }
        .pcb-sunrise .pcb-brand { color: #0f172a; }
        .pcb-sunrise .pcb-deco-c { top: 1mm; right: -12mm; width: 42mm; height: 11mm; background: #fbd7b0; transform: rotate(22deg); }
        .pcb-sunrise .pcb-deco-a { top: -4mm; right: -11mm; width: 42mm; height: 10mm; background: linear-gradient(90deg, #f2913c, #e56607); transform: rotate(22deg); }
        .pcb-sunrise .pcb-deco-b { bottom: -16mm; left: -14mm; width: 52mm; height: 26mm; border-radius: 50%; background: linear-gradient(90deg, #ef7c1a, #e35f08); transform: rotate(8deg); }

    </style>
</head>
<body>

@if ($asset === 'letterhead')
    <div class="sheet letterhead design-{{ $design }}" style="--brand: {{ $brand }}">
        @if ($design === 'banner')
            <div class="lh-band">
                <div>
                    <div class="lh-name">{{ $company->name }}</div>
                    @if ($company->motto)<div class="lh-motto">{{ $company->motto }}</div>@endif
                </div>
                <div class="lh-qr-chip">{!! $qrSvg !!}</div>
            </div>
            <div class="lh-meta">
                <span>{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</span>
                <span>{{ implode(' · ', array_filter([$phone, $company->email, $company->website])) }}</span>
            </div>
        @elseif ($design === 'sidebar')
            <div class="lh-bar"><div class="lh-bar-name">{{ $company->name }}</div></div>
            <div class="lh-head">
                <div>
                    @if ($company->motto)<div class="lh-motto" style="font-size: 11pt">{{ $company->motto }}</div>@endif
                    <div class="lh-meta-col">
                        <div>{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</div>
                        <div>{{ implode(' · ', array_filter([$phone, $company->email, $company->website])) }}</div>
                    </div>
                </div>
                <div>{!! $qrSvg !!}</div>
            </div>
        @elseif ($design === 'crest')
            <div class="lh-crest">
                <div class="lh-name">{{ $company->name }}</div>
                @if ($company->motto)<div class="lh-motto">{{ $company->motto }}</div>@endif
            </div>
        @else
            <div class="lh-head">
                <div>
                    <div class="lh-name">{{ $company->name }}</div>
                    @if ($company->motto)<div class="lh-motto">{{ $company->motto }}</div>@endif
                </div>
                <div>{!! $qrSvg !!}</div>
            </div>
            <div class="lh-meta">
                <span>{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</span>
                <span>{{ implode(' · ', array_filter([$phone, $company->email, $company->website])) }}</span>
            </div>
        @endif
        <div class="lh-body"></div>
        @if ($design === 'crest')
            <div class="lh-foot lh-foot-crest">
                {!! $qrSvg !!}
                <div>{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</div>
                <div>{{ implode(' · ', array_filter([$phone, $company->email, $company->website])) }}</div>
                <div>
                    {{ implode(' · ', array_filter([
                        $company->registration_number ? 'Reg. '.$company->registration_number : null,
                        'Generated with OPES360',
                    ])) }}
                </div>
            </div>
        @else
            <div class="lh-foot">
                {{ implode(' · ', array_filter([
                    $company->registration_number ? 'Reg. '.$company->registration_number : null,
                    $company->website,
                    'Generated with OPES360',
                ])) }}
            </div>
        @endif
    </div>

@elseif ($asset === 'card')
    @if ($isPremiumCard)
        {{-- Front --}}
        <div class="sheet card">
            <div class="pc pc-{{ $cardDesign }}">
                <div class="pc-deco-c"></div>
                <div class="pc-deco-d"></div>
                <div class="pc-deco-a"></div>
                <div class="pc-deco-b"></div>
                <div class="pc-left">
                    <div class="pc-brand trunc">{{ $nameBase }}@if ($nameAccent)<span class="pc-brand-accent"> {{ $nameAccent }}</span>@endif</div>
                    @if ($company->motto)<div class="pc-motto trunc">{{ $company->motto }}</div>@endif
                    <div class="pc-person-wrap">
                        <span class="pc-person-badge">{!! $glyphs['person'] !!}</span>
                        <div style="min-width:0">
                            <div class="pc-person trunc">{{ $name }}</div>
                            <div class="pc-title trunc">{{ $title }}</div>
                        </div>
                    </div>
                    <div class="pc-rows">
                        @foreach ($contactRows as $rowKey => $rowValue)
                            <div class="pc-row">
                                <span class="pc-chip">{!! $glyphs[$rowKey] !!}</span>
                                <span class="pc-row-text">{{ $rowValue }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="pc-panel">
                    <div class="pc-qr">{!! $qrSvg !!}</div>
                    <div class="pc-scan">Scan to view<br>my business</div>
                </div>
            </div>
        </div>

        {{-- Back --}}
        <div class="sheet card">
            <div class="pcb pcb-{{ $cardDesign }}">
                <div class="pcb-deco-c"></div>
                <div class="pcb-deco-a"></div>
                <div class="pcb-deco-b"></div>
                <div class="pcb-brand trunc">{{ $nameBase }}@if ($nameAccent)<span class="pc-brand-accent"> {{ $nameAccent }}</span>@endif</div>
                @if ($company->motto)<div class="pcb-motto trunc">{{ $company->motto }}</div>@endif
                <div class="pcb-features">
                    @foreach ($features as $feature)
                        <div class="pcb-feature">
                            <span class="pcb-feature-box">{!! $feature['glyph'] !!}</span>
                            <span>{{ $feature['label'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="pcb-dash"></div>
                <div class="pcb-tagline">All your business. One platform.</div>
            </div>
        </div>
    @elseif ($cardDesign === 'bold')
        <div class="sheet card">
            <div class="card-inner card-bold">
                <div>
                    <div class="card-name trunc">{{ $company->name }}</div>
                    @if ($company->motto)<div class="card-small trunc">{{ $company->motto }}</div>@endif
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:4mm">
                    <div style="min-width:0">
                        <div class="trunc" style="font-size:9pt; font-weight:700">{{ $name }}</div>
                        <div class="card-small trunc">{{ $title }}</div>
                        <div class="card-small" style="padding-top:2mm">
                            {{ $phone }}<br>{{ $company->email }}<br>{{ $company->website }}
                        </div>
                    </div>
                    {{-- White chip keeps the QR scannable against the panel. --}}
                    <div class="qr-chip">{!! $qrSvg !!}</div>
                </div>
            </div>
        </div>

    @elseif ($cardDesign === 'minimal')
        <div class="sheet card">
            <div class="card-inner card-minimal">
                <div class="rule"></div>
                <div style="min-width:0">
                    <div class="min-name trunc">{{ $company->name }}</div>
                    @if ($company->motto)<div class="min-small trunc">{{ $company->motto }}</div>@endif
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:4mm">
                    <div class="min-small" style="min-width:0">
                        <span class="trunc" style="display:block; font-size:7.5pt; font-weight:600; color:#0f172a">{{ $name }} · {{ $title }}</span>
                        {{ implode(' · ', array_filter([$phone, $company->email])) }}<br>
                        {{ implode(' · ', array_filter([$company->website, $company->city, $company->country])) }}
                    </div>
                    <div class="qr">{!! $qrSvg !!}</div>
                </div>
                <div class="rule"></div>
            </div>
        </div>

    @elseif ($cardDesign === 'split')
        <div class="sheet card">
            <div class="card-inner card-split">
                <div class="split-left">
                    @if ($logoUrl)
                        <img class="split-logo" src="{{ $logoUrl }}" alt="">
                    @else
                        <div class="split-initials">{{ $company->initials() }}</div>
                    @endif
                </div>
                <div class="split-right">
                    <div style="min-width:0">
                        <div class="card-name trunc" style="font-size:11pt">{{ $company->name }}</div>
                        @if ($company->motto)<div class="card-small trunc">{{ $company->motto }}</div>@endif
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:3mm">
                        <div style="min-width:0">
                            <div class="trunc" style="font-size:8.5pt; font-weight:700">{{ $name }}</div>
                            <div class="card-small trunc">{{ $title }}</div>
                            <div class="card-small" style="padding-top:1.5mm">
                                {{ $phone }}<br>{{ $company->email }}<br>
                                <span class="trunc" style="display:block">{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</span>
                            </div>
                        </div>
                        <div class="qr">{!! $qrSvg !!}</div>
                    </div>
                </div>
            </div>
        </div>

    @else
        <div class="sheet card">
            <div class="card-inner card-classic">
                <div style="min-width:0">
                    <div class="card-name trunc">{{ $company->name }}</div>
                    @if ($company->motto)<div class="card-small trunc">{{ $company->motto }}</div>@endif
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:4mm">
                    <div style="min-width:0">
                        <div class="trunc" style="font-size:9pt; font-weight:700">{{ $name }}</div>
                        <div class="trunc" style="font-size:7pt; color:#64748b">{{ $title }}</div>
                        <div class="card-small" style="padding-top:2mm">
                            {{ $phone }}<br>{{ $company->email }}<br>{{ $company->website }}
                        </div>
                    </div>
                    <div>{!! $qrSvg !!}</div>
                </div>
            </div>
        </div>
    @endif

    @unless ($isPremiumCard)
    {{-- Back (legacy designs share this dark face) --}}
    <div class="sheet card">
        <div class="card-inner card-dark">
            <div class="card-name" style="font-size:14pt">{{ $company->name }}</div>
            <div style="font-size:7pt; color:#cbd5e1; padding-top:1mm">{{ $company->motto ?? $company->industry }}</div>
            <div style="background:#fff; padding:1.5mm; margin-top:3mm">{!! $qrSvg !!}</div>
            <div style="font-size:6pt; color:#94a3b8; padding-top:1.5mm">Scan to verify this business</div>
        </div>
    </div>
    @endunless

@else
    <div class="sheet stamp-page">
        <div class="stamp stamp-{{ $shape }}">
            <div class="stamp-name">{{ $company->name }}</div>
            <div class="stamp-rule"></div>
            <div class="stamp-sub">{{ $company->registration_number ?? $company->industry ?? 'Verified' }}</div>
            <div class="stamp-sub" style="font-weight:400; padding-top:1mm">{{ $company->city ?? $company->country }}</div>
        </div>
    </div>
@endif

<div class="print-bar no-print">
    <button onclick="window.print()">Print or Save as PDF</button>
</div>
</body>
</html>
