<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Recharge;
use Illuminate\Http\Request;

class RechargeController extends Controller
{
    public function index(Request $request)
    {
        $q = Recharge::with(['driver', 'validator']);
        if ($status = $request->get('estado')) {
            $q->where('status', $status);
        }
        $recharges = $q->latest()->paginate(20)->withQueryString();
        $pendingCount = Recharge::where('status', 'pendiente')->count();

        return view('admin.recharges.index', compact('recharges', 'pendingCount'));
    }

    /** Carga manual de saldo hecha por el administrador (queda aprobada al instante). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'driver_id' => ['required', 'exists:drivers,id'],
            'amount'    => ['required', 'numeric', 'min:0.5'],
            'method'    => ['required', 'in:yape,transferencia,admin'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note'      => ['nullable', 'string', 'max:255'],
        ]);

        $driver = Driver::findOrFail($data['driver_id']);

        $recharge = Recharge::create([
            'driver_id'    => $driver->id,
            'amount'       => $data['amount'],
            'method'       => $data['method'],
            'reference'    => $data['reference'] ?? null,
            'note'         => $data['note'] ?? null,
            'status'       => 'aprobado',
            'validated_by' => $request->user()->id,
            'validated_at' => now(),
        ]);

        $driver->applyMovement(
            'recarga',
            (float) $data['amount'],
            'Recarga ' . $recharge->methodLabel(),
            'recharge',
            $recharge->id,
            $request->user()->id
        );

        return back()->with('ok', 'Recarga aplicada. Nuevo saldo del conductor: S/ ' . number_format($driver->saldo, 2));
    }

    public function approve(Request $request, Recharge $recharge)
    {
        if ($recharge->status !== 'pendiente') {
            return back()->withErrors(['status' => 'Esta recarga ya fue procesada.']);
        }

        $recharge->update([
            'status'       => 'aprobado',
            'validated_by' => $request->user()->id,
            'validated_at' => now(),
        ]);

        $recharge->driver->applyMovement(
            'recarga',
            (float) $recharge->amount,
            'Recarga ' . $recharge->methodLabel() . ' aprobada',
            'recharge',
            $recharge->id,
            $request->user()->id
        );

        return back()->with('ok', 'Recarga aprobada y saldo acreditado.');
    }

    public function reject(Request $request, Recharge $recharge)
    {
        if ($recharge->status !== 'pendiente') {
            return back()->withErrors(['status' => 'Esta recarga ya fue procesada.']);
        }
        $recharge->update([
            'status'       => 'rechazado',
            'validated_by' => $request->user()->id,
            'validated_at' => now(),
            'note'         => $request->get('note', $recharge->note),
        ]);

        return back()->with('ok', 'Recarga rechazada.');
    }
}
