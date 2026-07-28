<?php

use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VerificationController;
use App\Livewire\Business\Edit as BusinessEdit;
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
use App\Livewire\Settings\Index as SettingsIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Sign-in and sign-up. Email verification, password reset and TOTP two-factor
 * complete this flow in Phase 0's hardening pass.
 */
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::get('/register', Register::class)->name('register');

    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Those credentials do not match our records.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    });
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
    Route::get('/business/stationery', Stationery::class)->name('stationery');
    Route::get('/stationery/print', [PrintController::class, 'stationery'])->name('stationery.print');
    Route::get('/payments', PaymentsIndex::class)->name('payments');
    Route::get('/reports', ReportsIndex::class)->name('reports');
    Route::get('/calendar', CalendarIndex::class)->name('calendar');
    Route::get('/settings', SettingsIndex::class)->name('settings');
    Route::view('/help', 'help.index')->name('help');
});

/*
 * Public business profiles — what a business QR opens. Like verification, these
 * are read by customers with no account, and resolved as the profile's company.
 */
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/business/{company}', [ProfileController::class, 'business'])->name('profile.business');
    Route::get('/business/{company}/vcard', [ProfileController::class, 'vcard'])->name('profile.vcard');
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
