<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Reseller\DashboardController;
use App\Http\Controllers\Reseller\DepositController;
use App\Http\Controllers\Reseller\DownlineController;
use App\Http\Controllers\Reseller\ProfileController;
use App\Http\Controllers\Reseller\RateController;
use App\Http\Controllers\Reseller\RechargeController;
use App\Http\Controllers\Reseller\StatementController;
use App\Http\Controllers\Reseller\TicketController;
use Illuminate\Support\Facades\Route;

/*
| Reseller panel - the screens the distribution network uses day to day.
|
| Visibility is scoped by the signed-in account rather than by a permission
| matrix: a reseller sees their own transactions and their own downline, which
| the query scopes on the models enforce.
*/

Route::get('login', [LoginController::class, 'show'])
    ->defaults('panel', 'reseller')
    ->name('login');

Route::post('login', [LoginController::class, 'store'])
    ->defaults('panel', 'reseller')
    ->middleware('throttle:logins')
    ->name('login.store');

Route::post('logout', [LoginController::class, 'destroy'])
    ->defaults('panel', 'reseller')
    ->name('logout');

Route::middleware('panel:reseller')->group(function () {

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /* Recharge ----------------------------------------------------------- */

    Route::prefix('recharge')->name('recharge.')->group(function () {
        Route::get('/', [RechargeController::class, 'create'])->name('create');
        Route::post('/', [RechargeController::class, 'store'])
            ->middleware('throttle:recharge')->name('store');

        Route::get('history', [RechargeController::class, 'index'])->name('index');
        Route::get('{rechargeRequest}', [RechargeController::class, 'show'])->name('show');

        /* Live helpers used by the recharge form. */
        Route::get('lookup/operator', [RechargeController::class, 'lookupOperator'])->name('lookup.operator');
        Route::post('lookup/quote', [RechargeController::class, 'quote'])->name('lookup.quote');
        Route::get('lookup/packages', [RechargeController::class, 'packages'])->name('lookup.packages');
    });

    /* Balance ------------------------------------------------------------ */

    Route::get('statement', [StatementController::class, 'index'])->name('statement.index');

    Route::prefix('deposit')->name('deposit.')->group(function () {
        Route::get('/', [DepositController::class, 'create'])->name('create');
        Route::post('/', [DepositController::class, 'store'])->name('store');
        Route::get('history', [DepositController::class, 'index'])->name('index');
    });

    /* Downline ----------------------------------------------------------- */

    Route::prefix('downline')->name('downline.')->group(function () {
        Route::get('/', [DownlineController::class, 'index'])->name('index');
        Route::get('create', [DownlineController::class, 'create'])->name('create');
        Route::post('/', [DownlineController::class, 'store'])->name('store');
        Route::get('{reseller}/edit', [DownlineController::class, 'edit'])->name('edit');
        Route::put('{reseller}', [DownlineController::class, 'update'])->name('update');
        Route::post('{reseller}/balance', [DownlineController::class, 'transferBalance'])->name('balance');
    });

    Route::get('rates', [RateController::class, 'index'])->name('rates.index');

    /* Account ------------------------------------------------------------ */

    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', [TicketController::class, 'index'])->name('index');
        Route::get('create', [TicketController::class, 'create'])->name('create');
        Route::post('/', [TicketController::class, 'store'])->name('store');
        Route::get('{ticket}', [TicketController::class, 'show'])->name('show');
        Route::post('{ticket}/reply', [TicketController::class, 'reply'])->name('reply');
    });

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('security', [ProfileController::class, 'security'])->name('security.edit');
    Route::put('security/password', [ProfileController::class, 'updatePassword'])->name('security.password');
    Route::put('security/pin', [ProfileController::class, 'updatePin'])->name('security.pin');
});
