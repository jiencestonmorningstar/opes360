# Branding

How a business re-skins the platform, and how to add something new that follows
along.

---

## The one-sentence version

The owner picks a **seed** colour; the platform **derives** every colour that
carries text from it, so nothing they choose can make the product unreadable.

---

## Why derive at all

A brand colour is a signature, not a text colour. Measured against this app's
page background (`#eef2f7`), where the WCAG AA floor for normal text is 4.5:1:

| Brand | vs white | vs canvas |
|---|---|---|
| Spotify green `#1DB954` | 2.59 | 2.30 |
| Airbnb Rausch `#FF385C` | 3.52 | 3.13 |
| Stripe indigo `#635BFF` | 4.70 | **4.18** |
| Linear indigo `#5E6AD2` | 4.70 | **4.18** |
| Shopify green `#008060` | 4.93 | **4.39** |
| Slack aubergine `#4A154B` | 14.00 | 12.45 |

Only Slack's aubergine works in both roles. Spotify puts *black* text on its
green button precisely because white fails there.

The bolded rows are the reason this is a derivation engine and not a warning
label. Those three are perfectly legal as buttons and illegal as body text, by a
margin of roughly a third of a point. Nobody sees that in a colour picker.

Contrast is **symmetric** — the ratio between two colours is one number
whichever is the text. The two roles differ by *which background they must
survive*: a button fights white, body text has to hold on canvas, and canvas is
darker, so canvas binds.

---

## The pieces

| File | Does |
|---|---|
| `app/Support/Colour.php` | sRGB ↔ OKLCH, WCAG luminance and contrast, `toContrast()` |
| `app/Support/BrandDefaults.php` | Platform defaults, the neutral ramps, radius scales, and the fixed semantics |
| `app/Support/BrandPalette.php` | Inputs → the complete light/dark/root token map |
| `resources/views/components/branding/styles.blade.php` | Emits the tokens into `<head>` |
| `app/Livewire/Business/Branding.php` | The screen |
| `app/Http/Controllers/Api/BrandingController.php` | `/api/v1/branding` |

Storage is `companies.branding`, a nullable JSON column holding **inputs only**.
Null means platform default.

The derived palette is never persisted. That is deliberate: the derivation is
the part most likely to improve, and storing its output would strand every
existing company on whatever the generator produced the day they saved.

---

## Why `:root` overrides work

Tailwind v4 compiles every utility to a variable reference:

```css
.bg-fill-brand { background-color: var(--color-fill-brand) }
.text-ink      { color: var(--color-ink) }
.p-3           { padding: calc(var(--spacing) * 3) }
.rounded-xl    { border-radius: var(--radius-xl) }
```

So redefining those variables on `:root` re-skins the platform with **zero
component changes**. Density is one variable (`--spacing`); shape is one scale
(`--radius-*`).

The block is emitted inline in `<head>`, after the Vite tag. Inline because a
linked stylesheet would be a second request for the offline shell to cache, and
because anything applied after first paint flashes the default blue first.

---

## The three roles

Every hue carries three tokens, and picking the wrong one is the usual mistake:

| Token | Role | Guarantee |
|---|---|---|
| `--color-<hue>` | **ink** — text, icons, borders | ≥4.5:1 on canvas, surface, surface-2 and its own tint |
| `--color-fill-<hue>` | **fill** — solid backgrounds under white text | ≥4.5:1 against white |
| `--color-tint-<hue>` | **wash** — chips, icon bubbles | the matching ink reads on it |

Use `text-brand` for a link, `bg-fill-brand` for a button, `bg-tint-brand` for a
chip. Never `bg-brand`.

---

## What cannot be branded

- **Semantic colours.** `positive`, `warning` and `negative` are fixed. A
  business must not be able to make "overdue" green; those three mean the same
  thing in every company on the platform.
- **Typography.** Views carry 2453 hardcoded `text-[Npx]` values, so type is not
  tokenised. A font control would be a two-thousand-file change with no safe
  rollback.
- **Data cards under glass.** See below.

---

## Glass

`skin: glass` turns the sidebar, top bar and bottom nav translucent. Cards
holding figures stay solid, always.

That boundary is the point. A total's legibility must not depend on what happens
to be scrolling behind it, and `backdrop-filter` is the most expensive thing a
page can ask a GPU for — this product runs as an offline PWA on budget Android.

Three guards, each verified against the *compiled* stylesheet rather than the
source:

- `@supports (backdrop-filter: …)` — solid fallback where unsupported
- `@media (prefers-reduced-transparency: reduce)` — solid, no argument
- `@media print` — never; a blur costs ink and prints as a grey smear

Apply it with `BrandPalette::skinClass()`, which returns `glass` or an empty
string, rather than always carrying the class:

```blade
@php $glass = \App\Support\BrandPalette::skinClass(); @endphp
<nav class="border-t {{ $glass ?: 'border-border bg-surface' }} {{ $glass }}">
```

`.glass` and `bg-surface` are utilities of the same specificity, so which wins
depends on their order in the compiled sheet, not on the class attribute. In
solid mode nothing may change at all.

---

## Adding a new brandable token

1. Emit it from `BrandPalette::mode()` (per theme) or `::shape()` (theme-agnostic).
2. Make sure its **value shape** passes the whitelist in `styles.blade.php` — a
   hex colour, a length, an `rgb()` or a `var()`. Anything else is silently
   dropped, and `BrandingDeliveryTest::test_every_derived_token_survives_the_whitelist`
   will fail if you get it wrong.
3. If it carries text, add it to the assertions in `BrandPaletteTest` so the
   thirteen hostile seeds have to satisfy it too.

That third step is not optional bookkeeping. The hostile-seed corpus — Spotify
green, Airbnb red, pure yellow, pure cyan, pure magenta, pure black, pure white,
near-white, near-black, mid grey, aubergine — is what makes "cannot generate a
broken palette" a true statement rather than a hope. A token that is not
asserted is not guaranteed.

**If a hostile seed fails, the generator is wrong. Never loosen the assertion.**

---

## Customer-facing pages

A customer opening a shared form, event, verification link or public profile is
not signed in, so nothing infers the company. Those pages pass it:

```blade
<x-layouts.public :brand-company="$company">
```

The marketing site deliberately does not follow a tenant — it is ours, not
theirs.

---

## Testing map

| Test | Covers |
|---|---|
| `tests/Unit/ColourTest.php` | Colour space maths, gamut mapping, contrast |
| `tests/Feature/BrandPaletteTest.php` | The hostile-seed corpus — the real guarantee |
| `tests/Feature/BrandingStorageTest.php` | Column, cache invalidation, `brandToken()` |
| `tests/Feature/BrandingDeliveryTest.php` | The `<style>` block, injection, whitelist |
| `tests/Feature/BrandingGlassTest.php` | Skin switching and the compiled guards |
| `tests/Feature/BrandingScreenTest.php` | The screen, validation, permissions |
| `tests/Feature/BrandingApiTest.php` | Endpoints, scopes, partial updates |
| `tests/Feature/BrandingPublicPagesTest.php` | Customer-facing pages and leakage |
