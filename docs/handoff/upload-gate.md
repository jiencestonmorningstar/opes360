# Upload hygiene — one gate for every file (Phase 5)

`App\Support\UploadGate` is now the single place every uploaded file passes
before the product keeps it. `accept(UploadedFile $file, string $purpose)`
enforces, in order: an extension allow-list for the purpose, agreement between
the extension and the mime type sniffed from the file's actual bytes (never the
client's claim), a per-purpose size cap, and — when a ClamAV daemon is
configured — an INSTREAM scan. A refusal is a `RuntimeException` whose message
is fit to show the uploader, and on public paths it never reveals that a
scanner exists.

## Purposes

| Purpose    | Cap   | Accepts |
|------------|-------|---------|
| `document` | 25 MB | pdf, doc/docx, xls/xlsx, odt, txt, csv, png/jpg/jpeg/webp/gif |
| `cv`       | 10 MB | pdf, doc/docx, odt, txt, png/jpg/jpeg (no spreadsheets — public endpoint) |
| `image`    | 4 MB  | png, jpg/jpeg, webp, gif, bmp |
| `import`   | 5 MB  | csv, txt, xls, xlsx |

## Upload-path inventory (every one now routed through the gate)

| Path | Purpose | Call site |
|------|---------|-----------|
| Public CV upload (`/jobs/{token}`) | `cv` | `RecruitmentPipeline::storeCv` — delegates; its `CV_ALLOWED` const now reads `UploadGate::PURPOSES['cv']['allowed']` and the controller's `mimes:` rule still reads that const |
| Managed documents intake | `document` | `DocumentFiler::assertAcceptable` — delegates; `ALLOWED` now reads `UploadGate::PURPOSES['document']['allowed']` |
| Company logo | `image` | `Livewire\Business\Edit::save` (before `LogoProcessor::store`) |
| Artisan photo | `image` | `Livewire\Business\Artisans::save` |
| Bank statement import | `import` | `Livewire\Banking\Index::import` |
| Supplier statement reconcile | `import` | `Livewire\Payables\Reconcile::import` |
| Record imports (screen) | `import` | `Livewire\Imports\Index::preview` |
| Record imports (API) | `import` | `Api\ImportController::validated` |

Nothing else in `app/` takes an `UploadedFile` (checked: no e-signature
attachments, no expense-receipt upload, no other `hasFile`/`->file(` call
sites). Existing strictness is preserved — the recruitment and document checks
were already extension+sniffed-mime and keep their exact refusal messages;
statement/import paths, which previously accepted any `file`, are now narrowed
to tabular shapes.

## ClamAV

One env key, no package. The key is surfaced as `config('services.clamav.socket')`
(`config/services.php`), so it survives `php artisan config:cache` — set it in
`.env` as usual:

```
# The VPS (clamav-daemon installed):
CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl
# or over TCP:
CLAMAV_SOCKET=tcp://127.0.0.1:3310
```

Unset (shared hosting, dev) → `scan()` is a documented no-op and the gate
degrades gracefully to the sniff checks. Configured but unreachable → the
upload passes on sniff checks alone and a warning is logged, so a restarting
daemon never takes intake down. A `FOUND` verdict refuses the file with
"That file could not be accepted. Please try a different file." — deliberately
scanner-silent on public paths. Nothing is ever quarantined to disk; the bytes
are streamed to clamd over the socket (raw INSTREAM framing: `zINSTREAM\0`,
length-prefixed chunks, zero-length terminator) and the temp upload is simply
never stored.

### VPS setup

```
apt install clamav-daemon clamav-freshclam
# allow www-data to reach the socket:
usermod -aG clamav www-data   # or set LocalSocketGroup in clamd.conf
# .env: CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl
```

`StreamMaxLength` in clamd.conf must be at least the largest cap (25M).

## Tests

`tests/Feature/UploadGateTest.php` — all with REAL temp files, never
`UploadedFile::fake()` (the fake reports the mime its name suggests, hiding
exactly the lie the gate catches): PHP script named `cv.pdf` refused; PNG named
`.pdf` refused; `.exe` refused; oversize refused with a readable reason;
no-socket passes clean files; EICAR (padded past the 128-byte AV-trigger
window so Windows Defender leaves the temp file alone) refused when a faked
socket answers FOUND, with a message naming no scanner; unreachable daemon
degrades to sniff-only; INSTREAM framing verified byte-for-byte.

`php artisan test --filter="Upload|Recruitment|Document|Import"`: 550 passed;
3 failures in `BulkActionsTest`/`DocumentBundleTest`/`PublicSharingTest` are a
concurrent session's in-flight bundle/PDF work (zip entry counts), unrelated
to the gate.
