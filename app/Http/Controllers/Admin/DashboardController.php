<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Recharge;
use App\Models\Setting;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'drivers_total'   => Driver::count(),
            'drivers_online'  => Driver::whereIn('status', ['disponible', 'ocupado'])->count(),
            'drivers_available' => Driver::where('status', 'disponible')->count(),
            'drivers_blocked' => Driver::where('account_status', '!=', 'activo')->count(),
            'saldo_total'     => (float) Driver::sum('saldo'),
            'recharges_pending' => Recharge::where('status', 'pendiente')->count(),
        ];

        $lowSaldo = \App\Services\Fare::minSaldo();
        $needRecharge = Driver::where('account_status', 'activo')
            ->where('saldo', '<', $lowSaldo)
            ->count();

        $recentDrivers = Driver::latest()->take(6)->get();
        $pendingRecharges = Recharge::with('driver')->where('status', 'pendiente')->latest()->take(5)->get();

        return view('admin.dashboard', compact('stats', 'needRecharge', 'recentDrivers', 'pendingRecharges'));
    }
}
