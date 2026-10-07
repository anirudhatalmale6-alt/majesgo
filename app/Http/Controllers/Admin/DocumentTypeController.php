<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\DriverDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Los documentos que la central le exige a un conductor, editables desde el panel.
 *
 * Esto existe para que un cambio de requisitos de la municipalidad no dependa de que
 * alguien toque el programa: se agrega una fila y al día siguiente todos los conductores
 * ven el documento nuevo en su lista de pendientes.
 */
class DocumentTypeController extends Controller
{
    public function index()
    {
        $tipos = DocumentType::orderBy('sort_order')->orderBy('id')->get();

        // Cuántos conductores tienen cada tipo aprobado: es el dato que hace falta antes de
        // desactivar o volver obligatorio un documento.
        $aprobados = DriverDocument::where('status', 'aprobado')
            ->selectRaw('document_type_id, COUNT(DISTINCT driver_id) AS n')
            ->groupBy('document_type_id')
            ->pluck('n', 'document_type_id');

        return view('admin.tipos-documento.index', compact('tipos', 'aprobados'));
    }

    public function store(Request $request)
    {
        $d = $this->validar($request);

        DocumentType::create([
            'key'          => $this->claveLibre($d['name']),
            'name'         => $d['name'],
            'help'         => $d['help'] ?? null,
            'required'     => $request->boolean('required'),
            'has_expiry'   => $request->boolean('has_expiry'),
            'needs_number' => $request->boolean('needs_number'),
            'sort_order'   => (int) (DocumentType::max('sort_order') ?? 0) + 10,
            'active'       => true,
        ]);

        return back()->with('ok', 'Documento agregado. Los conductores ya lo ven en su lista.');
    }

    public function update(Request $request, DocumentType $tipo)
    {
        $d = $this->validar($request);

        // La clave NO se toca al renombrar: es lo que ata los documentos ya enviados a su
        // tipo. Cambiarla dejaría huérfanos los papeles que la gente ya subió.
        $tipo->update([
            'name'         => $d['name'],
            'help'         => $d['help'] ?? null,
            'required'     => $request->boolean('required'),
            'has_expiry'   => $request->boolean('has_expiry'),
            'needs_number' => $request->boolean('needs_number'),
        ]);

        return back()->with('ok', 'Documento actualizado.');
    }

    /**
     * Desactivar en vez de borrar.
     *
     * Un tipo con documentos ya aprobados no se puede eliminar sin llevarse puesto el
     * historial de quién presentó qué y quién lo revisó. Desactivado deja de pedirse a los
     * nuevos y lo ya entregado se conserva.
     */
    public function toggle(DocumentType $tipo)
    {
        $tipo->update(['active' => ! $tipo->active]);

        return back()->with('ok', $tipo->active
            ? 'Documento reactivado: vuelve a pedirse.'
            : 'Documento desactivado: deja de pedirse, y lo ya entregado se conserva.');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'help' => ['nullable', 'string', 'max:120'],
        ], [], ['name' => 'el nombre', 'help' => 'la ayuda']);
    }

    /** Clave estable a partir del nombre, sin chocar con una existente. */
    private function claveLibre(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'documento';
        $key  = Str::limit($base, 40, '');
        $i    = 2;

        while (DocumentType::where('key', $key)->exists()) {
            $key = Str::limit($base, 37, '').'_'.$i++;
        }

        return $key;
    }
}
