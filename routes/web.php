<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Entry point only. The panels live in routes/admin.php and
| routes/reseller.php, and the mobile API in routes/apiapp.php; all three are
| registered in bootstrap/app.php so each gets its own middleware group.
*/

Route::get('/', function () {
    if (auth()->guard('admin')->check()) {
        return redirect()->route('admin.dashboard');
    }

    if (auth()->guard('reseller')->check()) {
        return redirect()->route('reseller.dashboard');
    }

    return redirect()->route('reseller.login');
})->name('home');
