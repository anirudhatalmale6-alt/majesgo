<?php

namespace App\Services;

use App\Models\Driver;
use Illuminate\Http\UploadedFile;

/**
 * Guarda la foto del vehículo del conductor.
 *
 * Las fotos llegan desde la cámara del celular (pueden pesar 5-10 MB), así que
 * siempre se reescalan y se recomprimen a JPG antes de guardarlas: el pasajero
 * la ve en una tarjeta pequeña y no tiene sentido gastarle megas de su plan.
 * El procesamiento en sí vive en ImageStore, compartido con los comprobantes.
 */
class VehiclePhoto
{
    /** Suficiente para verla a pantalla completa en un celular. */
    private const MAX_W = 1280;
    private const MAX_H = 960;
    private const QUALITY = 82;

    public const DISK = ImageStore::DISK;
    public const DIR  = 'vehiculos';

    /** Extensiones/mimes aceptados (reglas de validación reutilizables). */
    public const RULES = ImageStore::RULES;

    /**
     * Procesa y guarda la foto, borra la anterior y devuelve la ruta relativa
     * ("vehiculos/xxx.jpg") lista para asignar a $driver->vehicle_photo.
     */
    public static function store(UploadedFile $file, Driver $driver): string
    {
        $path = ImageStore::put($file, self::DIR, $driver->code ?: 'drv', self::MAX_W, self::MAX_H, self::QUALITY);

        ImageStore::delete($driver->vehicle_photo); // sin huérfanos en el disco

        return $path;
    }

    /** Borra la foto actual del conductor (si tiene) y limpia el campo. */
    public static function clear(Driver $driver): void
    {
        ImageStore::delete($driver->vehicle_photo);
        $driver->update(['vehicle_photo' => null]);
    }

    /** URL pública de una ruta guardada, o null si no hay foto. */
    public static function url(?string $path): ?string
    {
        return ImageStore::url($path);
    }
}
