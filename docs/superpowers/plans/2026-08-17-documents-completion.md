# Documents module — final completion pass

Everything in GAP-ANALYSIS.md Part 1 "Not built (C)" that has no missing
prerequisite gets built now. Three genuine prerequisite gaps stay deferred
with reasons (unchanged from the existing argument): DOCX (no engine), OCR
(no Tesseract on this box yet), AI/translation (no provider configured).
True realtime co-editing stays argued-against (Reverb is the documented
upgrade path once locking's value is proven insufficient).

**Two prerequisites that WERE missing and now exist**, unblocking two rows:
Departments and Projects are both real entities now — §9/10 dossier folders
were only blocked on that.

## Wave

1. **Creation routes** (§5): duplicate an existing document; create from an
   ERP record (ride DocumentFieldRegistry, already generalised); create as a
   workflow-step side effect; create as an automation-rule action.
2. **document.* event stream + extension points** (§54, 58, 59): every
   Documents lifecycle moment already CAN emit (EmitsDomainEvents exists on
   BusinessDocument per the audit's parity test) — audit and complete the
   catalogue entries, then build the registries other modules extend without
   Documents importing them: types, and automation triggers/actions, on the
   DocumentFieldRegistry model.
3. **Multilingual templates** (§44): the `language` column has existed and
   done nothing. Compose in a chosen language; per-language template body.
4. **Department/project dossier folders** (§9-10): now unblocked.
5. **Background processing for large files** (§60): ZIP bundling and
   reindex-on-upload move to a queue job, degrading to sync exactly the way
   Wave 3 taught DeliverWebhook to.
6. **Full document view + offline** (§53, 50-51): audit Papers/Show.php
   against the spec's side-panel list; wire what's missing. Offline: confirm
   the existing SyncEngine already covers filing/comment actions or extend
   it minimally — argue scope, this is the softest item.

GAP-ANALYSIS.md gets rewritten at the end to reflect ship state, including
marking the rich editor + soft-lock (already shipped) against §6/14/16.
