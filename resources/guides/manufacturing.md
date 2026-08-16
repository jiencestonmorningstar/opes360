# Making things

If you make what you sell — a bakery, a furniture workshop, an assembler — this
is where the making is recorded. Two ideas, and only two: a **recipe** for each
finished product, and **orders** that run it.

Open it from **Manufacturing** in the menu. If you cannot see it, the module is
switched off; it ships off because most businesses buy their goods finished.

## Recipes

A recipe (a bill of materials, if you prefer the long name) says what one run of
a product is made from: the components from your own catalogue, the quantity of
each, and optionally a scrap percentage for what gets wasted along the way. A
sub-assembly is simply a product with its own recipe — no special machinery.

Writing or changing a recipe moves no stock and costs nothing. It is a plan.

## Orders

An order says *make this many, using that recipe*. Raising one still moves
nothing — components stay on the shelf, and you can cancel a planned order
without consequence.

**The order snapshots the recipe when it is raised.** The lines on the order
are a copy of the recipe as it stood that day, not a pointer at it. If you
improve the recipe next week, orders already raised keep making the thing they
promised to make; a snapshot is the only honest answer to "what did that batch
actually use?"

## Completing — the one step that matters

Completing an order is the moment stock really moves: the components come off
the shelf and the finished goods go on, in a single transaction.

There is no second stock system behind this. Every consumption and every
receipt is an ordinary movement in **the same stock ledger** everything else
uses — sales, purchases, counts, transfers. The count sheet, the valuation and
"how many do we have" all read one ledger, so manufacturing can never disagree
with the rest of stock, because there is nothing separate to disagree with.

The finished good's cost is the sum of what its components were worth at their
existing weighted average — priced onto the receipt exactly like a supplier
delivery. No separate costing engine, no typed-in figure.

Two refusals worth knowing:

- **A short component refuses the whole completion, by name** — "Not enough
  Vis 40mm: 48 needed, 30 on hand" — before anything moves. There is no
  half-made order with half its components consumed.
- **Cancelling consumes nothing.** An order that never completed never touched
  the shelf.

Lot-tracked components are picked first-expiry-first like every other issue,
and a lot-tracked finished good asks you for its lot number at completion.

## Who can do what

| Ability | What it allows |
|---|---|
| `manufacturing.view` | See recipes and orders |
| `manufacturing.manage` | Write recipes, raise and cancel orders |
| `manufacturing.complete` | Complete an order — the step that moves stock |

`complete` is separate because it is the money-shaped act: real stock moves and
a cost is fixed. Saying "it is made, take the planks off the shelf" is the same
trust as posting a stocktake.

## Related

- [Reordering before you run out](/guides/replenishment) — keeping the
  components in stock in the first place.
