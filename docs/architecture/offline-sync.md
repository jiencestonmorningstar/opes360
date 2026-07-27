# OPES360 — Offline Engine & Sync Protocol

Built in **Phase 4**, consumed by Phases 5–7. This is the highest-risk subsystem in the product: a sync bug
does not show up as a crash, it shows up as a customer's invoice quietly disappearing or being duplicated.

---

## 1. Design principles

1. **The device is authoritative for creation, the server is authoritative for truth.** A device may create
   records freely offline; the server decides final ordering, numbering and validity at sync.
2. **Every write is idempotent.** Replaying the outbox after a crash, a timeout or a duplicated request must
   never produce a second record.
3. **Issued documents are immutable** (`decisions.md` #6). This removes most conflicts by construction rather
   than resolving them after the fact.
4. **Never silently drop a user's work.** A record that cannot sync is surfaced to the user with a clear
   reason and an action, never discarded and never retried forever in silence.
5. **Offline is the normal case, not the error case.** The UI does not nag about being offline; it shows
   state calmly and keeps working.

---

## 2. Client-side storage

**IndexedDB**, one database per company (so switching companies cannot mix data), with object stores:

| Store | Contents |
|---|---|
| `entities/{type}` | Local mirror of synced records — customers, products, documents, payments |
| `outbox` | Pending mutations awaiting upload, in creation order |
| `number_leases` | Allocated document-number ranges (see §5) |
| `meta` | Sync cursors per entity type, device token, last-sync timestamps |
| `blobs` | Images and generated assets pending upload |

Large media goes in `blobs` and uploads separately from the record mutation, so a 4MB product photo on a slow
connection never blocks an invoice from syncing.

---

## 3. The outbox

Every offline mutation appends an envelope:

```json
{
  "id":              "01J8K2M4...",       // ULID, also the idempotency key
  "entity_type":     "invoice",
  "entity_id":       "01J8K2M3...",       // ULID generated on the device
  "operation":       "create",            // create | update | void
  "payload":         { },
  "client_version":  3,
  "base_version":    2,                    // server version this edit was based on
  "created_at":      "2026-07-27T09:14:02Z",
  "device_id":       "dev_01J8...",
  "attempts":        0,
  "status":          "pending"             // pending | inflight | done | failed | quarantined
}
```

**Replay rules**
- Strictly ordered per entity, parallel across unrelated entities.
- The server deduplicates on `id`; a repeated envelope returns the original result rather than creating again.
- Exponential backoff on transient failures: 1s, 2s, 4s … capped at 5 minutes.
- **Permanent failures** (validation errors, permission denied, stale version) do not retry. They move to
  `failed`, surface in the UI with the server's reason, and offer retry-after-edit or discard.
- After 10 consecutive transient failures a record is `quarantined` and reported — this is how we find bugs
  before customers do.

---

## 4. Sync protocol

Two endpoints, versioned under `/api/sync/v1`.

### Pull — `GET /api/sync/v1/pull`

```
?company=01J8...&since[customer]=cursor&since[product]=cursor&limit=500
```

Returns changed records per entity type since each cursor, plus new cursors and a `has_more` flag for
pagination. Cursors are opaque (server sequence + timestamp), never client-computed.

### Push — `POST /api/sync/v1/push`

Accepts a batch of outbox envelopes. Returns per-envelope results:

```json
{
  "results": [
    { "id": "01J8K2M4...", "status": "applied",   "server_version": 3, "assigned_number": "INV-2026-00042" },
    { "id": "01J8K2M5...", "status": "duplicate", "server_version": 3 },
    { "id": "01J8K2M6...", "status": "conflict",  "server_version": 5, "server_record": { } },
    { "id": "01J8K2M7...", "status": "rejected",  "error": "customer_not_found" }
  ]
}
```

Each envelope applies inside its own database transaction. A batch is **not** all-or-nothing: one bad record
must not block twenty good ones.

### Conflict rules

| Situation | Resolution |
|---|---|
| Draft edited on two devices | Last-write-wins by server receipt time; the loser is preserved as a revision and the user is told |
| Issued document edited | Impossible by design — rejected |
| Record deleted on server, edited on device | Server wins; the device edit is surfaced as failed with an explanation |
| Two devices create the same logical record | Both are created (different ULIDs); duplicate detection is a UI-level merge suggestion, not an automatic merge |
| Stock movement from two devices | Additive — movements are append-only events, never absolute-value writes |

That last row matters: **inventory is modelled as an append-only movement ledger, not a mutable quantity
column.** Two devices selling the same item offline both append `-1`, and both are correct. A quantity column
would have them overwrite each other.

---

## 5. Document numbering offline — the hard problem

A trader hands a customer a printed receipt while offline. That receipt needs a **final** number on it. It
cannot say "PENDING", and it cannot be renumbered later, because the customer already has the paper.

### Rejected approaches

- **Assign at sync.** Clean sequences, but the printed paper is wrong. Unacceptable for receipts.
- **Device-prefixed numbers** (`INV-A-001`, `INV-B-001`). Never collides, but produces ugly parallel sequences
  that many tax authorities will not accept as a single sequential series.
- **Timestamp/random numbers.** No sequence at all; fails compliance almost everywhere.

### Chosen approach: number leases

Each device leases blocks of numbers per company, per document type, per year, while it has connectivity:

```
lease  { company, type: "invoice", year: 2026,
         range_start: 41, range_end: 90,
         device_id, issued_at, expires_at }
```

- The server allocates blocks strictly sequentially and records every allocation in a **lease ledger**.
- The device consumes numbers from its block offline, in order.
- On sync, each used number is confirmed against the ledger and bound to its document.
- Unused numbers in an expired or revoked lease are marked `void_unused` in the ledger — a permanent,
  auditable record of *why* a gap exists.
- Block size adapts to usage (a busy till leases 200, an occasional user leases 20); low-water-mark triggers
  a top-up while still online.
- **Running out of lease while offline** is the failure case that must be designed for, not hoped away:
  the device falls back to a clearly-marked provisional number, the UI warns before the block is exhausted,
  and provisional documents are flagged for renumbering at sync with an explicit user confirmation step.

### The compliance question

Sequence gaps are the cost of this design. Most jurisdictions accept gaps when they are documented and
auditable, which the lease ledger provides. **Some do not.** This must be verified against your launch
jurisdictions in Phase 4 — it is listed as risk #2 in `PLAN.md` and it is a genuine go/no-go input, because
the alternative (online-only invoicing) contradicts a core product promise.

---

## 6. Device authorization

- Each installed PWA registers as a device and receives a scoped sync token bound to user + company + device.
- Tokens are revocable from the web dashboard; revocation blocks pull and push immediately.
- A revoked device keeps its local data readable but cannot sync — with a clear message, not a silent failure.
- Device list shows name, platform, last sync, pending record count.

**Open question:** should a revoked or lost device's local data be wiped on next launch? Wiping protects the
business; it also destroys unsynced work. Recommendation: wipe only on explicit "revoke and erase", with the
consequence spelled out in the confirmation.

---

## 7. iOS constraints — plan around these, don't discover them

| Capability | Chromium (Android/desktop) | iOS Safari |
|---|---|---|
| Service worker | Yes | Yes |
| IndexedDB | Yes | Yes, but evicted after ~7 days of no use in some versions |
| Background Sync API | Yes | **No** |
| Periodic Background Sync | Yes | **No** |
| Web Push | Yes | Yes, only for home-screen-installed PWAs (iOS 16.4+) |
| Web Bluetooth / WebUSB | Yes | **No** |
| Storage quota | Generous | Tighter, and evictable |

**Consequences designed in from the start:**
- Foreground sync (on load, on `visibilitychange`, on `online`, and on a timer while the app is open) is the
  **primary** path. Background Sync is an enhancement, never the only route to durability.
- `navigator.storage.persist()` is requested on install to reduce eviction risk, and the app warns if
  persistent storage is refused while records are pending.
- Unsynced work is never left implicit: a persistent, visible pending count, and a warning before the app is
  closed with pending records.

---

## 8. Testing — non-negotiable for this phase

Phase 4 is not done when it works in a demo. It is done when these pass automatically:

1. **Airplane-mode soak:** create 200 records across 4 entity types offline, reconnect, assert exactly 200
   server records, no duplicates, no losses.
2. **Interrupted sync:** kill the connection mid-batch, resume, assert idempotency.
3. **Two-device convergence:** same account, both offline, overlapping edits, both sync — assert the
   documented conflict rules produced the documented outcome.
4. **Number lease exhaustion:** consume a full block offline, assert the fallback path and the ledger state.
5. **Clock skew:** device clock 10 minutes off, assert ordering still correct (server time is authoritative
   for ordering; device time is metadata only).
6. **Quota exhaustion:** fill IndexedDB, assert graceful degradation with a clear user message.
