# Where your equipment is

The asset register answers what the business owns and what it is still worth.
This screen answers the question you actually ask out loud: *where is the
generator, and who has the laptop?*

Open it from **Assets → Movements & servicing**.

## Sites

A site is somewhere you keep things — a head office, a depot, a yard, a site
office. Add them under the **Sites** tab.

If you had already typed locations onto your assets as free text, those became
sites automatically the first time this feature ran, and each asset was linked
to the one it named. Two spellings of the same place will have become two
sites; merge them by transferring everything to whichever one you want to keep.

Sites are not the same thing as **stock locations**. A stock location holds
goods you will sell. A site holds equipment you own and use. A shop can be both
at the same address, and keeping them apart is what stops a shelf and a
generator ending up in the same list.

## Transferring something

Under **Where things are**, press **Transfer** on any asset. You can change:

- **where it is** — the site,
- **who has it** — the custodian.

Either, or both. An asset can move site without changing hands, and it can
change hands without moving an inch. A laptop passed from one person to another
at the same desk is a transfer, and it is the one most worth recording.

Choosing **Nobody — back to the store** clears the custodian. That is a real
answer, not a blank one: it means the thing is back in the cupboard and no one
is responsible for it.

Every transfer is kept. The list of where something has been is shown under the
transfer form, and it is the record that matters on the day something cannot be
found.

Two things are refused:

- **A transfer that changes nothing.** If it is already at that site and with
  that person, nothing happens and the screen says so rather than pretending.
- **Transferring something disposed of.** If it has been sold or scrapped it is
  not anywhere. If it is still in use, the register is wrong and that is what
  to fix.

## Servicing

Under **Servicing**, book what is due: a service, a repair or an inspection,
against an asset and a date.

Anything past its date shows in red, and the count of overdue items sits at the
top of the screen whichever tab you are on. That banner is the whole point of
the feature — servicing is not forgotten because nobody knew, it is forgotten
because nothing was in front of anybody.

### Work that repeats

Set **Repeat every** to a number of months and the next visit is raised
automatically when you mark this one done.

The next date is counted **from the day the work was actually done**, not from
the day it was due. If an oil change was six weeks late, the next one is three
months from the oil change — not three months from a date that has already
passed. Counting from the due date would bring the next service forward for no
reason and put the schedule permanently in arrears.

Leave it blank for one-off work. A windscreen replacement does not quietly
become a standing commitment.

### What it cost

Servicing does not record an amount of its own. If the work was paid for, there
is already an expense for it, and the maintenance record points at that expense
and reads the cost from it.

This is deliberate. A second copy of the amount would drift from the first, and
the month's spend would depend on which screen you happened to ask.

## Vehicles

A vehicle is an asset like any other, so everything above already applies to it:
where it is, who is driving it, its transfer history, its service schedule.
**Assets → Fleet** adds only what a generic asset has no business carrying.

**Papers and details** — registration, VIN, make, model, year, fuel type, tank
capacity, and the two dates that stop a van legally: insurance and
roadworthiness expiry. Both are watched and warned about.

**Trips** — date, driver, start and end odometer, purpose. The distance is the
difference between the two readings; it is not a number anybody types, so it
cannot disagree with them.

**Fuel** — litres, odometer at the fill, and the expense that paid for it.
Consumption per 100 km is worked out tank-to-tank, which means the first fill's
litres are excluded: you know how far the vehicle went on the *second* tank,
because you know where it started. Counting the first tank's litres against a
distance that began before it would flatter the figure by roughly one tankful.

Like servicing, a fuel log holds **no amount of its own** at all — it reads the
cost from the expense every time. There is exactly one record of what fuel cost
this month.

### Servicing by distance

This is the real addition. Assets schedule servicing by **date**; a vehicle
needs it by **distance** — "every 10,000 km". Set `Repeat every … km` and the
next visit is raised when the van has driven far enough.

The reasoning matches the date version exactly: the next service counts from the
reading the work was **actually done at**. A service carried out 800 km late
moves the whole schedule on by 800 km, rather than falling due again almost
immediately.

The odometer itself is never stored as a running total. It is read as the
highest figure the vehicle has been recorded at — across trips, fills and
completed services. A stored counter goes stale the first time somebody corrects
a mistyped trip, and the schedule would then be counting from a number nobody
could reproduce.

One consequence worth knowing: **completing a distance-based service with no
odometer reading anywhere is refused.** "Every 10,000 km" counted from an unknown
starting point is not a schedule, it is a guess, and the next visit would sit
either permanently overdue or permanently invisible. Record the reading.

A job can repeat on months, on distance, or on both.

## Who can do what

| Ability | What it allows |
|---|---|
| `assets.view` | Open the screen and see where everything is |
| `assets.transfer` | Move something, or hand it to somebody |
| `assets.maintain` | Book servicing and mark it done |
| `assets.update` | Add and edit sites, and a vehicle's papers |

Fleet adds **no new abilities**. Logging a trip or a fill is the same custodial
job as signing equipment out, so it sits on `assets.transfer`. A separate fleet
permission would draw a line between handing somebody the keys and writing down
what they did with them, and there is no business in which those are different
people.

`transfer` and `maintain` are separate from `update` because they are a
different job. A storeman signs equipment out and books its next service;
neither act should carry the ability to restate what the thing cost or how fast
it is being written off.

## Related

- [Fixed assets and depreciation](/guides/closing-the-books) — what the thing
  is worth, and the charge that appears in the books each month.
- [Departments](/guides/departments) — a site can belong to one.
