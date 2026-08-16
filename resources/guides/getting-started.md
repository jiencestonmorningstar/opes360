# How this documentation works

Every feature in OPES360 has a guide here. If something in the product does not
make sense, the explanation is on this page somewhere — and if it is not, that
is a gap worth reporting, because a feature nobody can find out how to use is
not finished.

## Who each guide is for

Guides are labelled by audience, because mixing the three in one page is how
manuals become unreadable.

- **Everyone** — anybody using the product to do their job. No setup knowledge
  assumed.
- **Admin** — whoever configures the business: roles, departments, approval
  rules, document settings.
- **Developer** — integrating with OPES360 over the API. These guides assume
  you have read `docs/API.md`.

## How to find things

Use the search box. It looks inside the full text of every guide, not just the
titles, so searching for a phrase you remember seeing usually works better than
guessing what a feature is called.

## What a guide contains

Each one is written the same way:

1. **What it is** — in one or two sentences, in plain terms.
2. **How to use it** — the actual steps, in order.
3. **What happens behind the scenes** — only where it changes what you should
   expect. A guide that explains the database schema to a receptionist is a
   guide nobody reads.
4. **Things that surprise people** — the behaviour that generates support
   questions, stated up front instead of discovered.

## If a guide is wrong

The product changes; documentation drifts. A guide that disagrees with what the
product actually does should be treated as a bug in the guide, and reported the
same way you would report any other bug.
