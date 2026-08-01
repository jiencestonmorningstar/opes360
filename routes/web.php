<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Billing\SubscriptionWebhookController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\EventPublicController;
use App\Http\Controllers\FormExportController;
use App\Http\Controllers\FormPublicController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\VerificationController;
use App\Livewire\Accounting\Index as AccountingIndex;
use App\Livewire\Business\Artisans as BusinessArtisans;
use App\Livewire\Business\Companies as BusinessCompanies;
use App\Livewire\Business\Edit as BusinessEdit;
use App\Livewire\Business\Logo as BusinessLogo;
use App\Livewire\Business\Reviews as BusinessReviews;
use App\Livewire\Business\Stationery;
use App\Livewire\CalendarPage\Index as CalendarIndex;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomerShow;
use App\Livewire\Dashboard;
use App\Livewire\Documents\Create as DocumentCreate;
use App\Livewire\Documents\Show as DocumentShow;
use App\Livewire\Events\Index as EventsIndex;
use App\Livewire\Events\Manage as EventsManage;
use App\Livewire\Events\Show as EventsShow;
use App\Livewire\Forms\Builder as FormsBuilder;
use App\Livewire\Forms\Index as FormsIndex;
use App\Livewire\Forms\Responses as FormsResponses;
use App\Livewire\Onboarding\Register;
use App\Livewire\Papers\Compose as PapersCompose;
use App\Livewire\Papers\Index as PapersIndex;
use App\Livewire\Papers\Show as PapersShow;
use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Products\Form as ProductForm;
use App\Livewire\Products\Index as ProductsIndex;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Livewire\Sales\Index as SalesIndex;
use App\Livewire\Scan;
use App\Livewire\Settings\Billing as SettingsBilling;
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

/*
 * Public demo request: the whole point is zero friction between "let me try
 * this" and a working, populated account in the visitor's inbox, so it sits
 * outside both the guest and auth groups — reachable either way.
 */
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/demo', [DemoRequestController::class, 'show'])->name('demo.request');
    Route::post('/demo', [DemoRequestController::class, 'store'])->name('demo.store');
    Route::get('/demo/thanks', [DemoRequestController::class, 'thanks'])->name('demo.thanks');
});

/*
 * MTN and Orange call back from their own servers, never from a signed-in
 * browser, so these sit outside `auth` and are CSRF-exempt (see
 * bootstrap/app.php). Neither webhook is trusted for its own content — see
 * SubscriptionWebhookController. The Orange return/cancel routes are where
 * the payer's browser lands after their hosted checkout, so they redirect
 * back into the app rather than returning a bare response.
 */
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/webhooks/mtn-momo', [SubscriptionWebhookController::class, 'mtnCallback'])->name('billing.mtn.callback');
    Route::post('/webhooks/orange-money', [SubscriptionWebhookController::class, 'orangeNotify'])->name('billing.orange.notify');
    Route::get('/billing/orange/return', [SubscriptionWebhookController::class, 'orangeReturn'])->name('billing.orange.return');
    Route::get('/billing/orange/cancel', [SubscriptionWebhookController::class, 'orangeCancel'])->name('billing.orange.cancel');
});

/*
 * Public marketing pages. No auth, no throttle beyond the contact POST — these
 * are read by anyone, signed in or not, and the whole point is zero friction.
 */
Route::get('/about', [MarketingController::class, 'about'])->name('marketing.about');
Route::get('/features', [MarketingController::class, 'features'])->name('marketing.features');
Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing');
Route::get('/blog', [MarketingController::class, 'blog'])->name('marketing.blog');
Route::get('/blog/{slug}', [MarketingController::class, 'blogShow'])->name('marketing.blog.show');
Route::get('/privacy', [MarketingController::class, 'privacy'])->name('marketing.privacy');
Route::get('/terms', [MarketingController::class, 'terms'])->name('marketing.terms');
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/contact', [MarketingController::class, 'contact'])->name('marketing.contact');
    Route::post('/contact', [MarketingController::class, 'storeContact'])->name('marketing.contact.store');
});

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::view('/account-suspended', 'account-suspended')->middleware('auth')->name('account-suspended');

/*
 * '/' is shared between the marketing home and the authenticated dashboard.
 * A route collection keys by method+URI, so two separate Route::get('/', ...)
 * registrations do not coexist — the second silently replaces the first
 * regardless of middleware. One route, branching on auth state, is the only
 * way both audiences land on the same URL.
 */
Route::get('/', function () {
    return Auth::check() ? app(Dashboard::class)() : app(MarketingController::class)->home();
})->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/sales', SalesIndex::class)->middleware('can:sales.view')->name('sales');

    Route::get('/customers', CustomersIndex::class)->middleware('can:customers.view')->name('customers');
    Route::get('/customers/create', CustomerForm::class)->middleware('can:customers.create')->name('customers.create');
    Route::get('/customers/{contact}', CustomerShow::class)->middleware('can:view,contact')->name('customers.show');
    Route::get('/customers/{contact}/statement', [PrintController::class, 'statement'])->middleware('can:view,contact')->name('customers.statement');
    Route::get('/customers/{contact}/edit', CustomerForm::class)->middleware('can:update,contact')->name('customers.edit');
    Route::get('/customers/{contact}/loyalty-card/print', [PrintController::class, 'loyaltyCard'])->middleware('can:view,contact')->name('customers.loyalty-card.print');
    Route::post('/customers/{contact}/loyalty-card/issue', [LoyaltyController::class, 'issueCard'])->name('loyalty.issue');
    Route::post('/customers/{contact}/loyalty/redeem', [LoyaltyController::class, 'redeem'])->name('loyalty.redeem');
    Route::post('/customers/{contact}/loyalty/adjust', [LoyaltyController::class, 'adjust'])->name('loyalty.adjust');

    // "create" is registered before the wildcard so it is never read as an id.
    Route::get('/documents/create', DocumentCreate::class)->middleware('can:sales.create')->name('documents.create');
    Route::get('/documents/{document}', DocumentShow::class)->middleware('can:view,document')->name('documents.show');
    Route::get('/documents/{document}/print', [PrintController::class, 'document'])->middleware('can:view,document')->name('documents.print');
    Route::get('/receipts/{receipt}/print', [PrintController::class, 'receipt'])->middleware('can:view,receipt')->name('receipts.print');

    Route::get('/products', ProductsIndex::class)->middleware('can:products.view')->name('products');
    Route::get('/products/create', ProductForm::class)->middleware('can:products.create')->name('products.create');
    Route::get('/products/{item}/edit', ProductForm::class)->middleware('can:update,item')->name('products.edit');

    Route::get('/business', BusinessEdit::class)->middleware('can:business.view')->name('business');
    // Registered inside the auth group, so it wins over the public
    // /business/{company} wildcard declared further down.
    Route::get('/business/reviews', BusinessReviews::class)->middleware('can:business.update')->name('business.reviews');
    Route::get('/business/logo', BusinessLogo::class)->middleware('can:business.manage-branding')->name('logo');
    Route::get('/business/logo/download', [PrintController::class, 'logo'])->middleware('can:business.manage-branding')->name('logo.download');
    Route::get('/business/stationery', Stationery::class)->middleware('can:business.manage-stationery')->name('stationery');
    Route::get('/businesses', BusinessCompanies::class)->name('businesses');
    Route::get('/artisans', BusinessArtisans::class)->middleware('can:business.view')->name('artisans');
    Route::get('/stationery/print', [PrintController::class, 'stationery'])->middleware('can:business.manage-stationery')->name('stationery.print');
    Route::get('/payments', PaymentsIndex::class)->middleware('can:payments.view')->name('payments');
    Route::get('/reports', ReportsIndex::class)->middleware('can:reports.view')->name('reports');
    Route::get('/accounting', AccountingIndex::class)->middleware('can:accounting.view')->name('accounting');

    /*
     * Module 13 — generated business documents (contracts, letters,
     * certificates). Its own prefix rather than a branch of /documents, whose
     * wildcard would otherwise swallow the index as a sales-document id.
     */
    Route::get('/library', PapersIndex::class)->middleware('can:papers.view')->name('papers');
    Route::get('/library/new/{template}', PapersCompose::class)->middleware('can:papers.create')->name('papers.create');
    Route::get('/library/{paper}', PapersShow::class)->middleware('can:view,paper')->name('papers.show');
    Route::get('/library/{paper}/edit', PapersCompose::class)->middleware('can:update,paper')->name('papers.edit');
    Route::get('/library/{paper}/print', [PrintController::class, 'paper'])->middleware('can:view,paper')->name('papers.print');
    /*
     * Module 16 — Opes Forms. The builder saves as it goes, so "create" is a
     * server action that makes a draft and lands in the builder, not a page.
     */
    Route::get('/forms', FormsIndex::class)->middleware('can:forms.view')->name('forms');
    Route::get('/forms/{form}/build', FormsBuilder::class)->middleware('can:update,form')->name('forms.build');
    Route::get('/forms/{form}/responses', FormsResponses::class)->middleware('can:responses,form')->name('forms.responses');
    Route::get('/forms/{form}/responses.csv', [FormExportController::class, 'csv'])->middleware('can:responses,form')->name('forms.responses.csv');

    /*
     * Module 17 — Opes Events. "create" before the wildcard, as with documents.
     */
    Route::get('/events', EventsIndex::class)->middleware('can:events.view')->name('events');
    Route::get('/events/create', EventsManage::class)->middleware('can:events.create')->name('events.create');
    Route::get('/events/{event}', EventsShow::class)->middleware('can:view,event')->name('events.show');
    Route::get('/events/{event}/edit', EventsManage::class)->middleware('can:update,event')->name('events.edit');

    // Check-in is POSTed from the public verification page by logged-in door
    // staff; the policy re-checks company membership and the ability.
    Route::post('/tickets/{ticket}/check-in', [TicketController::class, 'checkIn'])->name('tickets.check-in');
    Route::post('/tickets/{ticket}/void', [TicketController::class, 'void'])->name('tickets.void');
    Route::post('/tickets/{ticket}/paid', [TicketController::class, 'togglePaid'])->name('tickets.paid');

    Route::get('/calendar', CalendarIndex::class)->middleware('can:sales.view')->name('calendar');
    Route::get('/settings', SettingsIndex::class)->name('settings');
    Route::get('/settings/billing', SettingsBilling::class)->middleware('can:business.update')->name('settings.billing');
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
    Route::post('/lease', [SyncController::class, 'lease'])->name('sync.lease');
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

// Review submissions get a tighter limit than profile reads: one visitor has no
// business posting more than a handful of reviews a minute.
Route::post('/business/{company}/reviews', [ProfileController::class, 'submitReview'])
    ->middleware('throttle:10,1')->name('profile.business.review');

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
 * Public forms and event pages — what a share link opens. Same tenancy rule
 * as verification: the visitor is not a user, the token names the company.
 */
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/f/{token}/thanks', [FormPublicController::class, 'thanks'])->name('form.thanks');
    // The /embed variants render inside iframes on other websites — the only
    // routes in the product allowed to. See SecurityHeaders.
    Route::get('/f/{token}/embed', [FormPublicController::class, 'embed'])->name('form.embed');
    Route::get('/f/{token}', [FormPublicController::class, 'show'])->name('form.public');

    Route::get('/e/{token}/tickets', [EventPublicController::class, 'tickets'])->name('event.tickets');
    Route::get('/e/{token}/embed', [EventPublicController::class, 'embed'])->name('event.embed');
    Route::get('/e/{token}', [EventPublicController::class, 'show'])->name('event.public');
});

/*
 * The writes get a tighter limit than the reads, for the same reason review
 * submissions do: reading a form page costs a query, but submitting one writes
 * a row and emails every member who can see responses. At sixty a minute one
 * visitor could fill an inbox faster than anyone could empty it.
 */
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/f/{token}/embed', [FormPublicController::class, 'embedSubmit'])->name('form.embed.submit');
    Route::post('/f/{token}', [FormPublicController::class, 'submit'])->name('form.submit');
    Route::post('/e/{token}', [EventPublicController::class, 'purchase'])->name('event.purchase');
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

/*
 * Platform admin — entirely separate guard, session and routes from
 * everything above. See routes/admin.php.
 */
require __DIR__.'/admin.php';
