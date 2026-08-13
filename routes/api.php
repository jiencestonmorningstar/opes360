<?php

use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ItemController;
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

    Route::post('imports/preview', [ImportController::class, 'preview'])->name('api.imports.preview');
    Route::post('imports', [ImportController::class, 'store'])->name('api.imports.store');
});
