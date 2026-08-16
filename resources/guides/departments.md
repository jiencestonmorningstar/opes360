# Departments

A department is the part of the business somebody works in — Finance,
Operations, the Boutique. Staff are assigned to one, documents are filed under
one, and approvals can be routed to one's manager.

## Why this exists

Departments used to be a box you typed into on each employee's record. That
meant "Finance", "finance" and "Fin." were three different departments as far
as the product was concerned, and no report could add them up. Now there is one
list, and everybody picks from it.

## Setting them up

**Business → Departments.**

- **Name** — required, and unique within your business. Two businesses can both
  have a Finance department; yours cannot have two.
- **Code** — optional. A short label like `FIN` for reports and exports.
- **Sits under** — leave blank for a top-level department, or choose a parent to
  build an org chart. You can nest up to four levels deep.

You can also give a department a **manager**. That matters beyond the org
chart: approval rules can route to "this department's manager", and the product
looks up who that is at the moment the approval happens.

## Archiving, not deleting

There is no delete button, and that is deliberate.

A department's name appears on payslips that have already been paid, documents
that have already been filed, and approvals that have already happened.
Deleting it would leave all of that history reading as a blank. **Archive**
takes it out of the pickers without touching anything already recorded, and
**Restore** brings it back.

Tick **Show archived** to see the ones you have put away.

## Things that surprise people

**Archiving a department does not move its staff.** They keep working there;
the department simply stops being offered when you file something new. Assign
them somewhere else first if that is what you meant.

**A department is a label, not a container.** If a department is ever removed
entirely, the employees, documents and folders that pointed at it all survive —
they just stop being filed under anything. Nothing is ever deleted along with
it.

**You cannot move a department inside its own sub-department.** Operations
cannot be filed under Logistics if Logistics already sits under Operations. The
product refuses it rather than tying the org chart in a knot.

**Who can change this:** the Owner, an Administrator, and a Manager. The
Accountant and Read Only roles can see the list — payroll and cost reporting
both need it — but cannot redraw it.
