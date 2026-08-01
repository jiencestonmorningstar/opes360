# Business-card designs

The stationery module prints business cards in 98 designs: 10 universal
designs plus an industry catalogue of 88, reproduced from the template sheets
in `docs/image templates/` (cards1 … cards18).

## Where things live

| Piece | Where | What it holds |
|---|---|---|
| Universal designs | `Company::CARD_DESIGNS` | classic, bold, minimal, split, plus the six premium designs from cards1 (azure, onyx, jade, cyber, violet, sunrise) |
| Industry catalogue | `App\Support\CardCatalog` | every design from cards2 … cards18 as data: palette, sector, glyphs, feature/service lists, headlines |
| Rendering | `resources/views/print/stationery.blade.php` | the CSS skeletons and markup; all shapes are pure CSS so print stays vector at any DPI |
| Picker | `resources/views/livewire/business/stationery.blade.php` | sector chips + live tiles; each tile and the preview embed the print view itself |

## The two catalogue families

Every industry sheet reduces to one of two structural skeletons, coloured per
design through CSS variables — adding a design means adding a config entry,
not CSS:

- **spotlight** (cards2, cards3, cards5): sector badge + wordmark face with a
  big sector motif; solid-colour back holding the QR and the sector's feature
  icons. Variants: `spot` (cards2), `brand` (cards3 — QR on the face, tagline
  back), `edge` (cards5 — vertical sector word, five features).
- **pro** (cards6 … cards18): brand-lockup face with an accent wedge
  (`swoop` / `facet` / `pillar`) and the QR under a SCAN TO SAVE caption;
  the back carries a two-line headline, the service list, the QR, the
  website and social marks.

## Sectors and recommendation

`CardCatalog::bySector()` groups the catalogue; the picker shows one chip per
sector plus Universal. `CardCatalog::sectorFor($industry)` maps a company's
declared industry (English or French keywords) onto a sector — the picker
opens there with a ★ Recommended badge. Companies whose industry matches
nothing start on Universal.

## Faithfulness limits, on purpose

- Stock photos baked into some template mockups are replaced by the sector's
  motif glyph — a CSS reproduction cannot carry licensed photography.
- The sheets' fictional sample brands (LEX PARTNERS, FOCUS STUDIO, …) are
  placeholder content: the company's own name, motto, industry and website
  take their place at render time.
- The template sheets' typeface is approximated by Inter, the platform font.

## Previews

The stationery page never re-implements a design: tiles request
`/stationery/print?asset=card&design=…&preview=1&face=front` and the preview
pane the full two-face output. Preview mode hides the print bar and scales
the sheet to the frame; `?design=` overrides the rendered design for that
request only and never saves. The print route sends
`frame-ancestors 'self'`, so only the app itself can frame it.
