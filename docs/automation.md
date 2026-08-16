# Domain events and automation

The trigger half of trigger → condition → action. Modules announce what
happened; automation rules and notifications listen. Nothing polls, and no
module imports another to find out that something occurred.

The business-facing version of this page is the **Automation rules** guide at
`/guides/automation`. This one is for whoever is adding events to a module.

---

## One event class, not sixty

```php
DomainEvent {
    string $name;        // 'expense.recorded'
    string $companyId;
    Model  $subject;
    array  $context;
    ?int   $actorId;
}
```

The name is data. Sixty event classes would be sixty files differing only in a
string, and an automation rule matching on a name would have to map class names
back to it anyway.

Every name lives in `App\Support\DomainEvents`. A catalogue rather than
scattered string literals, for the same reason `Permissions` is a catalogue: a
rule listening for `document.pubished` would simply never fire, and nothing
would say why.

---

## Emitting

```php
use App\Models\Concerns\EmitsDomainEvents;

class Expense extends Model
{
    use EmitsDomainEvents;
}
```

```php
$expense->emitDomainEvent('expense.recorded');
```

**Emit at the point the thing actually happens** — inside the service or the
model, never in a controller. A screen and an API call must produce the same
event, and they only do if the emitter sits below both of them.

An event with no company is not dispatched at all. It could not be matched
against any business's rules, and dispatching it anyway would force every
listener to re-check something the bus should have guaranteed.

### The payload is a reference, never a copy

```php
['event' => …, 'company_id' => …, 'subject_type' => …, 'subject_id' => …, …]
```

Whoever handles it loads the record and gets the *current* one, with current
permissions applied, rather than a snapshot that has silently drifted. A
payload embedding a copy of an invoice would be a second copy of an invoice —
which is what §56 of the brief spends its length forbidding.

---

## Rules

`automation_rules`: an event name, optional conditions, an action, and its
config.

**Conditions are the workflow engine's matcher, unchanged.** Same
`{field, operator, value}` shape, same class, same fail-closed behaviour. One
condition language for the whole product — the second one is always the one
that grows an `eval`.

### Actions are a closed set

`start_workflow` · `notify_user` · `notify_role` · `send_webhook` ·
`set_field`

Every one delegates to something that already exists: the workflow engine, the
notification layer, the existing `WebhookDispatcher`. Nothing in
`ActionRunner` implements a capability of its own — an automation runner that
starts doing work itself is how a product ends up with two of everything.

Anything outside the set throws, and the rule is skipped.

### `set_field` asks the model

```php
    /** @return array<int, string> */
    public function automatableFields(): array
    {
        return ['notes', 'category'];
    }
```

Empty by default, so being listenable does not imply being writable.

A global allow-list was the first attempt and was wrong: it had to guess column
names across every table, and guessing wide enough to be useful meant guessing
wide enough to let a rule set `status` on an invoice. Each model names its own.

---

## Failure is contained, deliberately

```php
            try {
                $this->runner->run($rule, $event);
            } catch (Throwable $e) {
                report($e);
            }
```

**An automation that stops an invoice being issued is worse than an automation
that does not run.** The business event already happened; all that is lost is a
consequence. One broken rule must not take the others down with it, and the
emitting code must never learn that a listener failed at all.

---

## What is emitted today

| Event | Emitted by |
|---|---|
| `workflow.started` | `WorkflowEngine::start()` |
| `workflow.stalled` | `WorkflowEngine::advance()`, when a step resolves to nobody |
| `workflow.approved` / `.rejected` / `.cancelled` | `WorkflowEngine::finish()` |
| `workflow.changes_requested` | `WorkflowEngine::returnToSubmitter()` |
| `expense.recorded` | Expenses |

The document, sales and HR names are catalogued and not yet emitted. That is
the correct order — a rule cannot be written against an uncatalogued name, and
adding the emitter later is one line at the point the thing happens.

---

## Adding an event to your module

1. Add the name to `DomainEvents::CATALOGUE`, past tense, `module.thing.happened`.
2. `use EmitsDomainEvents` on the model.
3. Call `emitDomainEvent()` where the thing actually happens.
4. If automation should be able to write to the model, add `automatableFields()`.
5. Write the guide. A feature nobody can find out how to use is not finished,
   and `GuidesTest` fails if a guide file and its catalogue entry disagree.
