# Your own templates

Alongside the templates OPES360 ships with, your business can write its own —
an internal form, a letter with wording specific to how you work, anything the
built-in set does not cover.

## Writing one

A template has a name, an optional summary, the fields it asks for when
someone composes from it, and a body — the text itself, with `{{ field_key }}`
wherever an answer should be inserted.

New templates start **unpublished**. They do not appear in the gallery, and
nobody can compose from them, until you publish them. This lets you draft and
check a template before offering it to the business.

## Publishing

**Publish** puts a template in the gallery next to the built-in ones, exactly
as if it always belonged there. **Unpublish** takes it out again without
deleting it — anything already composed from it keeps working; only the
option to start a new one from it disappears.

## History

Every change to a template's wording or fields is kept as a version, the same
way a document's own edits are. Renaming or re-summarising a template does
not create a version; changing what it actually says does.

## Things that surprise people

**A template's key cannot be reused if it matches one that ships with
OPES360.** This keeps the two catalogues from ever colliding — if you try to
create one called `service_agreement`, you will be asked to choose a
different name, because that one is already taken by the built-in template of
the same name.

**Deleting a template does not touch documents already made from it.** A
document's text is copied in at the moment it is composed, not read from the
template afterwards — the same reason editing a template never rewrites a
document somebody already generated from it.
