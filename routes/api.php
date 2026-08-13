<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\TokenController;
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
 */

// Issuing a token is the one thing that cannot require a token.
Route::post('tokens', [TokenController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('api.tokens.store');

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('user', [TokenController::class, 'me'])->name('api.user');
    Route::delete('tokens/current', [TokenController::class, 'destroy'])->name('api.tokens.destroy');

    Route::apiResource('deals', DealController::class)->names('api.deals');
    Route::post('deals/{deal}/move', [DealController::class, 'move'])->name('api.deals.move');
    Route::post('deals/{deal}/invoice', [DealController::class, 'invoice'])->name('api.deals.invoice');

    Route::apiResource('contacts', ContactController::class)->names('api.contacts');
    Route::apiResource('items', ItemController::class)->names('api.items');

    /*
     * Documents are create-read-issue-delete rather than a full resource: an
     * issued document is immutable by design, so there is deliberately no
     * update route for one to be attempted through.
     */
    Route::apiResource('documents', DocumentController::class)
        ->only(['index', 'store', 'show', 'destroy'])
        ->names('api.documents');
    Route::post('documents/{document}/issue', [DocumentController::class, 'issue'])->name('api.documents.issue');

    /*
     * Money in. No update route: a payment is a thing that happened, and
     * correcting one is a refund or a void rather than an edit.
     */
    Route::apiResource('payments', PaymentController::class)
        ->only(['index', 'store', 'show'])
        ->names('api.payments');

    /*
     * Money out. Voided rather than deleted, so the reversal shows in the
     * books instead of the original vanishing from them.
     */
    Route::apiResource('expenses', ExpenseController::class)
        ->only(['index', 'store', 'show'])
        ->names('api.expenses');
    Route::post('expenses/{expense}/settle', [ExpenseController::class, 'settle'])->name('api.expenses.settle');
    Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('api.expenses.void');

    /*
     * The books, read only and deliberately so — every entry is the
     * consequence of a business event that already has its own endpoint.
     */
    Route::prefix('accounting')->name('api.accounting.')->group(function (): void {
        Route::get('accounts', [AccountingController::class, 'accounts'])->name('accounts');
        Route::get('trial-balance', [AccountingController::class, 'trialBalance'])->name('trial-balance');
        Route::get('income-statement', [AccountingController::class, 'incomeStatement'])->name('income-statement');
        Route::get('balance-sheet', [AccountingController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('journal', [AccountingController::class, 'journal'])->name('journal');
    });

    Route::apiResource('employees', EmployeeController::class)
        ->only(['index', 'store', 'show', 'update'])
        ->names('api.employees');

    // Read only: approving a month is the owner's signature, not a token's.
    Route::get('payroll/runs', [PayrollController::class, 'runs'])->name('api.payroll.runs');
    Route::get('payroll/runs/{run}/payslips', [PayrollController::class, 'payslips'])->name('api.payroll.payslips');

    Route::post('imports/preview', [ImportController::class, 'preview'])->name('api.imports.preview');
    Route::post('imports', [ImportController::class, 'store'])->name('api.imports.store');
});
