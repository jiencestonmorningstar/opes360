# Shareable forms

A form is a set of questions you send out as a link — a registration sheet, a
survey, a booking request — and the responses come back into one list you can
actually work with, instead of a WhatsApp thread and three paper slips.

Open it from **Forms** in the menu, or press **New Form** among the quick
actions.

## Building one

**New form** creates an untitled draft and puts you straight into the builder.
You name it there, not before — naming a thing before it exists is backwards,
and every forms product you have used works this way.

Add questions from the palette on the right. Nine types are offered:

- **Short answer** and **Paragraph** — free text, short or long.
- **Multiple choice**, **Checkboxes** and **Dropdown** — pick one, pick many,
  or pick one from a long list. These carry options you write yourself.
- **Date**, **Number**, **Email** and **Phone** — typed answers that are
  checked on the way in, so "next Tuesday" cannot land in a date field.

Each question can carry help text and a **required** flag. Reorder with the
arrows; remove what you no longer want.

There is **no save button**. Every structural change — adding, removing,
moving a question — saves immediately, and text saves as you leave each field.
A builder with a save button to forget is a builder that loses an afternoon's
questions.

## Sharing it

A form has three states: **draft**, **open** and **closed**. Only an open form
accepts responses, and a form cannot be opened until it has at least one
labelled question — an open form with nothing to fill in collects nothing but
confusion.

Once open, share the public link (or its QR code). Whoever opens it fills the
form in without an account and without seeing anything else of your business.
The same questions you built are what they see, and their answers are checked
against the same rules: a required question must be answered, a choice must be
one of your options, an email must look like one.

**Closing** the form stops new responses without losing the ones you have. A
registration that ends on Friday is closed on Friday, and the link starts
saying so instead of quietly collecting names you will not honour.

## Working with responses

Open **Responses** on any form. Two things are shown:

**The tallies.** Every question that has options gets a count per option at
the top — for the "which session are you attending" kind of form, that tally
is the entire reason the form was sent out, so it is not buried under the
list.

**The list.** Every response, newest first, with each answer under the
question it answered.

One design decision is worth knowing about: answers are stored against the
question's internal id, not its wording. That means you can fix a typo in a
question — or rephrase it entirely — after responses have arrived, and nothing
anybody already submitted is rewritten or orphaned. What people answered stays
what they answered.

Responses are behind their own permission, separate from seeing that the form
exists. Submissions are other people's names, numbers and complaints, and the
business decides who reads them — building a form does not automatically mean
reading what strangers typed into it.

## Deleting a form

A form can be deleted from the list. If it collected anything you still need,
close it instead: closed keeps the responses, deleted does not.

## Over the API

Forms and their responses can be read over the API — see
[API tokens and webhooks](/guides/api-tokens-and-webhooks). Building a form
over the API is deliberately not offered: dragging four fields onto a screen
is strictly easier than posting the same structure as JSON, and nothing about
a form repeats or arrives from another system.

## Who can do what

| Ability | What it allows |
|---|---|
| `forms.view` | See the list of forms and open one |
| `forms.create` | Create a new form |
| `forms.update` | Edit questions, and open or close a form |
| `forms.delete` | Delete a form |
| `forms.responses` | Read what people submitted |

`responses` is the one to think about. It is separate because what comes back
through a form belongs to the people who wrote it, and "may build the form" is
not the same trust as "may read every reply".

## Related

- [Events and tickets](/guides/events-and-tickets) — the other thing you share
  as a public link.
- [Customers](/guides/customers) — where the people your forms bring in
  eventually belong.
