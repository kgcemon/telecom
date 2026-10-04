<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LedgerController;
use App\Http\Controllers\Admin\OperatorController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RequestQueueController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\RouteController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TariffController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
| Admin panel.
|
| Every route sits behind `panel:admin`, and each feature permission is
| declared next to the route rather than inside the controller, so the sidebar
| and the server-side guard are driven from the same declaration and cannot
| drift apart.
*/

Route::get('login', [LoginController::class, 'show'])
    ->defaults('panel', 'admin')
    ->name('login');

Route::post('login', [LoginController::class, 'store'])
    ->defaults('panel', 'admin')
    ->middleware('throttle:logins')
    ->name('login.store');

Route::post('logout', [LoginController::class, 'destroy'])
    ->defaults('panel', 'admin')
    ->name('logout');

Route::middleware('panel:admin')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/series', [DashboardController::class, 'series'])->name('dashboard.series');

    /* Transaction queue -------------------------------------------------- */

    Route::prefix('requests')->name('requests.')->group(function () {
        Route::get('pending', [RequestQueueController::class, 'pending'])
            ->middleware('can.feature:request_view')->name('pending');

        Route::get('/', [RequestQueueController::class, 'index'])
            ->middleware('can.feature:request_view')->name('index');

        Route::get('{rechargeRequest}', [RequestQueueController::class, 'show'])
            ->middleware('can.feature:request_details')->name('show');

        Route::post('{rechargeRequest}/approve', [RequestQueueController::class, 'approve'])
            ->middleware('can.feature:request_confirm')->name('approve');

        Route::post('{rechargeRequest}/cancel', [RequestQueueController::class, 'cancel'])
            ->middleware('can.feature:request_cancel')->name('cancel');

        Route::post('{rechargeRequest}/resend', [RequestQueueController::class, 'resend'])
            ->middleware('can.feature:request_resend')->name('resend');

        Route::post('bulk', [RequestQueueController::class, 'bulk'])
            ->middleware('can.feature:request_confirm')->name('bulk');
    });

    /* Ledger ------------------------------------------------------------- */

    Route::get('ledger', [LedgerController::class, 'index'])
        ->middleware('can.feature:reports')->name('ledger.index');

    /* Resellers ---------------------------------------------------------- */

    Route::prefix('resellers')->name('resellers.')->group(function () {
        Route::get('/', [ResellerController::class, 'index'])
            ->middleware('can.feature:reseller_manage')->name('index');

        Route::get('create', [ResellerController::class, 'create'])
            ->middleware('can.feature:reseller_add')->name('create');

        Route::post('/', [ResellerController::class, 'store'])
            ->middleware('can.feature:reseller_add')->name('store');

        Route::get('{reseller}', [ResellerController::class, 'show'])
            ->middleware('can.feature:reseller_manage')->name('show');

        Route::get('{reseller}/edit', [ResellerController::class, 'edit'])
            ->middleware('can.feature:reseller_edit')->name('edit');

        Route::put('{reseller}', [ResellerController::class, 'update'])
            ->middleware('can.feature:reseller_edit')->name('update');

        Route::post('{reseller}/permissions', [ResellerController::class, 'syncPermissions'])
            ->middleware('can.feature:reseller_edit')->name('permissions');

        Route::delete('{reseller}', [ResellerController::class, 'destroy'])
            ->middleware('can.feature:server_delete_res')->name('destroy');
    });

    /* Payments ----------------------------------------------------------- */

    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])
            ->middleware('can.feature:payment_history')->name('index');

        Route::get('create', [PaymentController::class, 'create'])
            ->middleware('can.feature:payment_add_return')->name('create');

        Route::post('/', [PaymentController::class, 'store'])
            ->middleware('can.feature:payment_add_return')->name('store');

        Route::get('requests', [PaymentController::class, 'requests'])
            ->middleware('can.feature:pay_log')->name('requests');

        Route::post('requests/{paymentRequest}/approve', [PaymentController::class, 'approveRequest'])
            ->middleware('can.feature:payment_add_return')->name('requests.approve');

        Route::post('requests/{paymentRequest}/reject', [PaymentController::class, 'rejectRequest'])
            ->middleware('can.feature:payment_add_return')->name('requests.reject');
    });

    /* Pricing ------------------------------------------------------------ */

    Route::prefix('tariffs')->name('tariffs.')->middleware('can.feature:server_rate')->group(function () {
        Route::get('/', [TariffController::class, 'index'])->name('index');
        Route::get('prices', [TariffController::class, 'prices'])->name('prices');
        Route::put('prices', [TariffController::class, 'updatePrices'])->name('prices.update');
        Route::get('{tariff}', [TariffController::class, 'show'])->name('show');
        Route::put('{tariff}', [TariffController::class, 'update'])->name('update');
    });

    /* Catalogue ---------------------------------------------------------- */

    Route::prefix('services')->name('services.')->middleware('can.feature:server_module')->group(function () {
        Route::get('/', [ServiceController::class, 'index'])->name('index');
        Route::put('{service}', [ServiceController::class, 'update'])->name('update');
    });

    Route::prefix('operators')->name('operators.')->middleware('can.feature:Oparetor_per')->group(function () {
        Route::get('/', [OperatorController::class, 'index'])->name('index');
        Route::post('/', [OperatorController::class, 'store'])->name('store');
        Route::put('{operator}', [OperatorController::class, 'update'])->name('update');
    });

    /* Routing and providers ---------------------------------------------- */

    Route::prefix('routes')->name('routes.')->middleware('can.feature:route')->group(function () {
        Route::get('/', [RouteController::class, 'index'])->name('index');
        Route::post('/', [RouteController::class, 'store'])->name('store');
        Route::put('{route}', [RouteController::class, 'update'])->name('update');
        Route::delete('{route}', [RouteController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('providers')->name('providers.')->middleware('can.feature:server_api')->group(function () {
        Route::get('/', [ProviderController::class, 'index'])->name('index');
        Route::post('/', [ProviderController::class, 'store'])->name('store');
        Route::put('{provider}', [ProviderController::class, 'update'])->name('update');
        Route::post('{provider}/balance', [ProviderController::class, 'refreshBalance'])->name('balance');
    });

    /* Reports ------------------------------------------------------------ */

    Route::prefix('reports')->name('reports.')->middleware('can.feature:reports')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('operators', [ReportController::class, 'operators'])->name('operators');
        Route::get('daily', [ReportController::class, 'daily'])->name('daily');
        Route::get('profit', [ReportController::class, 'profit'])->name('profit');
        Route::get('export', [ReportController::class, 'export'])->name('export');
    });

    /* Settings ----------------------------------------------------------- */

    Route::prefix('settings')->name('settings.')->middleware('can.feature:server_security')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::put('company', [SettingsController::class, 'updateCompany'])->name('company');
        Route::put('security', [SettingsController::class, 'updateSecurity'])->name('security');
        Route::put('limits', [SettingsController::class, 'updateLimits'])->name('limits');
        Route::put('wallets', [SettingsController::class, 'updateWallets'])->name('wallets');
        Route::put('deposits', [SettingsController::class, 'updateDeposits'])->name('deposits');
    });

    /* Security ----------------------------------------------------------- */

    Route::prefix('security')->name('security.')->group(function () {
        Route::get('devices', [SecurityController::class, 'devices'])
            ->middleware('can.feature:device_logs')->name('devices');

        Route::post('devices/{device}/revoke', [SecurityController::class, 'revokeDevice'])
            ->middleware('can.feature:device_logs')->name('devices.revoke');

        Route::get('logs', [SecurityController::class, 'logs'])
            ->middleware('can.feature:access_logs')->name('logs');

        Route::get('online', [SecurityController::class, 'online'])
            ->middleware('can.feature:online_user')->name('online');

        Route::get('me', [SecurityController::class, 'edit'])->name('edit');
        Route::put('me/password', [SecurityController::class, 'updatePassword'])->name('password');
        Route::put('me/pin', [SecurityController::class, 'updatePin'])->name('pin');
    });

    Route::get('profile', [SecurityController::class, 'profile'])->name('profile.edit');
    Route::put('profile', [SecurityController::class, 'updateProfile'])->name('profile.update');
});
