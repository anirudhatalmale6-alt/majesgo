<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guardado de imágenes subidas desde el celular (fotos de vehículo, comprobantes de pago).
 *
 * Toda foto de cámara pesa 5-10 MB y llega girada si el celular estaba en vertical.
 * Aquí se corrige la orientación, se reescala y se recomprime a JPG una sola vez,
 * para no repetir esa lógica en cada servicio que guarde imágenes.
 */
class ImageStore
{
    public const DISK = 'public';

    /** Reglas de validación comunes (12 MB: una foto de celular moderna entra sin problema). */
    public const RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'];

    /** Mensajes en español: el panel y la app del conductor son 100% en español. */
    public static function messages(string $field, string $label): array
    {
        return [
            "{$field}.uploaded" => "No se pudo subir {$label}: pesa más de lo que admite el servidor. Usa una imagen más liviana.",
            "{$field}.image"    => "El archivo de {$label} debe ser una imagen.",
            "{$field}.mimes"    => "{$label} debe ser JPG, PNG o WEBP.",
            "{$field}.max"      => "{$label} es muy pesada (máx. 12 MB).",
        ];
    }

    /**
     * Procesa y guarda la imagen. Devuelve la ruta relativa ("dir/xxx.jpg").
     * $prefix identifica al dueño (código del conductor) para reconocer el archivo en el disco.
     */
    public static function put(UploadedFile $file, string $dir, string $prefix, int $maxW, int $maxH, int $quality = 82): string
    {
        $img = self::read($file);

        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min($maxW / $w, $maxH / $h, 1);
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
        imagejpeg($img, null, $quality);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        $path = trim($dir, '/') . '/' . Str::lower($prefix ?: 'img') . '-' . Str::random(10) . '.jpg';
        Storage::disk(self::DISK)->put($path, $jpeg);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * URL pública de una ruta guardada, o null si no hay imagen.
     * Se devuelve relativa a propósito: así funciona igual en local, en el dominio
     * de producción y dentro de la app nativa, sin depender de APP_URL.
     */
    public static function url(?string $path): ?string
    {
        return $path ? '/storage/' . ltrim($path, '/') : null;
    }

    /**
     * Decodifica el archivo subido respetando la orientación EXIF: las fotos
     * tomadas con el celular en vertical llegan giradas si no se corrige.
     */
    private static function read(UploadedFile $file)
    {
        $raw = file_get_contents($file->getRealPath());
        $img = @imagecreatefromstring($raw);
        if (! $img) {
            throw new \RuntimeException('No se pudo leer la imagen.');
        }

        if (function_exists('exif_read_data') && in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            $exif = @exif_read_data($file->getRealPath());
            $angle = match ((int) ($exif['Orientation'] ?? 1)) {
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
