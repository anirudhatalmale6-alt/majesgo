<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DriverController;
use App\Http\Controllers\Admin\RechargeController;
use App\Http\Controllers\Admin\PlaceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Passenger\AuthController as PassengerAuth;
use App\Http\Controllers\Passenger\PageController as PassengerPage;
use App\Http\Controllers\Passenger\RideController as PassengerRide;
use App\Http\Controllers\Driver\AuthController as DriverAuth;
use App\Http\Controllers\Driver\PageController as DriverPage;
use App\Http\Controllers\Driver\RideController as DriverRide;
use App\Http\Controllers\GeocodeController;
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
        Route::get('api/geocode/reverse', [GeocodeController::class, 'reverse']);
        Route::get('api/geocode/search', [GeocodeController::class, 'search']);
        Route::get('api/zones', [GeocodeController::class, 'zones']);
        Route::get('api/drivers/nearby', [PassengerRide::class, 'nearbyDrivers']);
        Route::post('api/push/subscribe', [PassengerRide::class, 'subscribePush']);
        Route::post('api/quote', [PassengerRide::class, 'quote']);
        Route::post('api/rides', [PassengerRide::class, 'store']);
        Route::get('api/rides/current', [PassengerRide::class, 'current']);
        Route::post('api/rides/confirm-driver', [PassengerRide::class, 'confirmDriver']);
        Route::post('api/rides/reject-driver', [PassengerRide::class, 'rejectDriver']);
        Route::get('api/rides/messages', [PassengerRide::class, 'messages']);
        Route::post('api/rides/messages', [PassengerRide::class, 'sendMessage']);
        Route::post('api/rides/cancel', [PassengerRide::class, 'cancel']);
        Route::post('api/rides/rate', [PassengerRide::class, 'rate']);
        Route::post('api/rides/ack', [PassengerRide::class, 'ack']);
        Route::get('api/rides/history', [PassengerRide::class, 'history']);
    });
});

/*
| App del CONDUCTOR (PWA instalable) — Hito 3
*/
Route::prefix('conductor')->name('driver.')->group(function () {
    Route::get('/', [DriverPage::class, 'app'])->name('home');

    // Autenticación (las cuentas las crea el administrador; el conductor solo inicia sesión)
    Route::get('api/me', [DriverAuth::class, 'me']);
    Route::post('api/login', [DriverAuth::class, 'login']);
    Route::post('api/logout', [DriverAuth::class, 'logout']);

    Route::middleware('driver')->group(function () {
        // Conexión y ubicación
        Route::post('api/connect', [DriverRide::class, 'connect']);
        Route::post('api/location', [DriverRide::class, 'location']);
        Route::post('api/reroute', [DriverRide::class, 'reroute']);
        Route::post('api/push/subscribe', [DriverRide::class, 'subscribePush']);

        // Solicitudes entrantes
        Route::get('api/pending', [DriverRide::class, 'pending']);
        Route::post('api/accept', [DriverRide::class, 'accept']);
        Route::post('api/reject', [DriverRide::class, 'reject']);

        // Viaje en curso
        Route::get('api/current', [DriverRide::class, 'current']);
        Route::get('api/messages', [DriverRide::class, 'messages']);
        Route::post('api/messages', [DriverRide::class, 'sendMessage']);
        Route::post('api/arrive', [DriverRide::class, 'arrive']);
        Route::post('api/start', [DriverRide::class, 'start']);
        Route::post('api/complete', [DriverRide::class, 'complete']);
        Route::post('api/cancel', [DriverRide::class, 'cancel']);
        Route::post('api/rate', [DriverRide::class, 'ratePassenger']);
        Route::post('api/ack', [DriverRide::class, 'ack']);

        // Saldo y recargas
        Route::get('api/saldo', [DriverRide::class, 'saldo']);
        Route::post('api/recharge', [DriverRide::class, 'recharge']);
        Route::get('api/history', [DriverRide::class, 'history']);
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

        // Zonas locales (referencias personalizadas)
        Route::get('zonas', [PlaceController::class, 'index'])->name('places.index');
        Route::get('zonas/nueva', [PlaceController::class, 'create'])->name('places.create');
        Route::post('zonas', [PlaceController::class, 'store'])->name('places.store');
        Route::get('zonas/{place}/editar', [PlaceController::class, 'edit'])->name('places.edit');
        Route::put('zonas/{place}', [PlaceController::class, 'update'])->name('places.update');
        Route::delete('zonas/{place}', [PlaceController::class, 'destroy'])->name('places.destroy');

        // Configuración
        Route::get('configuracion', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('configuracion', [SettingController::class, 'update'])->name('settings.update');
    });
});
