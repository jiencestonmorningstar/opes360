# PDF engine (Phase 5)

Real PDF output for everything that previously only knew `window.print()`.

## Engine

`barryvdh/laravel-dompdf` (dompdf) — pure PHP, no daemon, no paid vendor,
safe on shared hosting. It is wrapped entirely by **`App\Support\Pdf`**; no
other class touches dompdf. Moving to headless Chromium (Browsershot /
Gotenberg) on the VPS later is a change to that one class — same
`render()` / `download()` signatures, same blade views.

- Paper size and orientation are always explicit (A4 portrait by default) —
  dompdf will not infer them.
- Remote fetching stays off. `Pdf::html()` inlines images that live on the
  app's own public disk (the company logo) as data URIs; any other http(s)
  image becomes a transparent pixel, never a server-side fetch.
- `Pdf::filename(...parts)` builds the ASCII-safe attachment name:
  reference + title, non-ASCII transliterated, everything else collapsed to
  hyphens, `document.pdf` as the floor.

## Endpoints

`?format=pdf` on the existing print routes (handled inside the controllers —
no new routes):

| Endpoint | Filename |
|---|---|
| `documents.print` (invoices etc.) | `{number}-{type}.pdf` |
| `papers.print` (business documents) | `{reference}-{title}.pdf` |
| `payslips.print` | `Bulletin-{employee}-{period}.pdf` |
| `customers.statement` | `Statement-{contact}-{from}-{to}.pdf` |
| `shares.show` (public, token) | `{reference}-{title}.pdf` — requires `allow_download` |
| `signatures.show` (public, token) | `{reference}-{title}.pdf` — only after the signature is signed |

The share and signature pages carry a "Download PDF" button. Both public
paths render inside `CurrentCompany::as($company)` resolved from the token,
so a PDF can only ever carry its own tenant's data.

## Watermarks and audit

- The same `App\Support\Watermarks` marks reach the PDF: DRAFT / VOID on the
  status layer, COPY on external copies (share and signature downloads), and
  the CONFIDENTIEL footer naming company + viewer (share identity on the
  share path, signer name on the signature path).
- Restricted/confidential papers write the same export-tier
  `Audit::record($paper, 'exported', ...)` row for PDF as for print, with
  `export: pdf` in the metadata. Two downloads are two rows, on purpose.

## Bundles

`DocumentBundles` now renders composed documents (template papers with no
uploaded file) into the ZIP as PDFs instead of skipping them — watermarks
included, so a draft in a bundle still says DRAFT.

## Rendering notes / limits

- The PDFs render the same blade views the browser-print path uses — one
  source of truth for the data. dompdf's CSS subset renders flexbox layouts
  more plainly than Chromium; content, marks, and totals are all present,
  layout polish is the future engine swap's win.
- `autoprint` is forced off on the PDF branch (no JS in a PDF).

## Tests

`tests/Feature/PdfOutputTest.php` — %PDF magic, filename convention, DRAFT
watermark present in extracted PDF text, restricted-paper audit row, share
tenancy isolation, download gating. Run:

```
php artisan test --filter="Pdf|Print|Watermark"
```
