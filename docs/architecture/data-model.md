# OPES360 — Core Data Model (sketch)

A first-pass schema, phase by phase. Detail will firm up as flows arrive — treat this as the shape, not the
final migration set.

**Conventions**
- ULID primary keys on anything creatable offline; auto-increment elsewhere (`decisions.md` #3).
- `company_id` on every business table, enforced by a global scope (`decisions.md` #2).
- `created_at`, `updated_at`, `deleted_at` (soft deletes) everywhere.
- `created_by`, `updated_by` on user-authored records.
- Foreign keys with explicit `ON DELETE` behaviour; `RESTRICT` on financial records, never `CASCADE`.
- InnoDB, `utf8mb4_0900_ai_ci`.

---

## Phase 0 — Foundation

```
users               id, name, email, password, two_factor_secret,
                    two_factor_recovery_codes, current_company_id, timestamps

companies           id(ULID), slug(unique), name, motto, description, industry,
                    registration_number, tax_id(enc), vat_number(enc),
                    address, city, region, country, postal_code,
                    latitude, longitude, website, email, phones(json),
                    socials(json), operating_hours(json),
                    logo_path, banner_path, brand_tokens(json),
                    owner_id, status, timestamps, soft deletes

company_user        company_id, user_id, role_id, status, invited_at, joined_at
                    unique(company_id, user_id)

roles               id, name, slug, is_system            -- 7 seeded roles
permissions         id, name, slug, group
role_permission     role_id, permission_id
user_permission     company_id, user_id, permission_id, granted  -- per-user overrides

activity_log        id, company_id, user_id, subject_type, subject_id,
                    event, properties(json), ip, user_agent, created_at
                    index(company_id, created_at), index(subject_type, subject_id)

devices             id, user_id, company_id, name, platform, token_hash,
                    last_synced_at, pending_count, revoked_at, timestamps
```

`companies.brand_tokens` holds the Phase 2 brand kit (palette, type scale, logo variants) as JSON, read by
every template downstream. Keeping it denormalised on the company is deliberate: it is read constantly and
written rarely.

---

## Phase 1 — Identity & verification

```
verification_tokens id, token(unique, 22 chars), subject_type, subject_id,
                    company_id, revoked_at, timestamps
                    index(subject_type, subject_id)

verification_scans  id, token_id, scanned_at, country, region,
                    device_class, referrer
                    index(token_id, scanned_at)

qr_codes            id, company_id, type, subject_type, subject_id,
                    token_id, svg_path, png_path, options(json), timestamps

media               id(ULID), company_id, collection, disk, path,
                    mime, size, width, height, checksum,
                    attachable_type, attachable_id, sort_order, timestamps
```

One polymorphic `media` table rather than per-entity image columns — logos, banners, product photos,
portfolio images and document attachments all land here, which keeps the offline blob-upload path uniform.

---

## Phase 2–3 — Branding & stationery

```
brand_kits          id, company_id, palette(json), typography(json),
                    icon_set(json), guidelines_pdf_path, timestamps

logo_designs        id, company_id, template_key, config(json),
                    svg_path, png_paths(json), pdf_path,
                    is_active, generated_by, timestamps

stationery_assets   id, company_id, type, -- letterhead|card|signature|stamp
                    size,                 -- a4|a3|85x55|circular|square|oval
                    template_key, config(json),
                    pdf_path, docx_path, preview_path, timestamps
```

`config(json)` on both design tables holds the parametric template inputs, so any asset can be regenerated
from scratch after a template or brand change — the stored files are a cache, not the source of truth.

---

## Phase 5 — CRM, products, inventory

```
contacts            id(ULID), company_id, type,   -- customer|supplier|vendor|lead
                    name, company_name, email, phones(json),
                    address(json), tax_id(enc), tax_id_index,
                    credit_limit, payment_terms, notes, tags(json),
                    balance, timestamps, soft deletes
                    index(company_id, type), index(company_id, tax_id_index)

contact_notes       id(ULID), company_id, contact_id, user_id, type, body, created_at

categories          id(ULID), company_id, parent_id, name, slug, sort_order

items               id(ULID), company_id, type,   -- product|service
                    category_id, sku, barcode, name, description,
                    unit, price, cost, tax_rate_id, track_stock,
                    reorder_level, is_active, timestamps, soft deletes
                    unique(company_id, sku)

stock_movements     id(ULID), company_id, item_id, quantity,  -- signed, append-only
                    reason, reference_type, reference_id,
                    user_id, device_id, occurred_at, created_at
                    index(company_id, item_id, occurred_at)

tax_rates           id, company_id, name, rate, is_compound, is_default
```

**`stock_movements` is append-only and additive** — never an absolute quantity write. Two devices selling the
same item offline each append `-1` and both are correct; a mutable `quantity` column would have them silently
overwrite each other. Current stock is the sum, cached in a projection if it becomes a hot query.

---

## Phase 6 — Sales documents

```
documents           id(ULID), company_id, type,
                    -- quotation|invoice|proforma|purchase_order
                    -- |delivery_note|credit_note|debit_note
                    number, number_lease_id, contact_id,
                    status,          -- draft|issued|sent|accepted|paid|partial|void|expired
                    issue_date, due_date, valid_until,
                    currency, exchange_rate,
                    subtotal, discount_total, tax_total, total, amount_paid, balance,
                    notes, terms, reference,
                    parent_document_id,        -- quotation -> invoice lineage
                    content_hash,              -- tamper detection, set at issue
                    issued_at, issued_by, signature_path,
                    token_id, pdf_path,
                    device_id, synced_at, timestamps, soft deletes
                    unique(company_id, type, number)
                    index(company_id, type, status, issue_date)

document_lines      id(ULID), company_id, document_id, item_id,
                    description, quantity, unit, unit_price,
                    discount_type, discount_value,
                    tax_rate_id, tax_amount, line_total, sort_order

document_approvals  id, company_id, document_id, user_id,
                    action, comment, created_at

number_leases       id, company_id, document_type, year,
                    range_start, range_end, next_available,
                    device_id, issued_at, expires_at,
                    status,          -- active|exhausted|expired|revoked
                    void_unused_from -- audit trail for sequence gaps
                    index(company_id, document_type, year)
```

`content_hash` is written once at issue and never again — that plus immutability (`decisions.md` #6) is the
whole tamper-detection mechanism.

---

## Phase 7 — Payments & receipts

```
payments            id(ULID), company_id, contact_id, method,
                    -- cash|bank_transfer|mobile_money|card
                    amount, currency, exchange_rate,
                    reference, provider, provider_reference,
                    received_at, received_by, device_id,
                    notes, timestamps, soft deletes

payment_allocations id(ULID), company_id, payment_id, document_id, amount

receipts            id(ULID), company_id, payment_id, contact_id,
                    number, number_lease_id, format,  -- a4|a5|thermal58|thermal80
                    total, status, issued_at, cashier_id,
                    content_hash, signature_path, token_id, pdf_path,
                    device_id, synced_at, timestamps
                    unique(company_id, number)
```

A payment is separate from a receipt because one payment can settle several invoices
(`payment_allocations`), and part-payments are the normal case in this market, not an edge case.

---

## Phase 9 — Artisans

```
artisans            id(ULID), company_id, user_id, slug(unique),
                    full_name, artisan_number, photo_path,
                    occupation, trade_category, skills(json),
                    biography, years_experience, certifications(json),
                    languages(json), coverage_area(json),
                    phones(json), whatsapp, email, website,
                    address(json), latitude, longitude,
                    working_hours(json), emergency_contact,
                    socials(json), token_id, is_verified,
                    timestamps, soft deletes

artisan_testimonials id, artisan_id, author_name, rating, body,
                     is_published, created_at
```

Artisans reuse `media` for portfolio images and video, and `verification_tokens` for QR/AR — which is why
Phase 9 is only 2–3 weeks despite the long field list.

---

## Indexing notes

- Every `company_id` column is the **leading** column of its composite indexes. Tenant scoping is in every
  query, so an index that does not start with `company_id` will not be used.
- Document list screens sort by `issue_date DESC` within a type and status — covered by
  `index(company_id, type, status, issue_date)`.
- `verification_tokens.token` is the hottest public lookup; unique index, and cache resolved subjects.
- `stock_movements` grows fastest of any table. If summing becomes slow, add a periodically-refreshed
  `item_stock_snapshots` projection rather than denormalising a mutable quantity onto `items` — that would
  reintroduce exactly the concurrency bug the append-only design avoids.
