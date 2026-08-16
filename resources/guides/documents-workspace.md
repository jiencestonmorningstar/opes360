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

## For developers

Everything on this screen is also reachable over the API — see §22 of
`docs/API.md` for `GET /api/v1/library` and the filing endpoints. Versions do
not have an API yet; see `docs/API.md` §23 for what is intentionally absent
versus what simply has not shipped.
