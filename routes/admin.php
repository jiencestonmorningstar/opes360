<?php

use App\Http\Controllers\Admin\ActivityController as AdminActivityController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PlatformAdminsController;
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
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/companies', [AdminCompanyController::class, 'index'])->name('companies');
        // withTrashed(): "full read access to everything" has to include a
        // business that has since been deleted, not just active ones — the
        // suspend/activate/plan actions below deliberately do NOT get this,
        // since acting on a deleted company isn't a meaningful operation.
        Route::get('/companies/{company}', [AdminCompanyController::class, 'show'])->name('companies.show')->withTrashed();
        Route::post('/companies/{company}/suspend', [AdminCompanyController::class, 'suspend'])->name('companies.suspend');
        Route::post('/companies/{company}/activate', [AdminCompanyController::class, 'activate'])->name('companies.activate');
        Route::post('/companies/{company}/plan', [AdminCompanyController::class, 'updatePlan'])->name('companies.plan');
        Route::post('/companies/{company}/members/{member}/remove', [AdminCompanyController::class, 'removeMember'])->name('companies.members.remove');
        Route::post('/companies/{company}/members/{member}/reset-password', [AdminCompanyController::class, 'resetMemberPassword'])->name('companies.members.reset-password');

        Route::get('/admins', [PlatformAdminsController::class, 'index'])->name('admins');
        Route::post('/admins', [PlatformAdminsController::class, 'store'])->name('admins.store');
        Route::delete('/admins/{admin}', [PlatformAdminsController::class, 'destroy'])->name('admins.destroy');

        Route::get('/activity', [AdminActivityController::class, 'index'])->name('activity');
    });
});
