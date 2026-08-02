<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DriverController;
use App\Http\Controllers\Admin\RechargeController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Passenger\AuthController as PassengerAuth;
use App\Http\Controllers\Passenger\PageController as PassengerPage;
use App\Http\Controllers\Passenger\RideController as PassengerRide;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.login'));

/*
| App del PASAJERO (PWA instalable) — Hito 2
*/
Route::prefix('app')->name('app.')->group(function () {
    Route::get('/', [PassengerPage::class, 'app'])->name('home');

    // Autenticación (pública)
    Route::get('api/me', [PassengerAuth::class, 'me']);
    Route::post('api/register', [PassengerAuth::class, 'register']);
    Route::post('api/login', [PassengerAuth::class, 'login']);
    Route::post('api/logout', [PassengerAuth::class, 'logout']);

    // Viajes (requieren pasajero autenticado)
    Route::middleware('passenger')->group(function () {
        Route::post('api/quote', [PassengerRide::class, 'quote']);
        Route::post('api/rides', [PassengerRide::class, 'store']);
        Route::get('api/rides/current', [PassengerRide::class, 'current']);
        Route::post('api/rides/cancel', [PassengerRide::class, 'cancel']);
        Route::post('api/rides/rate', [PassengerRide::class, 'rate']);
        Route::get('api/rides/history', [PassengerRide::class, 'history']);
    });
});

Route::prefix('admin')->name('admin.')->group(function () {

    // Autenticación
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.attempt');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('password', [AuthController::class, 'updatePassword'])->name('password.update');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Conductores
        Route::get('conductores', [DriverController::class, 'index'])->name('drivers.index');
        Route::get('conductores/nuevo', [DriverController::class, 'create'])->name('drivers.create');
        Route::post('conductores', [DriverController::class, 'store'])->name('drivers.store');
        Route::get('conductores/{driver}', [DriverController::class, 'show'])->name('drivers.show');
        Route::get('conductores/{driver}/editar', [DriverController::class, 'edit'])->name('drivers.edit');
        Route::put('conductores/{driver}', [DriverController::class, 'update'])->name('drivers.update');
        Route::post('conductores/{driver}/estado', [DriverController::class, 'setAccountStatus'])->name('drivers.status');
        Route::post('conductores/{driver}/clave', [DriverController::class, 'resetPassword'])->name('drivers.password');
        Route::post('conductores/{driver}/saldo', [DriverController::class, 'adjustSaldo'])->name('drivers.saldo');
        Route::delete('conductores/{driver}', [DriverController::class, 'destroy'])->name('drivers.destroy');

        // Recargas
        Route::get('recargas', [RechargeController::class, 'index'])->name('recharges.index');
        Route::post('recargas', [RechargeController::class, 'store'])->name('recharges.store');
        Route::post('recargas/{recharge}/aprobar', [RechargeController::class, 'approve'])->name('recharges.approve');
        Route::post('recargas/{recharge}/rechazar', [RechargeController::class, 'reject'])->name('recharges.reject');

        // Configuración
        Route::get('configuracion', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('configuracion', [SettingController::class, 'update'])->name('settings.update');
    });
});
