# Finding a document

The Documents screen is a workspace, not just a list: search, filter, and a
quick read on what needs attention.

## The overview counters

Five numbers at the top of the screen:

| Counter | What it counts |
|---|---|
| **Total** | Every document you can see |
| **Mine** | Documents you own |
| **Drafts** | Not yet issued |
| **Expiring** | Due to lapse within 30 days |
| **Archived** | Voided documents |

## Filtering

- **Kind** — contract, letter, certificate, and everything else in the
  catalogue, grouped by area (Legal, HR, Finance…).
- **Security level** — see only documents at a particular level. See
  [Who can see a document](/guides/document-security) for what each level
  means.
- **Folder** — narrow to one folder, including its immediate sub-folders.
- **Tag** — free-text, matches any document carrying that tag.

Filters combine. Searching "contract", filtering to **Legal**, and picking a
folder narrows to exactly that intersection. **Clear filters** resets all of
them, including the search box, in one click.

## Things that surprise people

**A restricted document you cannot open never appears in this list at all** —
not even as a locked row. It is refused at the same point it would be refused
anywhere else: by search, by the API, by its own link. See
[Who can see a document](/guides/document-security).

**The counters only count what you can see.** If your role cannot open
restricted documents, they are left out of every counter too — "Total" is not
a claim about the business's entire document library, it is a claim about
yours.

## Filing versus editing

Moving a document into a folder, tagging it, changing its owner or setting
when it expires is **filing** — organising where a document lives, not
changing what it says. Filing works on a document at any stage, including one
that has already been issued and signed.

Changing the actual content — its title, its wording, who it is addressed to
— is **editing**, and that only works on a draft. Once a document is issued it
is frozen: revise a draft, or void it and issue a replacement. This is why you
can move a signed contract into a different folder six months later, but you
cannot go back and change what it says.

## Versions

Every time a draft's actual content changes — its title, its wording, who it
is addressed to — the previous version is kept. Filing actions (moving it,
tagging it, locking it) do not create a new version, because they have not
changed what the document says.

You can restore an earlier version of a draft. Restoring does not erase what
came after it — it becomes the new current version, on top of the ones
already there, so the history always shows exactly what happened and in what
order. Only a draft can be restored; once a document is issued, its content is
frozen the same way it always has been.

## Locking a draft

**Lock** freezes a draft against further edits without issuing it — useful
mid-review, when you want nobody to touch the wording while it is being
checked, but are not ready to commit to it being final. A locked draft can
still be unlocked, or issued outright; locking is not a step on the way to
issuing, it is a separate hold you can put on and take off.

## How long a document is kept

A business can set how long each kind of document must be kept — a contract
for ten years, a memo for five, whatever the law and your own policy require.
There is no built-in default: nobody outside your business gets to decide
that for you.

**Legal hold** overrides the schedule entirely. Place one on a document —
with a reason — and it cannot be disposed of no matter what the retention
period says, until the hold is lifted. This is for the moment a document
becomes relevant to a dispute or an investigation and must not go anywhere.

Nothing is ever disposed of automatically. Disposal is something an
administrator does deliberately, and only once the retention period has
actually passed and nothing is holding it.

## Sharing outside the business

Create a link that opens a document for anyone who has it, with no account
needed. Three things you can set when creating one:

- **Expiry** — the link stops working after a date you choose. Leave it
  blank for a link that lasts indefinitely.
- **Password** — require a password before the document shows. Send it
  separately from the link itself.
- **View-only** — hide the print/download option. This is a courtesy, not a
  lock: anyone who can see a page in a browser can still take a screenshot of
  it, so treat it as discouraging casual copies rather than preventing them.

Every view is logged, so you can see when a link was actually opened.

**Revoke** turns a link off immediately. It still exists in your history —
you can see it was created and when — it simply stops opening.

**Things that surprise people:** a document can have more than one share
link at once, each with its own settings — a permanent internal link and a
one-week client link for the same document, side by side. Revoking one never
touches the others.

## Signing

Send a document out for signature to one or more people — a client, a
witness, a new employee. Each signer gets their own link by email; nobody
needs an account with you to sign.

**Parallel** — anybody can sign at any time, in any order. **Sequential** —
the second signer's link does not work until the first has signed. Choose
whichever matches how the paper version would have been passed round.

A signer types their name and submits to sign — the same way most lightweight
e-signature tools work. What makes it binding is not the typed name itself,
but the combination that only they hold: the emailed link, and the time and
IP address recorded the moment they use it. A signer can decline instead, with
a reason, if something needs to change first.

Once everybody has signed, the document gets the same tamper-evident
verification your other issued documents already have — the same QR
verification the rest of the product uses, not a second one built specially
for signatures.

**Things that surprise people:** a document can be sent for signature whether
it is a draft or already issued — signing a contract after it has been
formally issued is the ordinary case, not an edge case. Once a signature round
is under way, a second one cannot be started on the same document until the
first is finished, declined, or the document is voided.

## Approval

A document can be sent for approval the same way any other approvable record
in the business is — see [Approvals and workflows](/guides/approvals) for how
that works in general. Once submitted, it shows as **awaiting approval** until
somebody with a say in it answers.

## Activity

Every document has a timeline: created, filed, revised, issued, commented on
— one chronological list rather than four separate places to look. Nothing
here is a separate record kept specially for the timeline; it is built from
the document's own version history, its comments, and the audit log the
product already keeps, put in order.

## Comments

Leave a remark on a document, or reply to one already there. Mentioning a
colleague sends them an email and a notification — mentions are chosen from a
list of people, not typed as `@name`, so the notice always reaches the right
person even if two colleagues share a name.

A thread can be **resolved** once it no longer needs attention, and
**reopened** if it turns out it does. Resolving is available to whoever wrote
the comment, to the document's owner, and to a document administrator — not to
anyone passing by, since resolving a thread is a small act of judgement about
what still needs attention.

Deleting a comment is narrower still: only its author, or a document
administrator.

## Comparing versions

Two versions can be compared side by side, word by word — not just "this
changed" but exactly which words were added and which were removed, the same
way a word processor's track-changes view works.

## For developers

Everything on this screen is also reachable over the API — see §22 of
`docs/API.md` for `GET /api/v1/library` and the filing endpoints, and its
"Versions" subsection for listing, comparing and restoring.
