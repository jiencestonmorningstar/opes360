# Branding module — design

**Date:** 2026-08-15
**Status:** Approved
**Scope:** One sub-project. Hotel Management and the eight ERP gaps remain separate.

---

## Purpose

Let a company owner re-skin the platform to their own brand — colour, surface
temperature, corner shape, density, and an optional liquid-glass treatment —
without being able to make the product unreadable.

The second half of that sentence is the hard part and the reason this document
exists. `resources/css/app.css` currently guarantees that every hue pair clears
WCAG AA 4.5:1 on every surface, in both light and dark, and
`tests/Feature/Design/ColourContrastTest.php` enforces it. A naive colour picker
throws that guarantee away on day one. This design keeps it.

The platform owner uses the same engine at a different scope to brand the
marketing site.

---

## Why the obvious approach is wrong

A brand colour is a signature, not a text colour.

Contrast is **symmetric** — the ratio between a colour and white is one number,
whichever of the two is the text. So the two roles are not distinguished by
direction but by *which background they must survive*:

- **fill role** — a solid button under white text. Hardest background: white.
- **ink role** — text and icons. Must hold on *every* surface, and the darkest
  of those is canvas `#eef2f7`, not white. Canvas is therefore the binding test.

Measured:

| Brand | Hex | vs white | vs canvas | Verdict |
|---|---|---|---|---|
| Spotify green | `#1DB954` | 2.59 | 2.30 | fails both roles |
| Airbnb Rausch | `#FF385C` | 3.52 | 3.13 | fails both roles |
| Stripe indigo | `#635BFF` | 4.70 | 4.18 | fill ✓, ink fails on canvas |
| Linear indigo | `#5E6AD2` | 4.70 | 4.18 | fill ✓, ink fails on canvas |
| Shopify green | `#008060` | 4.93 | 4.39 | fill ✓, ink fails on canvas |
| Slack aubergine | `#4A154B` | 14.00 | 12.45 | ✓ both roles |

Only Slack's aubergine survives both. Spotify puts *black* text on its green
button precisely because white fails, and never sets green type on white.

The near-misses are the instructive ones: Stripe, Linear and Shopify are all
comfortably legal as buttons and all illegal as body text on the app's own
canvas, by a margin (4.18 vs 4.5) far too small to eyeball. This is exactly the
error a human picking colours in a settings screen cannot catch, and precisely
what the derivation engine exists to prevent.

So the owner's colour is a **seed**, not a token. It is kept exactly where it is
safe (logos, large brand shapes) and used to *derive* the three UI roles the
design system already has.

---

## Decisions

| Decision | Choice | Rejected |
|---|---|---|
| Unsafe colours | Derive safe roles automatically | Warn-and-allow; curated palettes only; expert override |
| Control surface | Colour + surface + shape + density | Colour only; adding typography |
| Glass scope | Chrome only; data cards stay solid | Glass everywhere |
| Branding reach | App UI, print, customer-facing, marketing site | — |
| Token delivery | Server-rendered `:root` override | Per-tenant stylesheet; client-side JS |

**Typography is out of scope.** Views contain 2453 hardcoded `text-[Npx]`
values; type is not tokenised, so a font/size control would be a
two-thousand-file change with no safe rollback.

**Semantic colours are not brandable.** `positive`, `warning`, `negative` stay
fixed. A company must not be able to make "overdue" green.

---

## Why `:root` override works

Tailwind v4 compiles every utility to a variable reference. Verified against the
built stylesheet:

```
.bg-fill-brand { background-color: var(--color-fill-brand) }
.text-ink      { color: var(--color-ink) }
.p-3           { padding: calc(var(--spacing) * 3) }
.rounded-xl    { border-radius: var(--radius-xl) }
--spacing: .25rem;
```

Overriding those variables at `:root` re-skins the whole platform with **zero
component changes**. Density is one variable (`--spacing`); shape is one scale
(`--radius-*`).

---

## Components

### 1. `app/Support/Colour.php`

Pure functions, no framework dependency.

```
fromHex(string) : [r, g, b]              srgb 0-1
toHex(array)    : string
srgbToOklab / oklabToSrgb                 via linear-light srgb + LMS
toOklch / fromOklch                       polar form of Oklab
relativeLuminance(array) : float          WCAG 2.1
contrast(a, b) : float                    (L1 + .05) / (L2 + .05)
toContrast(seed, against, target, direction) : hex
```

`direction` is `darken` or `lighten` — which way to move the seed. Light mode
darkens the seed until it reads on canvas; dark mode lightens it until it reads
on a near-black canvas. It is an explicit argument rather than inferred from the
background, because at mid lightness both directions can reach the target and
only the caller knows which theme it is building.

`toContrast` binary-searches **lightness only** in OKLCH, holding hue and chroma,
until the contrast target is met against the given background. The ink role is
searched against **canvas**, not white — canvas is the darkest surface text sits
on and therefore the binding constraint; a colour that clears canvas clears
surface and surface-2 for free. Preserving hue and
chroma is what makes the result still read as the owner's colour rather than a
generic dark shade. 24 iterations converges well past 8-bit precision; if the
target is unreachable at any lightness (an extreme chroma at a hue with a narrow
gamut), it clamps to the best achievable value and the caller is told, so the UI
can say so rather than silently lying.

OKLCH is used rather than HSL because HSL lightness is not perceptual — HSL 50%
yellow and HSL 50% blue differ by more than 4:1 in actual luminance, so an
HSL-based ramp produces wildly inconsistent contrast across hues.

### 2. `app/Support/BrandPalette.php`

```
BrandPalette::for(Company|null $company) : array   // cached
BrandPalette::derive(array $inputs) : array        // pure
```

Returns the complete token map for light **and** dark:

- `--color-brand` (ink role) — ≥4.5:1 against canvas, surface, surface-2
- `--color-fill-brand` — ≥4.5:1 against white
- `--color-tint-*` — wash on which the matching ink reads ≥4.5:1
- `--color-accent-*` — secondary seed maps onto the accent family
- surfaces/border/text ramp — shifted by neutral temperature
- `--spacing`, `--radius-*`
- `--glass-bg`, `--glass-blur`, `--glass-border`

Dark mode is derived independently, not by inverting: ink must be *light* on a
dark canvas while fill must stay dark enough to carry white text. The existing
hand-tuned palette already encodes this and is the reference the generator must
reproduce.

Caching keys on `company_id` plus a hash of the inputs, so an edit invalidates
without an explicit flush.

### 3. Storage — `companies.branding` (json, nullable)

Inputs only. Derived output is never persisted; it is recomputed and cached.

```json
{
  "primary":       "#1d4ed8",
  "secondary":     "#7e22ce",
  "neutral":       "cool",          // cool | warm | true-black
  "radius":        "rounded",       // sharp | soft | rounded | pill
  "density":       "comfortable",   // compact | comfortable
  "skin":          "solid",         // solid | glass
  "glass_strength": 0.5             // 0-1, only meaningful when skin=glass
}
```

`null` means platform default — nothing changes until an owner opts in.

`brand_tokens` (existing) keeps working: `Company::brandToken()` starts reading
through the derived palette, so the loyalty and VIP cards inherit branding
without touching those views.

### 4. Delivery — `<x-branding.styles />`

One Blade component in the layout `<head>`, after the compiled stylesheet.
Emits `:root{…}` and `.dark{…}`. Inlined rather than fetched, so it survives
offline and cannot flash the default palette before applying.

### 5. Glass

A `.glass` utility in `app.css` reading the three glass tokens, applied to the
sidebar, top bar, modals, bottom sheets and command palette. Data cards stay
solid — a figure's contrast must not depend on what happens to sit behind it.

Guards:
- `@supports (backdrop-filter: blur(1px))`, with a solid fallback
- `@media (prefers-reduced-transparency: reduce)` → solid
- `@media print` → never
- Auto-solid below a device-memory threshold is **not** attempted; `backdrop-filter`
  support is the proxy, because `navigator.deviceMemory` is absent on iOS and
  unreliable elsewhere.

### 6. Screen — `app/Livewire/Settings/Branding.php`

Live preview of a real dashboard fragment — cards, a chip row, a primary button,
a sidebar — not a row of swatches. A swatch cannot show you that your green makes
button labels vanish.

Each derived role displays its computed contrast ratio, so the owner can see the
guarantee holding rather than trusting it.

Gated on a new `branding.manage` permission, granted to Owner and Admin.

### 7. Platform branding

A singleton record (no `company_id`) drives the marketing site through the same
engine. Platform-owner only.

### 8. API

`GET /api/v1/branding` — current inputs plus derived palette
`PUT /api/v1/branding` — update inputs

Under the existing `read` / `write` abilities and `branding.manage`.

---

## Testing

| Area | Test |
|---|---|
| Colour maths | Round-trip sRGB→OKLCH→sRGB within 1/255; luminance and contrast against published WCAG values |
| Derivation | A corpus of hostile seeds — Spotify green, Airbnb red, pure yellow `#FFFF00`, pure black, pure white, near-white `#FEFEFE` — every one must produce a palette passing every existing `ColourContrastTest` assertion, in light and dark |
| Regression | The platform default inputs must derive a palette matching today's hand-tuned values within a small delta, proving the generator reproduces a known-good design |
| Tenancy | Company A's branding never leaks into Company B's request |
| Glass | Print stylesheet contains no `backdrop-filter`; reduced-transparency yields solid |
| Screen | Non-permitted roles cannot reach it; invalid hex rejected |
| API | Scope enforcement; derived output matches the screen's |

The hostile-seed corpus is the centre of gravity. It is the difference between
"we generate palettes" and "we cannot generate a broken palette".

---

## Risks

**Density moves every gap at once.** The range is capped narrowly
(`--spacing` 0.22–0.28rem) rather than offered as a free slider. A wider range
will break layouts in places no test covers.

**Secondary has no natural home.** The design system has `brand` plus seven fixed
accents. Secondary maps onto the accent family used by quick actions and charts.
If that proves too subtle to feel like a real second brand colour, the fallback
is to also use it for selected/active navigation states.

**Glass on chrome still costs GPU.** Cheaper than glass everywhere, but not free.
If sidebar scrolling degrades on low-end Android, the mitigation is to drop blur
radius rather than remove the feature.

---

## Out of scope

Typography and font selection · per-user themes (this is per-company) ·
custom CSS injection · logo redesign (already shipped) · glass on data cards ·
theming the printed document layout beyond colour.
