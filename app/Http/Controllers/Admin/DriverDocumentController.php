<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Services\DriverDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Bandeja de postulaciones de conductores.
 *
 * Se agrupa POR CONDUCTOR y no por documento suelto: quien revisa quiere ver la
 * postulación completa de una persona y decidir, no siete archivos sueltos de siete
 * personas distintas.
 */
class DriverDocumentController extends Controller
{
    public function index(Request $request)
    {
        $estado = $request->query('estado', 'pendientes');

        $q = Driver::query()
            ->where(function ($w) {
                $w->whereIn('onboarding_status', ['enviado', 'registrado', 'observado'])
                  ->orWhereHas('documents', fn ($d) => $d->where('status', 'pendiente'));
            })
            ->withCount([
                'documents as pendientes_count' => fn ($d) => $d->where('status', 'pendiente'),
            ]);

        if ($estado === 'pendientes') {
            $q->where(fn ($w) => $w->where('onboarding_status', '!=', 'aprobado'));
        }

        $drivers = $q->orderByRaw("CASE onboarding_status WHEN 'enviado' THEN 0 WHEN 'observado' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.postulaciones.index', compact('drivers', 'estado'));
    }

    public function show(Driver $driver)
    {
        $driver->load(['documents.type', 'documents.reviewer']);
        $checklist = DriverDocuments::checklist($driver);
        $faltan    = DriverDocuments::missing($driver);

        return view('admin.postulaciones.show', compact('driver', 'checklist', 'faltan'));
    }

    /**
     * El archivo. NUNCA se sirve desde una carpeta pública: un DNI o unos antecedentes no
     * pueden quedar en una URL que abra cualquiera con el enlace. Pasa por acá, con la
     * sesión de la central.
     */
    public function file(DriverDocument $document)
    {
        if (! Storage::disk(DriverDocuments::DISK)->exists($document->path)) {
            abort(404);
        }

        return Storage::disk(DriverDocuments::DISK)->response(
            $document->path,
            null,
            ['Content-Disposition' => 'inline', 'X-Robots-Tag' => 'noindex, nofollow']
        );
    }

    public function approve(Request $request, DriverDocument $document)
    {
        if ($document->status !== 'pendiente') {
            return back()->with('error', 'Ese documento ya fue revisado.');
        }

        DriverDocuments::approve($document, $request->user()->id);

        return back()->with('ok', 'Documento aprobado.');
    }

    public function reject(Request $request, DriverDocument $document)
    {
        $d = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ], [], ['reason' => 'el motivo']);

        if ($document->status !== 'pendiente') {
            return back()->with('error', 'Ese documento ya fue revisado.');
        }

        DriverDocuments::reject($document, $request->user()->id, $d['reason']);

        return back()->with('ok', 'Documento rechazado. El conductor ve el motivo en su app.');
    }

    /**
     * Habilitación manual, que fue un pedido explícito de la central: poder aprobar a
     * alguien aunque el sistema lo tenga bloqueado. Queda registrado quién lo hizo y
     * cuándo; si se le da un plazo, el conductor trabaja mientras regulariza.
     */
    public function approveDriver(Request $request, Driver $driver)
    {
        $d = $request->validate([
            'dias_plazo' => ['nullable', 'integer', 'min:1', 'max:180'],
        ], [], ['dias_plazo' => 'los días de plazo']);

        $driver->update([
            'onboarding_status' => 'aprobado',
            'approved_at'       => now(),
            'approved_by'       => $request->user()->id,
            'docs_due_at'       => isset($d['dias_plazo']) ? now()->addDays((int) $d['dias_plazo']) : $driver->docs_due_at,
        ]);

        return back()->with('ok', 'Conductor habilitado'.(isset($d['dias_plazo'])
            ? ' con '.$d['dias_plazo'].' días de plazo para completar sus documentos.' : '.'));
    }
}
