# OPES360 — Flows

Drop your flows here. One file per flow, named `NN-short-name.md` (e.g. `01-onboarding.md`). I'll fold each
one into its phase in `../PLAN.md` and expand that phase into implementable tasks.

Rough notes, bullet points, screenshots or a paste of how you'd describe it out loud are all fine — the
template below is a prompt, not a requirement. The parts that actually change the build are marked ★.

---

## Template

```markdown
# Flow: <name>

**Phase:** <which phase from PLAN.md, or "not sure">
**Actor:** <who is doing this — role from Module 15 if relevant>
**Trigger:** <what starts it>
**Outcome:** <what exists or has changed when it's done>

## Steps
1. …
2. …
3. …

## ★ Offline behaviour
- Can this happen with no connection?
- If yes, what does the user see, and what happens when the connection returns?
- Is anything printed or handed to a customer during the offline portion?

## ★ Decisions & permissions
- Which roles can do this? Which can only view?
- Anything requiring approval before it takes effect?

## Rules
- Validation, required fields, limits.
- What is not allowed, and what the user sees when they try.

## Edge cases
- What happens when it fails, is cancelled, or is done twice?
```

---

## Priority order

These four are the highest-leverage, roughly in order:

1. **Onboarding** (Phase 1) — signup → company creation → first asset. Sets navigation and information
   architecture for the whole app.
2. **Sales document flow** (Phase 6) — quotation → invoice → receipt, including who approves what and where
   money is recorded.
3. **Offline scenarios** (Phase 4) — the real field situations. How long offline, how many devices per
   company, and whether paper is handed to a customer while offline. These answers decide the numbering
   design in `../architecture/offline-sync.md` §5.
4. **Verification landing** (Phases 1/8) — what someone scanning a receipt QR should see and be able to do.

## Why the offline flow matters most for design

The other three are mostly UX. The offline one is architecture: if a receipt is printed and handed over while
offline, the number on that paper must be final, which forces the number-leasing design and its compliance
implications. If receipts are only ever issued online, a much simpler design is available. Worth answering
early.
