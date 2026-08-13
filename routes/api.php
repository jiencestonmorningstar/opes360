<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * The JSON API.
 *
 * Everything here is token-authenticated (Sanctum) rather than session-based,
 * and every route runs through SetCurrentCompany so the tenant scope applies
 * exactly as it does on the web side — an API that skipped it would be a way
 * around the one boundary that keeps businesses' data apart.
 *
 * Authorisation is the same policy layer the UI uses. There is no separate
 * "API permission" concept: a token can never do more than the user it belongs
 * to could do while signed in.
 *
 * ── Why the version is in the path ──────────────────────────────────────────
 *
 * It costs nothing today and cannot be added later. The moment one integration
 * exists, moving `/api/deals` to `/api/v1/deals` breaks it, and the alternative
 * — never making a breaking change — is a promise no product keeps. So the
 * prefix goes in while the only caller is us.
 *
 * v1 is what this file is. A future v2 gets its own group beside it and its own
 * controllers; the two are allowed to disagree, which is the entire point.
 *
 * ── Token scopes ────────────────────────────────────────────────────────────
 *
 * `ability:` narrows a token below its user. Read is `read`; ordinary writes
 * are `write`; anything that moves money is `money`; the staff file and payroll
 * are `people`. They are deliberately not implied by each other — a dashboard
 * token asking for `read` does not thereby get the payroll, and a token that
 * can add a customer cannot take a payment.
 *
 * A token minted without a scope list holds `*` and passes all of these, which
 * keeps the simple case simple: a script the owner wrote for themselves should
 * not have to enumerate scopes to work.
 */

Route::prefix('v1')->group(function (): void {

    // Issuing a token is the one thing that cannot require a token.
    Route::post('tokens', [TokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('api.v1.tokens.store');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        // Deliberately outside the scope checks: a token must always be able
        // to say who it is and to revoke itself, whatever it was minted for.
        Route::get('user', [TokenController::class, 'me'])->name('api.v1.user');
        Route::delete('tokens/current', [TokenController::class, 'destroy'])->name('api.v1.tokens.destroy');

        // ── Reading ──────────────────────────────────────────────────────
        Route::middleware('ability:read')->group(function (): void {
            Route::get('deals', [DealController::class, 'index'])->name('api.v1.deals.index');
            Route::get('deals/{deal}', [DealController::class, 'show'])->name('api.v1.deals.show');

            Route::get('contacts', [ContactController::class, 'index'])->name('api.v1.contacts.index');
            Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('api.v1.contacts.show');

            Route::get('items', [ItemController::class, 'index'])->name('api.v1.items.index');
            Route::get('items/{item}', [ItemController::class, 'show'])->name('api.v1.items.show');

            Route::get('documents', [DocumentController::class, 'index'])->name('api.v1.documents.index');
            Route::get('documents/{document}', [DocumentController::class, 'show'])->name('api.v1.documents.show');

            Route::get('payments', [PaymentController::class, 'index'])->name('api.v1.payments.index');
            Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('api.v1.payments.show');

            Route::get('expenses', [ExpenseController::class, 'index'])->name('api.v1.expenses.index');
            Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('api.v1.expenses.show');

            /*
             * Events. Read only here: an event is a poster somebody writes
             * once and checks on a screen, so what an integration wants from
             * this module is the price list and the attendee list — selling
             * and scanning are below, under the scopes that fit them.
             */
            Route::get('events', [EventController::class, 'index'])->name('api.v1.events.index');
            Route::get('events/{event}', [EventController::class, 'show'])->name('api.v1.events.show');
            Route::get('events/{event}/ticket-types', [EventController::class, 'ticketTypes'])->name('api.v1.events.ticket-types');
            Route::get('events/{event}/tickets', [TicketController::class, 'index'])->name('api.v1.events.tickets.index');

            /*
             * Forms, and the answers people gave them. Responses are scoped to
             * a form rather than offered flat, for the same reason payslips are
             * scoped to a run, and they sit behind `forms.responses` — a
             * separate grant from seeing that a form exists, because other
             * people's submissions are what is in them.
             */
            Route::get('forms', [FormController::class, 'index'])->name('api.v1.forms.index');
            Route::get('forms/{form}', [FormController::class, 'show'])->name('api.v1.forms.show');
            Route::get('forms/{form}/responses', [FormController::class, 'responses'])->name('api.v1.forms.responses');

            /*
             * Loyalty hangs off a customer rather than standing alone — a
             * balance is part of somebody's record, and both the contact and
             * the loyalty ability are checked before it is returned.
             */
            Route::get('loyalty/contacts/{contact}', [LoyaltyController::class, 'show'])->name('api.v1.loyalty.show');
            Route::get('loyalty/contacts/{contact}/transactions', [LoyaltyController::class, 'transactions'])->name('api.v1.loyalty.transactions');

            /*
             * The books, read only and deliberately so — every entry is the
             * consequence of a business event that already has its own
             * endpoint, and a hand-written entry would be a way to make the
             * ledger disagree with the documents underneath it.
             */
            /*
             * Webhooks: the endpoints a business has registered, and the log
             * of what we tried to send them. Reading is separated from
             * managing because they answer different questions — "did that
             * sale reach my system" is a support question anybody debugging an
             * integration asks, while "which servers get a copy of our
             * revenue" is a decision. `webhooks.view` still keeps it away from
             * a token whose user is not an Owner or Administrator, and the
             * signing secret is never in a read response.
             */
            Route::get('webhooks', [WebhookController::class, 'index'])->name('api.v1.webhooks.index');
            Route::get('webhooks/deliveries', [WebhookController::class, 'deliveries'])->name('api.v1.webhooks.deliveries');
            Route::get('webhooks/{webhook}', [WebhookController::class, 'show'])->name('api.v1.webhooks.show');

            Route::prefix('accounting')->name('api.v1.accounting.')->group(function (): void {
                Route::get('accounts', [AccountingController::class, 'accounts'])->name('accounts');
                Route::get('trial-balance', [AccountingController::class, 'trialBalance'])->name('trial-balance');
                Route::get('income-statement', [AccountingController::class, 'incomeStatement'])->name('income-statement');
                Route::get('balance-sheet', [AccountingController::class, 'balanceSheet'])->name('balance-sheet');
                Route::get('journal', [AccountingController::class, 'journal'])->name('journal');
            });
        });

        // ── Ordinary writes ──────────────────────────────────────────────
        Route::middleware('ability:write')->group(function (): void {
            Route::post('deals', [DealController::class, 'store'])->name('api.v1.deals.store');
            Route::match(['put', 'patch'], 'deals/{deal}', [DealController::class, 'update'])->name('api.v1.deals.update');
            Route::delete('deals/{deal}', [DealController::class, 'destroy'])->name('api.v1.deals.destroy');
            Route::post('deals/{deal}/move', [DealController::class, 'move'])->name('api.v1.deals.move');
            Route::post('deals/{deal}/invoice', [DealController::class, 'invoice'])->name('api.v1.deals.invoice');

            Route::post('contacts', [ContactController::class, 'store'])->name('api.v1.contacts.store');
            Route::match(['put', 'patch'], 'contacts/{contact}', [ContactController::class, 'update'])->name('api.v1.contacts.update');
            Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('api.v1.contacts.destroy');

            Route::post('items', [ItemController::class, 'store'])->name('api.v1.items.store');
            Route::match(['put', 'patch'], 'items/{item}', [ItemController::class, 'update'])->name('api.v1.items.update');
            Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('api.v1.items.destroy');

            /*
             * Documents have no update route: an issued one is immutable by
             * design, and a draft that needs different lines can be deleted
             * and recreated.
             */
            // Issuing burns an invoice number and enters the books, so a
            // retried request must not produce a second invoice.
            Route::post('documents', [DocumentController::class, 'store'])
                ->middleware('idempotent')->name('api.v1.documents.store');
            Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('api.v1.documents.destroy');
            Route::post('documents/{document}/issue', [DocumentController::class, 'issue'])
                ->middleware('idempotent')->name('api.v1.documents.issue');

            /*
             * The counterpart to there being no update route. An issued
             * document cannot be edited, so these are how a mistake is undone:
             * void it if nothing has been paid, credit it if something has.
             * Both are idempotent — voiding twice or crediting twice on a
             * retry would be a different kind of wrong from the first.
             */
            Route::post('documents/{document}/void', [DocumentController::class, 'void'])
                ->middleware('idempotent')->name('api.v1.documents.void');
            Route::post('documents/{document}/convert', [DocumentController::class, 'convert'])
                ->middleware('idempotent')->name('api.v1.documents.convert');
            Route::post('documents/{document}/credit-note', [DocumentController::class, 'creditNote'])
                ->middleware('idempotent')->name('api.v1.documents.credit-note');

            /*
             * Registering a webhook endpoint. Under `write` rather than a
             * scope of its own: a token that can already issue invoices and
             * create customers is not made meaningfully more dangerous by
             * being able to name a URL, and a fifth scope invented for one
             * resource is a worse contract than the four the API already
             * teaches. The permission is what narrows this — `webhooks.manage`
             * belongs to the Owner and the Administrator and to nobody else,
             * because subscribing to `payment.recorded` is a standing export
             * of the business's revenue.
             *
             * Redelivery is a write rather than a read for the obvious reason
             * — it puts a request on somebody's server — and it is here rather
             * than under `money` because the money already moved; this only
             * repeats the sentence describing it.
             */
            Route::post('webhooks', [WebhookController::class, 'store'])->name('api.v1.webhooks.store');
            Route::match(['put', 'patch'], 'webhooks/{webhook}', [WebhookController::class, 'update'])->name('api.v1.webhooks.update');
            Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('api.v1.webhooks.destroy');
            Route::post('webhooks/deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver'])->name('api.v1.webhooks.redeliver');

            Route::post('imports/preview', [ImportController::class, 'preview'])->name('api.v1.imports.preview');
            Route::post('imports', [ImportController::class, 'store'])->name('api.v1.imports.store');

            /*
             * Checking a ticket in is an ordinary write, not a money one:
             * nothing changes hands at the door, the seat was paid for (or
             * not) when it was sold. A scanner at the entrance should be able
             * to hold a token that admits people and cannot sell a thing.
             *
             * Nested under the event so a serial belonging to another night
             * 404s rather than quietly admitting somebody to the wrong one.
             */
            Route::post('events/{event}/tickets/{ticket}/check-in', [TicketController::class, 'checkIn'])
                ->name('api.v1.events.tickets.check-in');
        });

        // ── Money ────────────────────────────────────────────────────────
        /*
         * Its own scope because "can add a customer" and "can take a payment"
         * are not the same trust, and most integrations want the first without
         * the second. A leaked read-only or write-only key cannot move money.
         */
        /*
         * `idempotent` is here rather than on every write because this is
         * where retrying twice costs money. A client that loses the connection
         * mid-payment cannot tell whether it went through; with an
         * Idempotency-Key it can simply send the same request again.
         */
        Route::middleware(['ability:money', 'idempotent'])->group(function (): void {
            Route::post('payments', [PaymentController::class, 'store'])->name('api.v1.payments.store');

            /*
             * The payment is not deleted and its receipt keeps verifying: the
             * customer holds a printed copy saying money changed hands, and it
             * did. The refund is a second event recorded beside the first.
             */
            Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])
                ->middleware('idempotent')->name('api.v1.payments.refund');

            Route::post('expenses', [ExpenseController::class, 'store'])->name('api.v1.expenses.store');
            Route::post('expenses/{expense}/settle', [ExpenseController::class, 'settle'])->name('api.v1.expenses.settle');
            Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('api.v1.expenses.void');

            /*
             * Selling a ticket and spending loyalty points are both here
             * rather than under `write` because both move value: a ticket is
             * a seat somebody paid for, and a point is a discount the business
             * will honour at the till. A retry that issued the order twice or
             * deducted the points twice is precisely what `idempotent` is for,
             * and both are the kind of call a shaky mobile connection drops
             * halfway through.
             */
            Route::post('events/{event}/tickets', [TicketController::class, 'store'])->name('api.v1.events.tickets.store');

            Route::post('loyalty/contacts/{contact}/redeem', [LoyaltyController::class, 'redeem'])->name('api.v1.loyalty.redeem');
        });

        // ── People ───────────────────────────────────────────────────────
        /*
         * Staff and pay sit behind their own scope rather than under `read`,
         * so a reporting integration does not quietly come with the staff file
         * attached. Payroll is read only: approving a month commits the
         * business to its wages and to the declarations that follow, which is
         * the owner's signature and not a token's.
         */
        Route::middleware('ability:people')->group(function (): void {
            Route::get('employees', [EmployeeController::class, 'index'])->name('api.v1.employees.index');
            Route::post('employees', [EmployeeController::class, 'store'])->name('api.v1.employees.store');
            Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('api.v1.employees.show');
            Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update'])->name('api.v1.employees.update');

            Route::get('payroll/runs', [PayrollController::class, 'runs'])->name('api.v1.payroll.runs');
            Route::get('payroll/runs/{run}/payslips', [PayrollController::class, 'payslips'])->name('api.v1.payroll.payslips');
        });
    });
});
