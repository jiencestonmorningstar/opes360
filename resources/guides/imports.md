# Bringing your data in

A business switching to this app already has its records somewhere — a
spreadsheet, a notebook typed up, another system's export. Making people
retype them is how a migration stalls on day one, so the **Import records**
screen takes the files as they are and is deliberately forgiving about what
is in them.

Two kinds of record can be imported today:

- **Customers** — name, company, email, phone, WhatsApp, city, street,
  tax number (NIU), notes.
- **Products** — name, SKU, barcode, selling price, cost, unit, opening
  stock quantity.

## What the file can look like

Excel (`.xlsx`, and the old `.xls`) or CSV, up to 5 MB and up to **2,000 rows
per import**. The file is identified by its actual contents, not its name — a
file called `.csv` that is really a workbook is a common way imports fail, and
it works here anyway.

You do not have to rename your columns. The importer recognises the headings
these files actually arrive with, in English and French alike: `Name`,
`Client`, `Nom`, `Phone Number`, `Téléphone`, `Prix de vente`, `Quantité`,
and many more, with spacing, capitals and underscores ignored. Amounts written
as `1 250 000`, `1,250,000` or `1.250.000,50` are all read correctly, and a
phone number Excel has stored as a number comes through as digits, not
scientific notation.

The one hard requirement: **a column naming each customer or product**. A file
with no recognisable name column is refused outright, with a message saying
so — there is nothing sensible an importer can invent for a nameless row.

## Preview first, then commit

Every import is two passes, and the split is the whole point:

1. **Preview** reads the file and reports what it found — nothing is written.
2. **Commit** writes exactly the rows the preview showed.

An import that wrote first and reported afterwards would leave you picking
three hundred half-right rows out of your customer book by hand. Here, if the
preview looks wrong, close it, fix the file, and upload again; nothing has
happened yet.

The preview tells you four things:

- **The rows it will import**, as it read them.
- **The rows it will skip, and why** — each with its line number in your
  file. A row is skipped when it has no name, when it repeats a name already
  seen earlier in the same file (the line it duplicates is named), or when it
  falls past the 2,000-row limit.
- **Which of your columns matched** which field.
- **Which columns were ignored** because nothing recognised them. If a column
  you care about is in this list, rename its header to something plainer and
  upload again.

## Fixing rejects

Rejects are fixed **in your file**, not on the screen: open the spreadsheet,
go to the line numbers the preview named, correct them, and upload again. The
line numbers count the way you count — the header is line 1, the first data
row is line 2 — so they match what you see in Excel.

## Running it twice is safe

An import run twice must not double the customer book. An incoming row that
matches an existing record — by SKU first for products, by email first for
customers, by name otherwise — **updates** that record instead of adding a
second one. The result message tells you how many were created and how many
updated.

Updates fill and correct fields, but they do not blank them: a file that
carries only a city will not wipe a street address somebody typed in by hand.

## Who can do what

There is no separate import permission, on purpose. The import writes the same
records the create screens write, so it asks for the same abilities:

| Ability | What it allows |
|---|---|
| `customers.create` | Import customers (and see the Imports entry in the menu) |
| `products.create` | Import products |

Somebody who may not add a customer by hand may not add five hundred by file.

## Over the API

The same two-pass import exists at `/api/v1/imports/preview` and
`/api/v1/imports`, under the `write` token ability — useful for migrating
from another system programmatically. See
[API tokens and webhooks](/guides/api-tokens-and-webhooks).

## Related

- [Customers](/guides/customers) — where imported customers land.
- [Products and stock](/guides/products-and-stock) — where imported products
  land, and what the opening quantity means.
- [Matching the bank](/guides/banking-reconciliation) — the other importer,
  for bank statements, built on the same forgiveness about file formats.
