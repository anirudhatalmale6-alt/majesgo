<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\Driver;
use App\Models\DriverDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documentos del conductor: envío, revisión y, sobre todo, EL CANDADO.
 *
 * Reglas que sostienen todo esto:
 *
 * 1. Lo que sube el conductor queda PENDIENTE y no reemplaza a lo aprobado. Si no fuera
 *    así, alguien podría hacerse aprobar una licencia buena y cambiarla después.
 * 2. El candado vive en el SERVIDOR, en canReceiveRides(), que es el único punto por el
 *    que se pasa para conectarse y para entrar al despacho. Nada de esconder botones.
 * 3. Aprobado no es lo mismo que vigente: un documento con fecha vencida no habilita,
 *    aunque la fila diga 'aprobado' (ver DriverDocument::vigente()).
 * 4. Los archivos van al disco PRIVADO. Un DNI o unos antecedentes no pueden vivir en
 *    una URL que abre cualquiera, como sí pasa hoy con las fotos de perfil.
 */
class DriverDocuments
{
    /** Disco privado: storage/app/private. NO se sirve solo, hay que pasar por la central. */
    public const DISK = 'local';

    private const DIR = 'documentos';

    public const RULES = ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'];

    /** ¿Está encendida la exigencia de documentos? */
    public static function required(): bool
    {
        return (string) \App\Models\Setting::get('require_documents', '0') === '1';
    }

    /**
     * El conductor envía un documento. Queda pendiente de revisión.
     * Si ya había uno pendiente del mismo tipo, se reemplaza (no se acumulan borradores).
     */
    public static function submit(Driver $driver, DocumentType $type, UploadedFile $file,
                                  ?string $number = null, ?string $expires = null): DriverDocument
    {
        $anterior = DriverDocument::where('driver_id', $driver->id)
            ->where('document_type_id', $type->id)
            ->where('status', 'pendiente')
            ->first();

        if ($anterior) {
            Storage::disk(self::DISK)->delete($anterior->path);
            $anterior->delete();
        }

        $nombre = self::DIR.'/'.$driver->id.'/'.$type->key.'-'.Str::random(10).'.'.$file->getClientOriginalExtension();
        Storage::disk(self::DISK)->put($nombre, $file->get());

        return DriverDocument::create([
            'driver_id'        => $driver->id,
            'document_type_id' => $type->id,
            'path'             => $nombre,
            'number'           => $number,
            'expires_at'       => $type->has_expiry ? $expires : null,
            'status'           => 'pendiente',
        ]);
    }

    public static function approve(DriverDocument $doc, int $userId): void
    {
        // Al aprobar uno nuevo, el aprobado anterior del mismo tipo deja de estar vigente:
        // se marca como reemplazado para que el historial no muestre dos "aprobados" a la vez.
        DriverDocument::where('driver_id', $doc->driver_id)
            ->where('document_type_id', $doc->document_type_id)
            ->where('id', '!=', $doc->id)
            ->where('status', 'aprobado')
            ->update(['status' => 'reemplazado']);

        $doc->update([
            'status'        => 'aprobado',
            'reject_reason' => null,
            'reviewed_by'   => $userId,
            'reviewed_at'   => now(),
        ]);

        self::refreshOnboarding($doc->driver);
    }

    public static function reject(DriverDocument $doc, int $userId, string $reason): void
    {
        // El archivo se borra (no hay motivo para conservar un DNI rechazado), pero la fila
        // queda: es el registro de que se revisó, quién y por qué.
        Storage::disk(self::DISK)->delete($doc->path);

        $doc->update([
            'status'        => 'rechazado',
            'reject_reason' => $reason,
            'reviewed_by'   => $userId,
            'reviewed_at'   => now(),
        ]);

        $doc->driver->update(['onboarding_status' => 'observado']);
    }

    /** Tipos exigidos que este conductor NO tiene vigentes hoy. */
    public static function missing(Driver $driver): array
    {
        if ($driver->is_demo || $driver->is_reviewer) {
            return [];
        }

        $vigentes = DriverDocument::where('driver_id', $driver->id)
            ->where('status', 'aprobado')
            ->get()
            ->filter(fn (DriverDocument $d) => $d->vigente())
            ->pluck('document_type_id')
            ->all();

        return DocumentType::vigentes()
            ->where('required', true)
            ->whereNotIn('id', $vigentes ?: [0])
            ->get()
            ->all();
    }

    /**
     * ¿Hay que impedirle trabajar por los papeles?
     *
     * El plazo de gracia es lo que evita que al encender esto se caiga el servicio: los
     * conductores que ya venían trabajando tienen fecha límite y hasta ese día siguen
     * operando aunque les falten documentos.
     */
    public static function blocking(Driver $driver): bool
    {
        if (! self::required() || $driver->is_demo || $driver->is_reviewer) {
            return false;
        }

        if ($driver->onboarding_status !== 'aprobado') {
            return true;
        }

        if (! self::missing($driver)) {
            return false;
        }

        // le faltan papeles pero todavía está dentro del plazo
        return ! ($driver->docs_due_at && $driver->docs_due_at->isFuture());
    }

    /** Texto para la app del conductor, que explica exactamente qué le falta. */
    public static function blockMessage(Driver $driver): ?string
    {
        if (! self::blocking($driver)) {
            return null;
        }

        if ($driver->onboarding_status === 'registrado') {
            return 'Envía tus documentos para que la central revise tu solicitud.';
        }
        if ($driver->onboarding_status === 'enviado') {
            return 'Tus documentos están en revisión. Te avisamos apenas la central los apruebe.';
        }

        $faltan = array_map(fn (DocumentType $t) => $t->name, self::missing($driver));
        if ($driver->onboarding_status === 'observado') {
            return 'La central observó tu solicitud. Corrige lo que te indicaron para poder conectarte.';
        }

        return $faltan
            ? 'No puedes conectarte: falta '.self::lista($faltan).'.'
            : 'No puedes conectarte: revisa tus documentos con la central.';
    }

    /** Los que vencen dentro de N días (para los avisos). */
    public static function expiringSoon(Driver $driver, int $dias = 30): array
    {
        return DriverDocument::where('driver_id', $driver->id)
            ->where('status', 'aprobado')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($dias))
            ->with('type')
            ->get()
            ->all();
    }

    /**
     * Recalcula el estado del alta a partir de los documentos.
     * Se llama al aprobar: si ya no falta nada, el conductor queda aprobado solo.
     */
    public static function refreshOnboarding(Driver $driver): void
    {
        if (! self::missing($driver) && $driver->onboarding_status !== 'aprobado') {
            $driver->update([
                'onboarding_status' => 'aprobado',
                'approved_at'       => now(),
            ]);
        }
    }

    /** Estado de cada tipo, tal como lo necesita la lista de la app. */
    public static function checklist(Driver $driver): array
    {
        $suyos = DriverDocument::where('driver_id', $driver->id)
            ->whereIn('status', ['pendiente', 'aprobado', 'rechazado'])
            ->latest('id')
            ->get()
            ->groupBy('document_type_id');

        $out = [];
        foreach (DocumentType::vigentes()->get() as $t) {
            $doc = optional($suyos->get($t->id))->first();
            $out[] = [
                'key'        => $t->key,
                'name'       => $t->name,
                'help'       => $t->help,
                'required'   => $t->required,
                'expiry'     => $t->has_expiry,
                'number'     => $t->needs_number,
                'status'     => $doc?->vencido() ? 'vencido' : ($doc->status ?? 'falta'),
                'reason'     => $doc?->reject_reason,
                'expires_at' => $doc?->expires_at?->toDateString(),
            ];
        }

        return $out;
    }

    private static function lista(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }
        $ultimo = array_pop($items);

        return implode(', ', $items).' y '.$ultimo;
    }
}
