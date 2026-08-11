<?php

namespace App\Services;

use App\Models\Driver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda la foto del vehículo del conductor.
 *
 * Las fotos llegan desde la cámara del celular (pueden pesar 5-10 MB), así que
 * siempre se reescalan y se recomprimen a JPG antes de guardarlas: el pasajero
 * la ve en una tarjeta pequeña y no tiene sentido gastarle megas de su plan.
 */
class VehiclePhoto
{
    /** Ancho máximo guardado. Suficiente para verla a pantalla completa en un celular. */
    private const MAX_W = 1280;
    private const MAX_H = 960;
    private const QUALITY = 82;

    public const DISK = 'public';
    public const DIR  = 'vehiculos';

    /** Extensiones/mimes aceptados (reglas de validación reutilizables). */
    public const RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'];

    /**
     * Procesa y guarda la foto, borra la anterior y devuelve la ruta relativa
     * ("vehiculos/xxx.jpg") lista para asignar a $driver->vehicle_photo.
     */
    public static function store(UploadedFile $file, Driver $driver): string
    {
        $img = self::readImage($file);

        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(self::MAX_W / $w, self::MAX_H / $h, 1);
        if ($scale < 1) {
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            // fondo blanco: los PNG/WebP con transparencia quedarían negros al pasarlos a JPG
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }

        ob_start();
        imagejpeg($img, null, self::QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        $path = self::DIR . '/' . Str::lower($driver->code ?: 'drv') . '-' . Str::random(10) . '.jpg';
        Storage::disk(self::DISK)->put($path, $jpeg);

        self::deleteFile($driver->vehicle_photo);

        return $path;
    }

    /** Borra la foto actual del conductor (si tiene) y limpia el campo. */
    public static function clear(Driver $driver): void
    {
        self::deleteFile($driver->vehicle_photo);
        $driver->update(['vehicle_photo' => null]);
    }

    /**
     * URL pública de una ruta guardada, o null si no hay foto.
     * Se devuelve relativa a propósito: así funciona igual en local, en el dominio
     * de producción y dentro de la app nativa, sin depender de APP_URL.
     */
    public static function url(?string $path): ?string
    {
        return $path ? '/storage/' . ltrim($path, '/') : null;
    }

    private static function deleteFile(?string $path): void
    {
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Decodifica el archivo subido respetando la orientación EXIF: las fotos
     * tomadas con el celular en vertical llegan giradas si no se corrige.
     */
    private static function readImage(UploadedFile $file)
    {
        $raw = file_get_contents($file->getRealPath());
        $img = @imagecreatefromstring($raw);
        if (! $img) {
            throw new \RuntimeException('No se pudo leer la imagen.');
        }

        if (function_exists('exif_read_data') && in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            $exif = @exif_read_data($file->getRealPath());
            $orientation = $exif['Orientation'] ?? 1;
            $angle = match ((int) $orientation) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($angle !== 0) {
                $rotated = imagerotate($img, $angle, 0);
                if ($rotated) {
                    imagedestroy($img);
                    $img = $rotated;
                }
            }
        }

        return $img;
    }
}
