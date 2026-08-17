# QR branding, print colours, and seal watermarks — handoff

Three related asks, shipped together on 2026-08-17/18: printed receipts and
documents carry the company's own colour, every QR always shows the
company's icon at its centre, and a business can generate one of six
official-seal-style watermarks (or upload its own) to print faintly behind
its paperwork.

## QR centre-logo overlay

`App\Services\QrCodes::svg()` gained an optional `Company $brand` parameter.
When given, and the company has a logo, and the code is being rendered at
the default H error-correction level, the logo is stamped in the centre on
a white backing plate:

```php
$qr->svg($token->publicUrl(), 120, brand: $company);
```

- **Why H-only**: level H spends 30% of the code on error recovery, which is
  exactly the margin a centre logo eats into. A code explicitly rendered at
  M (business cards, printed very small — see the existing docblock) never
  gets an overlay; stamping one there risks the code not scanning at all.
- **Self-contained**: the logo is inlined as a `data:` URI read straight off
  the `public` disk, never fetched by URL — the SVG never depends on a
  second network request to render correctly, same reasoning as every other
  print-embedded image in this product.
- **Wired into every company-branded QR the platform generates**:
  `PrintController::document()`, `::paper()`, `::statement()`, `::receipt()`,
  `::loyaltyCard()`, `::vipCard()`, `::stationery()`, `::partnerCard()`,
  `::waybill()`, `DeliveryNotePrintController`, `VerificationController::qr()`
  (the public `/v/{token}/qr` endpoint behind every printed code), and the
  event ticketing share QR (`Livewire\Events\Show`). `stationery()` and
  `partnerCard()` pass `level: M` for the business-card asset specifically
  (small physical size, already established before this change) — `QrCodes`
  itself refuses the overlay at any level but the default H, so those two
  correctly get no overlay on the card face without any special-casing here.
  **Deliberately not touched**: the 2FA provisioning QRs
  (`AuthController`, `Admin\TwoFactorController`) — those encode a personal
  authenticator secret, not a company-branded document, and are not company
  data at all.
- **Tests**: `tests/Feature/Branding/QrLogoOverlayTest.php`.

## Print colours (§ "receipts and documents should also be branded")

`document.blade.php`'s document-type label (INVOICE/QUOTATION/…) and
`receipt.blade.php`'s PAID total now read `$company->brandToken('primary',
…)` into a `--brand` CSS variable, instead of a hardcoded `#2563eb`.
`paper.blade.php` (generated business documents/contracts) already did this
— its `.design-banner`/`.design-sidebar`/`.design-crest` letterhead designs
were built brand-aware from the start; only `document.blade.php` and
`receipt.blade.php` had the gap.

Deliberately kept to **one accent touch per template** rather than
recolouring every line — a printed page needs to stay legible in black ink
on any paper stock; the brand colour marks the single most identifying
element (what kind of document this is / what was paid), not the whole
page. Screen-only UI chrome (the "Print or Save as PDF" button) stays the
platform's own blue, since it never reaches the printed page itself.

Falls through `brandToken()`'s own resolution order (explicit `brand_tokens`
→ derived default palette → caller's literal fallback) — a company that has
never touched branding still gets a real colour from the platform's default
palette, never literally `#2563eb` for every business that hasn't opted in.

## Seal watermarks (§ "6 variant seal-like watermark templates")

- **`App\Support\SealCatalog`**: the six design keys (`starburst`, `laurel`,
  `shield`, `compass`, `monogram`, `sunburst`) with label/description —
  mirrors `CardCatalog`'s shape but carries only *which emblem and ring
  layout*, not a full SVG per design (see below for why).
- **`App\Services\SealComposer`**: draws a design to SVG from primitives —
  concentric rings, ring text along `<textPath>` arcs (top: company name,
  bottom: registration number or custom text), and a central emblem built
  from basic shapes (a star polygon, leaf ellipses for the laurel wreath, a
  shield path, a compass-rose of alternating diamonds, bold monogram
  initials, or sunburst rays). **Generative, not a static asset per design**:
  a seal has to carry the *company's own* name and registration text, so
  the composer takes those as input the same way `LogoComposer` takes a
  name and tagline — this is also what keeps every design honestly original
  rather than tracing any real seal's specific artwork (no eagle, no
  specific coat of arms, no government emblem).
- **`companies.watermark_path` / `watermark_seal` / `show_watermark`**
  (migration `2026_09_18_000004_add_watermark_to_companies`): the saved
  image (generated seal or uploaded custom), which design produced it (null
  for an upload), and whether it is switched on. `show_watermark` defaults
  false — every existing printed document looks exactly as it did before
  this shipped until a business opts in.
- **`App\Livewire\Business\Watermark`** (`/business/watermark`, in the
  Business sub-nav): pick a design with a live preview, optionally add a
  bottom-ring text override, "Generate & save" — or upload a custom image
  instead (`UploadGate::accept($file, 'image')`, same gate every other image
  upload in the product passes through). A toggle switches printing on/off,
  refused (422) until a watermark actually exists; "Remove" clears
  everything and switches it back off.
- **Print integration**: `document.blade.php` and `paper.blade.php` now
  check `$company->show_watermark && $company->watermarkUrl()` first,
  falling back to the pre-existing faint-logo watermark
  (`$company->logoUrl()`) when no dedicated watermark is configured — so a
  business that never visits the new screen sees no change at all. Not
  wired into `receipt.blade.php`: a seal-sized background image is visual
  noise on a till slip, thermal or A4; receipts keep the plain brand-colour
  touch above instead.
- **Deliberately not built**: opacity/size controls (fixed at 5%, matching
  the existing logo-watermark's own fixed opacity), positioning options,
  more than one watermark per company, and the status
  (DRAFT/VOID/COPY)/confidential-footer watermark from
  `docs/handoff/2.17-watermarks.md` staying completely separate — that one
  answers a different question (validity/provenance) and is not
  configurable by design; this one is purely decorative branding.
- **Tests**: `tests/Feature/Branding/SealComposerTest.php` (all 6 designs
  produce valid SVG, ring text, colour, XSS-safety), `tests/Feature/
  Branding/WatermarkScreenTest.php` (generate/upload/toggle/remove,
  permission gate), `tests/Feature/PrintFidelityTest.php` (watermark
  actually appears on a printed document/paper once switched on, and stays
  absent while off).
