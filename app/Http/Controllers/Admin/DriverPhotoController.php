<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverPhoto;
use App\Services\DriverPhotos;
use Illuminate\Http\Request;

class DriverPhotoController extends Controller
{
    /** Motivos frecuentes: la central los usa de un clic en vez de escribirlos cada vez. */
    public const REASONS = [
        'Foto borrosa o movida',
        'No se distingue el vehículo',
        'No se distingue el rostro',
        'La placa no se lee',
        'La foto no corresponde al conductor',
        'Contenido no permitido',
    ];

    public function index(Request $request)
    {
        $status = $request->get('estado', 'pendiente');

        $q = DriverPhoto::with(['driver', 'reviewer']);
        if (in_array($status, ['pendiente', 'aprobado', 'rechazado'], true)) {
            $q->where('status', $status);
        }

        $photos       = $q->latest('id')->paginate(24)->withQueryString();
        $pendingCount = DriverPhoto::pending()->count();

        return view('admin.photos.index', [
            'photos'       => $photos,
            'pendingCount' => $pendingCount,
            'status'       => $status,
            'reasons'      => self::REASONS,
        ]);
    }

    public function approve(Request $request, DriverPhoto $photo)
    {
        if ($photo->status !== 'pendiente') {
            return back()->withErrors(['status' => 'Esta foto ya fue revisada.']);
        }

        DriverPhotos::approve($photo, $request->user()->id);

        $driver = $photo->driver->fresh();
        $extra  = $driver->canReceiveRides()
            ? ' El conductor ya puede conectarse.'
            : ' Aún le falta ' . implode(' y ', array_map(
                fn ($t) => $t === 'perfil' ? 'la foto de perfil' : 'la foto del vehículo',
                DriverPhotos::missing($driver)
            )) . '.';

        return back()->with('ok', "{$photo->typeLabel()} de {$photo->driver->full_name} aprobada." . $extra);
    }

    public function reject(Request $request, DriverPhoto $photo)
    {
        if ($photo->status !== 'pendiente') {
            return back()->withErrors(['status' => 'Esta foto ya fue revisada.']);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:160'],
        ], [
            'reason.required' => 'Escribe el motivo del rechazo: es lo que lee el conductor para corregir.',
        ]);

        DriverPhotos::reject($photo, $request->user()->id, $data['reason']);

        return back()->with('ok', "{$photo->typeLabel()} de {$photo->driver->full_name} rechazada. El conductor verá el motivo en su app.");
    }
}
