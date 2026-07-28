<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\VerificationController;
use App\Livewire\Business\Artisans as BusinessArtisans;
use App\Livewire\Business\Companies as BusinessCompanies;
use App\Livewire\Business\Edit as BusinessEdit;
use App\Livewire\Business\Logo as BusinessLogo;
use App\Livewire\Business\Stationery;
use App\Livewire\CalendarPage\Index as CalendarIndex;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomerShow;
use App\Livewire\Dashboard;
use App\Livewire\Documents\Create as DocumentCreate;
use App\Livewire\Documents\Show as DocumentShow;
use App\Livewire\Onboarding\Register;
use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Products\Form as ProductForm;
use App\Livewire\Products\Index as ProductsIndex;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Livewire\Sales\Index as SalesIndex;
use App\Livewire\Scan;
use App\Livewire\Settings\Index as SettingsIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Guest authentication: sign-in with a TOTP challenge, sign-up, and password
 * reset. Login is throttled per email+IP in AuthController.
 */
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    Route::get('/register', Register::class)->name('register');

    Route::view('/forgot-password', 'auth.forgot-password')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');

    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token]))
        ->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

    // The challenge sits between credentials and an authenticated session, so it
    // is reachable by a guest — the session holds only a pending user id.
    Route::get('/two-factor', [AuthController::class, 'showChallenge'])->name('two-factor.challenge');
    Route::post('/two-factor', [AuthController::class, 'challenge'])->name('two-factor.verify');
});

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');

    Route::get('/sales', SalesIndex::class)->name('sales');

    Route::get('/customers', CustomersIndex::class)->name('customers');
    Route::get('/customers/create', CustomerForm::class)->name('customers.create');
    Route::get('/customers/{contact}', CustomerShow::class)->name('customers.show');
    Route::get('/customers/{contact}/edit', CustomerForm::class)->name('customers.edit');

    // "create" is registered before the wildcard so it is never read as an id.
    Route::get('/documents/create', DocumentCreate::class)->name('documents.create');
    Route::get('/documents/{document}', DocumentShow::class)->name('documents.show');
    Route::get('/documents/{document}/print', [PrintController::class, 'document'])->name('documents.print');
    Route::get('/receipts/{receipt}/print', [PrintController::class, 'receipt'])->name('receipts.print');

    Route::get('/products', ProductsIndex::class)->name('products');
    Route::get('/products/create', ProductForm::class)->name('products.create');
    Route::get('/products/{item}/edit', ProductForm::class)->name('products.edit');

    Route::get('/business', BusinessEdit::class)->name('business');
    Route::get('/business/logo', BusinessLogo::class)->name('logo');
    Route::get('/business/logo/download', [PrintController::class, 'logo'])->name('logo.download');
    Route::get('/business/stationery', Stationery::class)->name('stationery');
    Route::get('/businesses', BusinessCompanies::class)->name('businesses');
    Route::get('/artisans', BusinessArtisans::class)->name('artisans');
    Route::get('/stationery/print', [PrintController::class, 'stationery'])->name('stationery.print');
    Route::get('/payments', PaymentsIndex::class)->name('payments');
    Route::get('/reports', ReportsIndex::class)->name('reports');
    Route::get('/calendar', CalendarIndex::class)->name('calendar');
    Route::get('/settings', SettingsIndex::class)->name('settings');
    Route::get('/scan', Scan::class)->name('scan');
    Route::get('/two-factor/qr.svg', [AuthController::class, 'twoFactorQr'])->name('two-factor.qr');
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')->name('verification.verify');
    Route::view('/help', 'help.index')->name('help');
});

/*
 * Offline sync API. Session-authenticated because the PWA runs same-origin;
 * versioned so a device on older code keeps working after a server deploy.
 */
Route::middleware(['auth', 'throttle:120,1'])->prefix('api/sync/v1')->group(function () {
    Route::post('/push', [SyncController::class, 'push'])->name('sync.push');
    Route::get('/pull', [SyncController::class, 'pull'])->name('sync.pull');
});

/*
 * Public business profiles — what a business QR opens. Like verification, these
 * are read by customers with no account, and resolved as the profile's company.
 */
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/business/{company}', [ProfileController::class, 'business'])->name('profile.business');
    Route::get('/business/{company}/vcard', [ProfileController::class, 'vcard'])->name('profile.vcard');
    Route::get('/artisan/{artisan}', [ProfileController::class, 'artisan'])->name('profile.artisan');
    Route::get('/artisan/{artisan}/vcard', [ProfileController::class, 'artisanVcard'])->name('profile.artisan.vcard');
});

/*
 * Public verification — the destination behind every printed QR. No auth: the
 * person scanning is a customer, not a user. Throttled because tokens are
 * unguessable but endpoints are still enumerable.
 */
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/v/{token}', [VerificationController::class, 'show'])->name('verification.show');
    Route::get('/v/{token}/qr.svg', [VerificationController::class, 'qr'])->name('verification.qr');
});

/*
 * Served from a route rather than a static file so the manifest can pick up a
 * company's own branding and start URL once Phase 1 makes those configurable.
 */
// Precached by the service worker so a failed navigation has somewhere to land.
Route::view('/offline', 'offline')->name('offline');

Route::get('/manifest.webmanifest', function () {
    return response()->json([
        'name' => config('opes.brand.name').' — '.config('opes.brand.tagline'),
        'short_name' => config('opes.brand.name'),
        'description' => 'Business Identity & Operations Suite by '.config('opes.brand.vendor'),
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'portrait-primary',
        'background_color' => '#ffffff',
        'theme_color' => '#2563eb',
        'icons' => [
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ])->header('Content-Type', 'application/manifest+json');
})->name('manifest');
