<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverPhoto;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;

/**
 * Moderación de las fotos del conductor (rostro y vehículo).
 *
 * REGLA CENTRAL: ninguna foto llega al pasajero sin que la central la apruebe.
 * Por eso subir una foto NUNCA toca la foto que se está mostrando: la nueva queda
 * "pendiente" y la aprobada sigue vigente hasta que alguien revise la nueva. Si no
 * fuera así, un conductor podría hacerse aprobar una foto correcta y reemplazarla
 * después por cualquier cosa.
 *
 * Foto vigente (la que ve el pasajero):
 *   perfil   -> drivers.photo_path
 *   vehiculo -> drivers.vehicle_photo
 */
class DriverPhotos
{
    /** Columna de la foto vigente según el tipo. */
    private const LIVE_COLUMN = [
        'perfil'   => 'photo_path',
        'vehiculo' => 'vehicle_photo',
    ];

    private const DIRS = [
        'perfil'   => 'perfiles',
        'vehiculo' => 'vehiculos',
    ];

    /** El rostro se recorta cuadrado en la tarjeta; el auto se ve apaisado. */
    private const SIZES = [
        'perfil'   => [900, 900, 85],
        'vehiculo' => [1280, 960, 82],
    ];

    public const RULES = ImageStore::RULES;

    public static function label(string $type): string
    {
        return ['perfil' => 'tu foto de perfil', 'vehiculo' => 'la foto de tu vehículo'][$type] ?? 'la foto';
    }

    public static function liveColumn(string $type): string
    {
        return self::LIVE_COLUMN[$type];
    }

    /**
     * El conductor envía una foto nueva: queda pendiente de aprobación.
     * La foto vigente no se toca.
     */
    public static function submit(Driver $driver, string $type, UploadedFile $file): DriverPhoto
    {
        [$w, $h, $q] = self::SIZES[$type];

        // si ya tenía una pendiente sin revisar, esta la reemplaza (no acumular cola por conductor)
        foreach ($driver->photos()->pending()->where('type', $type)->get() as $old) {
            ImageStore::delete($old->path);
            $old->delete();
        }

        $path = ImageStore::put($file, self::DIRS[$type], $driver->code ?: 'drv', $w, $h, $q);

        return $driver->photos()->create([
            'type'   => $type,
            'path'   => $path,
            'status' => 'pendiente',
        ]);
    }

    /** La central aprueba: recién aquí la foto pasa a ser la que ve el pasajero. */
    public static function approve(DriverPhoto $photo, int $userId): void
    {
        $driver = $photo->driver;
        $column = self::LIVE_COLUMN[$photo->type];
        $previous = $driver->{$column};

        $photo->update([
            'status'        => 'aprobado',
            'reject_reason' => null,
            'reviewed_by'   => $userId,
            'reviewed_at'   => now(),
        ]);

        $driver->update([$column => $photo->path]);

        // la foto anterior ya no la ve nadie: se borra el archivo y su registro
        if ($previous && $previous !== $photo->path) {
            ImageStore::delete($previous);
            $driver->photos()
                ->where('type', $photo->type)
                ->where('status', 'aprobado')
                ->where('id', '!=', $photo->id)
                ->delete();
        }
    }

    /**
     * La central rechaza con un motivo. El archivo se borra en el acto: es material que
     * la central decidió no publicar y no tiene por qué quedar accesible en el servidor.
     * El motivo sí se conserva, porque es lo que el conductor necesita leer para corregir.
     */
    public static function reject(DriverPhoto $photo, int $userId, string $reason): void
    {
        ImageStore::delete($photo->path);

        $photo->update([
            'status'        => 'rechazado',
            'reject_reason' => $reason,
            'reviewed_by'   => $userId,
            'reviewed_at'   => now(),
        ]);
    }

    /** Estado de un tipo de foto, tal como lo necesita la app del conductor. */
    public static function state(Driver $driver, string $type): array
    {
        $live    = $driver->{self::LIVE_COLUMN[$type]};
        $pending = $driver->photos()->pending()->where('type', $type)->latest('id')->first();
        $lastRej = $driver->photos()->where('type', $type)->where('status', 'rechazado')->latest('id')->first();

        // el rechazo solo se le muestra si es lo último que pasó con esa foto
        $showReject = $lastRej && (! $pending || $lastRej->id > $pending->id);

        $status = $pending ? 'pendiente' : ($live ? 'aprobada' : ($showReject ? 'rechazada' : 'ninguna'));

        return [
            'type'        => $type,
            'status'      => $status,
            'url'         => ImageStore::url($live),          // la vigente (aprobada)
            'pending_url' => $pending ? $pending->url() : null,
            'reason'      => $showReject ? $lastRej->reject_reason : null,
            'reviewed_at' => $showReject ? $lastRej->reviewed_at?->format('d/m/Y H:i') : null,
        ];
    }

    /** Ambos estados juntos (lo que consume la pantalla "Mi cuenta"). */
    public static function states(Driver $driver): array
    {
        return [
            'perfil'   => self::state($driver, 'perfil'),
            'vehiculo' => self::state($driver, 'vehiculo'),
        ];
    }

    /** ¿Está activo el requisito de tener las dos fotos aprobadas para conectarse? */
    public static function required(): bool
    {
        return (string) Setting::get('require_photos', '1') === '1';
    }

    /** Tipos que le faltan al conductor para poder conectarse. */
    public static function missing(Driver $driver): array
    {
        // el conductor demo es una simulación interna: no hay a quién pedirle fotos
        if (! self::required() || $driver->is_demo) {
            return [];
        }

        return array_values(array_filter(
            DriverPhoto::TYPES,
            fn ($t) => ! $driver->{self::LIVE_COLUMN[$t]}
        ));
    }

    /** Mensaje para el conductor cuando no puede conectarse por las fotos. */
    public static function blockMessage(Driver $driver): ?string
    {
        $missing = self::missing($driver);
        if (! $missing) {
            return null;
        }

        $states  = self::states($driver);
        $pending = array_filter($missing, fn ($t) => $states[$t]['status'] === 'pendiente');

        if (count($pending) === count($missing)) {
            return 'Tus fotos están en revisión. Podrás conectarte apenas la central las apruebe.';
        }

        $names = array_map(fn ($t) => $t === 'perfil' ? 'tu foto de perfil' : 'la foto de tu vehículo', $missing);

        return 'Para conectarte necesitas ' . implode(' y ', $names)
            . ', aprobada' . (count($names) > 1 ? 's' : '') . ' por la central. Súbela'
            . (count($names) > 1 ? 's' : '') . ' desde «Mi cuenta».';
    }
}
