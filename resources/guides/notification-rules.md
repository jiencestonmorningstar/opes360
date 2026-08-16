# Who gets told what

A rule says: *when this happens, and it looks like this, tell these people.*

Set them up under **Settings → Notification rules**. Your own choices about what
reaches you live under **Settings → Notifications**, and need no permission —
gating those would mean an administrator had to grant you the right to mute your
own email.

## The rule

Three parts:

1. **When** — a business event. An invoice was issued, a claim was approved, an
   SLA is about to breach, a contract's notice date is coming.
2. **If** — conditions on the record. Over a certain amount, in a certain
   department, for a certain customer. These are the same conditions the
   [approval workflows](/guides/approvals) use, not a second, subtly different
   set.
3. **Who** — by role, department, the record's owner, the person's manager, or a
   named person.

**Recipients are worked out when the event happens**, never when the rule is
written. A rule that says "the warehouse manager" means whoever that is today.
Storing the person instead of the role is how a business ends up notifying
somebody who left last year.

## Channels

**In-app** and **email** today. SMS and WhatsApp are listed but switched off:
they need a paid gateway account, and this product does not ship one. If a rule
names an unavailable channel, the delivery is logged as unavailable rather than
silently pretended.

## The real problem: noise

A notification that fires on everything is noise. Noise gets muted. A muted
channel is worse than no channel at all, because now everybody believes people
are being told.

Four levers, and they fail in different ways, which is why there are four:

- **Deduplication** kills machine repeats. The same event about the same record
  is not sent twice. It fingerprints the record *and* what is being said, so
  "approved" can never suppress a later "rejected".
- **Digests** kill human volume. Instead of eleven messages, one message at a
  set hour listing eleven things.
- **Quiet hours** hold messages and release them afterwards. They never drop
  anything. Something that arrives at 23:00 is delivered when your morning
  starts.
- **Muting** a category is the only lossy option, which is exactly why it is per
  *category* and not per rule — a mute you cannot reason about later is a hole
  in the trail.

### The one escape hatch

A rule marked **critical** ignores all four. This is deliberate, and keeping
*exactly one* such level is what makes the others trustworthy.

If people cannot trust that muting a category still lets the genuinely urgent
through, they stop using the mute and set up a filter in their mail client
instead — and that filter hides the critical ones too. You have then lost
control of the channel entirely without being told.

Use `critical` sparingly. Every rule marked critical makes the next one worth
less.

## The delivery log

Every send is recorded: what, to whom, on which channel, and whether it got
there. "I was never told" is a question with an answer.

## Who can do what

| Ability | What it allows |
|---|---|
| `notifications.manage` | Write and edit the rules |
| *(none)* | Your own preferences, mutes and quiet hours |

`notifications.manage` is not granted with `settings.update`. Muting a rule is a
quiet act with loud consequences — it is the difference between a breach being
noticed and not — and the [audit trail](/guides/audit-trail) should show who did
it.

## Related

- [Automation rules](/guides/automation) — when this happens, *do* that, rather
  than tell somebody.
- [Approvals and workflows](/guides/approvals)
