<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AppAuthController;
use App\Http\Controllers\Api\AppCatalogController;
use App\Http\Controllers\Api\AppProfileController;
use App\Http\Controllers\Api\AppRechargeController;
use Illuminate\Support\Facades\Route;

/*
| Mobile app API.
|
| The path, the endpoint names and the response shapes are kept exactly as the
| installed Android client expects, so an existing APK keeps working against
| this backend without an update. The client sends `token` plus `deviceid` on
| every call and reads loosely-typed JSON, which is why the responses are built
| explicitly rather than from Eloquent resources.
|
| Both GET and POST are accepted on most endpoints because the shipped client
| is inconsistent about which it uses - some screens post a form, others append
| a query string to the same name.
*/

/* Unauthenticated ------------------------------------------------------- */

/*
 | The client posts the actual sign-in to the bare /apiapp path (the legacy
 | controller's index action), and uses /apiapp/loginchk only to ask whether an
 | existing session is still alive.
 */
Route::match(['get', 'post'], '/', [AppAuthController::class, 'login'])
    ->middleware('throttle:logins')
    ->name('login');

Route::match(['get', 'post'], 'index', [AppAuthController::class, 'login'])
    ->middleware('throttle:logins')
    ->name('login.index');

Route::match(['get', 'post'], 'loginchk', [AppAuthController::class, 'check'])
    ->name('session.check');

Route::match(['get', 'post'], 'otp', [AppAuthController::class, 'verifyOtp'])
    ->middleware('throttle:logins')
    ->name('otp');

Route::match(['get', 'post'], 'notice', [AppCatalogController::class, 'notices'])->name('notice');
Route::match(['get', 'post'], 'oparetorList', [AppCatalogController::class, 'operators'])->name('operators');
Route::match(['get', 'post'], 'countryList', [AppCatalogController::class, 'countries'])->name('countries');

/* Token-authenticated --------------------------------------------------- */

Route::middleware('app.token')->group(function () {

    /* Session and account */
    Route::match(['get', 'post'], 'Userchk', [AppAuthController::class, 'check'])->name('user.check');
    Route::match(['get', 'post'], 'balance', [AppProfileController::class, 'balance'])->name('balance');
    Route::match(['get', 'post'], 'role', [AppCatalogController::class, 'services'])->name('services');
    Route::match(['get', 'post'], 'pinchk', [AppAuthController::class, 'checkPin'])->name('pin.check');
    Route::match(['get', 'post'], 'dlock', [AppAuthController::class, 'deviceLock'])->name('device.lock');
    Route::match(['get', 'post'], 'logout', [AppAuthController::class, 'logout'])->name('logout');

    /* Recharge */
    Route::match(['get', 'post'], 'NewRequest', [AppRechargeController::class, 'store'])
        ->middleware('throttle:recharge')
        ->name('recharge');

    Route::match(['get', 'post'], 'Rechg', [AppRechargeController::class, 'store'])
        ->middleware('throttle:recharge')
        ->name('recharge.alt');

    Route::match(['get', 'post'], 'RechargeHistory', [AppRechargeController::class, 'history'])->name('history');
    Route::match(['get', 'post'], 'Otherhistory', [AppRechargeController::class, 'otherHistory'])->name('history.other');

    /* Catalogue lookups used by the recharge screens */
    Route::match(['get', 'post'], 'relnumber', [AppCatalogController::class, 'savedNumbers'])->name('numbers');
    Route::match(['get', 'post'], 'getinternet', [AppCatalogController::class, 'internetPackages'])->name('packages.internet');
    Route::match(['get', 'post'], 'getdrive', [AppCatalogController::class, 'drivePackages'])->name('packages.drive');
    Route::match(['get', 'post'], 'netSelect', [AppCatalogController::class, 'internetOperators'])->name('net.operators');
    Route::match(['get', 'post'], 'billpayProvider', [AppCatalogController::class, 'billProviders'])->name('bill.providers');
    Route::match(['get', 'post'], 'banklist', [AppCatalogController::class, 'banks'])->name('banks');
    Route::match(['get', 'post'], 'bankList', [AppCatalogController::class, 'banks'])->name('banks.alt');

    /* Rates */
    Route::match(['get', 'post'], 'myrate', [AppProfileController::class, 'myRates'])->name('rates');
    Route::match(['get', 'post'], 'myResellerRates', [AppProfileController::class, 'resellerRates'])->name('rates.downline');

    /* Balance movement */
    Route::match(['get', 'post'], 'payment', [AppProfileController::class, 'transfer'])->name('payment');
    Route::match(['get', 'post'], 'makepay', [AppProfileController::class, 'claimDeposit'])->name('deposit.claim');
    Route::match(['get', 'post'], 'payinfo', [AppProfileController::class, 'depositInfo'])->name('deposit.info');
    Route::match(['get', 'post'], 'autopayinfo', [AppProfileController::class, 'depositInfo'])->name('deposit.info.auto');
    Route::match(['get', 'post'], 'paymentlist', [AppProfileController::class, 'payments'])->name('payments');
    Route::match(['get', 'post'], 'receivelist', [AppProfileController::class, 'receipts'])->name('receipts');

    /* Downline */
    Route::match(['get', 'post'], 'reseller', [AppProfileController::class, 'downline'])->name('downline');
    Route::match(['get', 'post'], 'reselleradd', [AppProfileController::class, 'createDownline'])->name('downline.create');
    Route::match(['get', 'post'], 'resellerEdit', [AppProfileController::class, 'updateProfile'])->name('profile.update');

    /* Credentials */
    Route::match(['get', 'post'], 'password', [AppProfileController::class, 'changePassword'])->name('password');
    Route::match(['get', 'post'], 'pin', [AppProfileController::class, 'changePin'])->name('pin');

    /* Reports and support */
    Route::match(['get', 'post'], 'reports', [AppProfileController::class, 'reports'])->name('reports');
    Route::match(['get', 'post'], 'tricket_main', [AppProfileController::class, 'tickets'])->name('tickets');
    Route::match(['get', 'post'], 'trickets', [AppProfileController::class, 'ticketMessages'])->name('tickets.messages');
    Route::match(['get', 'post'], 'tricket_new', [AppProfileController::class, 'createTicket'])->name('tickets.create');
});
