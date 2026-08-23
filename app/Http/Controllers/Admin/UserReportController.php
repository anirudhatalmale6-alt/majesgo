<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserReport;
use Illuminate\Http\Request;

class UserReportController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('estado', 'pendiente');

        $q = UserReport::with(['ride', 'reviewer']);
        if (in_array($status, ['pendiente', 'revisado'], true)) {
            $q->where('status', $status);
        }

        $reports = $q->latest('id')->paginate(25)->withQueryString();

        // Antecedentes del denunciado: es lo primero que se pregunta la central al abrir
        // un caso ("¿es la primera vez o ya viene arrastrando?").
        $history = [];
        foreach ($reports as $r) {
            $key = $r->reported_type . ':' . $r->reported_id;
            $history[$key] ??= UserReport::where('reported_type', $r->reported_type)
                ->where('reported_id', $r->reported_id)
                ->count();
        }

        return view('admin.reports.index', [
            'reports'      => $reports,
            'status'       => $status,
            'pendingCount' => UserReport::pending()->count(),
            'history'      => $history,
        ]);
    }

    /** Marcar como revisada (opcionalmente con una nota de lo que se hizo). */
    public function review(Request $request, UserReport $report)
    {
        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        $report->update([
            'status'      => 'revisado',
            'admin_note'  => trim((string) ($data['admin_note'] ?? '')) ?: null,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        return back()->with('ok', 'Denuncia marcada como revisada.');
    }

    /** Volver a dejarla pendiente, si se cerró por error. */
    public function reopen(Request $request, UserReport $report)
    {
        $report->update([
            'status'      => 'pendiente',
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        return back()->with('ok', 'Denuncia reabierta.');
    }
}
