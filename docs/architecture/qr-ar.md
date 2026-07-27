# OPES360 — QR & AR Verification Design

Covers Module 3 (Smart QR & AR Identity System) and the verification pages behind Modules 4, 5, 6 and 7.
QR ships in **Phase 1**; AR ships in **Phase 8** on top of the same foundation.

---

## 1. One token, two renderers

Every verifiable thing — a company, an artisan, an invoice, a quotation, a receipt, a business card — gets one
immutable public token at creation:

```
verification_tokens
  token        CHAR(22)   unique, unguessable, immutable
  subject_type string     company | artisan | document | receipt | card
  subject_id   ULID
  company_id   ULID
  created_at
  revoked_at   nullable
```

The QR encodes `https://opes360.com/v/{token}`. The AR marker resolves to the same token. **Both renderers
read the same record and can never disagree** about whether a document is valid — which is the entire point
of a verification feature.

**Token properties**
- 22 characters, 128 bits of entropy, base62. Not sequential, not derived from the record ID: a competitor
  must not be able to enumerate a business's invoices by incrementing a URL.
- Immutable for the life of the subject. A printed QR from 2026 must still resolve in 2036.
- Revocable without deletion — a revoked token resolves to a page saying *this document was voided*, which is
  far more useful to the scanning customer than a 404.

---

## 2. QR generation

Server-side, `bacon/bacon-qr-code`, rendered to **SVG** and cached to storage.

- SVG because these get embedded in print-resolution PDFs; a raster QR at A3 letterhead scale looks amateur.
- PNG variants generated on demand for contexts that need raster (email signatures, thermal receipts).
- Error correction level **H** (30%) on anything printed, so a scuffed or partly-obscured receipt still scans.
- Logo overlay in the centre at up to 20% area — safe at level H, and it is what makes the codes look like the
  business's own.
- Quiet zone enforced in every template. This is the single most common cause of codes that "sometimes don't
  scan".
- **Thermal receipts get their own treatment:** 58mm at low print density is unforgiving. Minimum module size
  is tested on real hardware in Phase 7, not assumed.

**Code types per company** (all generated in Phase 1):

| Code | Resolves to |
|---|---|
| Business QR | Public business profile |
| Contact QR | vCard download / save-contact |
| Website QR | The company's own website |
| Payment QR | Payment details or a payment link |
| Document QR | Per-document verification page |

---

## 3. Verification pages

One route, `/v/{token}`, resolving to a subject-specific panel. Built once in Phase 1; every later document
type plugs in.

**Design rules**
- Public, no auth, no app install required. The person scanning is a *customer*, not a user — they will bounce
  instantly if asked to sign up.
- Server-rendered and fast. These pages get scanned on bad connections in shops and markets.
- **The verdict is the page.** A large, unambiguous status — valid, voided, expired, unknown — above
  everything else. A verification page whose answer requires scrolling has failed.
- Only what the business chose to publish. Tax numbers are opt-in per company (`decisions.md` #10).
- Every scan is logged: timestamp, coarse location, device class. This is genuinely useful analytics for the
  business and it is how tamper attempts get noticed.

**What an invoice or receipt verification shows** (per the spec): authenticity, amount, customer, issuing
business, payment method, date, current status — plus the issuing business's identity and a link to its
profile.

### Tamper detection

At issue, a document's canonical content is hashed (SHA-256 over a normalised representation) and stored. The
verification page recomputes and compares. A mismatch is displayed as **tampered**, not merely invalid,
because the two mean very different things to the person holding the paper. This is cheap to build because
issued documents are immutable (`decisions.md` #6).

---

## 4. AR — an honest assessment

The spec describes a rich AR overlay: logo, verification status, registration details, contacts, gallery,
services, promotional video, navigation, and call/WhatsApp/email/save/share actions. Delivering that on the
open web has real constraints worth stating before Phase 8 starts.

### What "AR code" can mean, and what it costs

| Approach | How it works | Reality |
|---|---|---|
| **Marker-tracked AR** | A printed marker is tracked by the camera; content is drawn anchored to the paper | The full experience. Needs a tracking library (MindAR, AR.js, or a commercial SDK). Camera permission required. Quality varies sharply on low-end Android |
| **WebXR** | Native browser AR | Best quality where supported. **Not supported in iOS Safari** — no WebXR at all |
| **Enhanced 2D "AR-styled" page** | Scan opens an animated, interactive rich page | Works everywhere, no permissions, no tracking failures. Not really AR |

### Recommendation

Ship **all three as one progressive ladder** behind a single token:

1. Detect capability on load.
2. WebXR where available → tracked marker AR where the camera and a library are available → enhanced 2D
   everywhere else.
3. **Make the 2D fallback excellent**, because on iOS — a large share of the target market's higher-value
   customers — it is what most people will actually see.

Do a **one-week spike before committing Phase 8 scope**, testing tracked AR on genuinely mid-range Android
hardware, not on the team's flagship phones. If it does not hold up, ship the ladder without the tracked
tier; the token scheme, the content and the routes are identical either way, so nothing is wasted.

### The marker question

Tracked AR needs a marker with high visual entropy. Options:

- **A distinct printed AR marker** alongside the QR — reliable tracking, but two codes on a business card is
  visually cluttered and confuses users about which to scan.
- **The QR code itself as the marker** — QR codes have excellent tracking entropy, and one code doing both
  jobs is much better design. Slightly more work.
- **The company logo as the marker** — most elegant, least reliable; logo entropy varies wildly and a simple
  wordmark tracks badly.

**Recommendation:** the QR code doubles as the AR marker. One printed code, one scan, two experiences
depending on what the scanning device supports. This also means every document ever printed in Phase 1 is
already AR-ready when Phase 8 lands — which is a strong argument for deciding it now rather than in Phase 8.

**Open:** confirm this before Phase 3 finalises the business card and letterhead templates, since it affects
the printed layout.
