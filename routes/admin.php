<?php

use App\Http\Controllers\Admin\ActivityController as AdminActivityController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PlatformAdminsController;
use App\Http\Controllers\Admin\TwoFactorController as AdminTwoFactorController;
use Illuminate\Support\Facades\Route;

/*
 * Platform admin. Its own guard ('admin'), its own login, its own prefix —
 * nothing here shares session, middleware or routes with the business side
 * in routes/web.php. See config/auth.php for the guard/provider split and
 * App\Models\PlatformAdmin's docblock for why it's a separate table.
 */
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::view('/login', 'admin.auth.login')->name('login');
        Route::post('/login', [AdminAuthController::class, 'login'])->name('login.attempt');

        Route::view('/forgot-password', 'admin.auth.forgot-password')->name('password.request');
        Route::post('/forgot-password', [AdminAuthController::class, 'sendResetLink'])->name('password.email');

        Route::get('/reset-password/{token}', fn (string $token) => view('admin.auth.reset-password', ['token' => $token]))
            ->name('password.reset');
        Route::post('/reset-password', [AdminAuthController::class, 'resetPassword'])->name('password.update');

        // The challenge sits between credentials and an authenticated
        // session, so it's reachable by a guest — the session holds only a
        // pending admin id (see AuthController's docblock on why the
        // session key is namespaced separately from the business flow's).
        Route::get('/two-factor', [AdminAuthController::class, 'showChallenge'])->name('two-factor.challenge');
        Route::post('/two-factor', [AdminAuthController::class, 'challenge'])->name('two-factor.verify');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/companies', [AdminCompanyController::class, 'index'])->name('companies');
        // Must be registered before /companies/{company} — otherwise the
        // {company} wildcard matches "export" first and 404s trying to
        // resolve it as a slug.
        Route::get('/companies/export', [AdminCompanyController::class, 'export'])->name('companies.export');
        // withTrashed(): "full read access to everything" has to include a
        // business that has since been deleted, not just active ones — the
        // suspend/activate/plan actions below deliberately do NOT get this,
        // since acting on a deleted company isn't a meaningful operation.
        Route::get('/companies/{company}', [AdminCompanyController::class, 'show'])->name('companies.show')->withTrashed();
        Route::post('/companies/{company}/suspend', [AdminCompanyController::class, 'suspend'])->name('companies.suspend');
        Route::post('/companies/{company}/activate', [AdminCompanyController::class, 'activate'])->name('companies.activate');
        // The two actions with real financial/security weight — changing what
        // a business pays for, and who else has full access to the whole
        // platform — are gated to the 'admin' role. Everything above stays
        // open to 'support' too: read access and routine support actions.
        Route::post('/companies/{company}/plan', [AdminCompanyController::class, 'updatePlan'])->name('companies.plan')->middleware('admin.role');
        Route::post('/companies/{company}/members/{member}/remove', [AdminCompanyController::class, 'removeMember'])->name('companies.members.remove');
        Route::post('/companies/{company}/members/{member}/reset-password', [AdminCompanyController::class, 'resetMemberPassword'])->name('companies.members.reset-password');
        Route::post('/companies/{company}/extend-demo', [AdminCompanyController::class, 'extendDemo'])->name('companies.extend-demo');
        Route::post('/companies/{company}/end-demo', [AdminCompanyController::class, 'endDemo'])->name('companies.end-demo');
        Route::post('/companies/{company}/notes', [AdminCompanyController::class, 'addNote'])->name('companies.notes.store');

        Route::get('/admins', [PlatformAdminsController::class, 'index'])->name('admins');
        Route::post('/admins', [PlatformAdminsController::class, 'store'])->name('admins.store')->middleware('admin.role');
        Route::delete('/admins/{admin}', [PlatformAdminsController::class, 'destroy'])->name('admins.destroy')->middleware('admin.role');

        Route::get('/activity', [AdminActivityController::class, 'index'])->name('activity');

        Route::get('/settings', [AdminTwoFactorController::class, 'show'])->name('settings');
        Route::get('/settings/two-factor/qr.svg', [AdminTwoFactorController::class, 'qr'])->name('two-factor.qr');
        Route::post('/settings/two-factor/start', [AdminTwoFactorController::class, 'start'])->name('two-factor.start');
        Route::post('/settings/two-factor/confirm', [AdminTwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::post('/settings/two-factor/cancel', [AdminTwoFactorController::class, 'cancel'])->name('two-factor.cancel');
        Route::post('/settings/two-factor/disable', [AdminTwoFactorController::class, 'disable'])->name('two-factor.disable');
    });
});
