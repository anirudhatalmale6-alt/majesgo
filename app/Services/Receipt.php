<?php

namespace App\Services;

use App\Models\Driver;
use Illuminate\Http\UploadedFile;

/**
 * Comprobante (voucher) que el conductor adjunta al pedir una recarga de saldo.
 *
 * Casi siempre es una captura de pantalla de Yape/Plin o la foto del voucher del banco.
 * Se guarda más grande que la foto del vehículo a propósito: la central tiene que poder
 * leer el número de operación y el monto para validar la recarga.
 */
class Receipt
{
    private const MAX_SIDE = 1600;
    private const QUALITY  = 88;

    public const DIR   = 'comprobantes';
    public const RULES = ImageStore::RULES;

    /** Guarda el comprobante y devuelve la ruta relativa ("comprobantes/xxx.jpg"). */
    public static function store(UploadedFile $file, Driver $driver): string
    {
        return ImageStore::put($file, self::DIR, $driver->code ?: 'drv', self::MAX_SIDE, self::MAX_SIDE, self::QUALITY);
    }

    public static function url(?string $path): ?string
    {
        return ImageStore::url($path);
    }

    public static function messages(string $field = 'receipt'): array
    {
        return ImageStore::messages($field, 'el comprobante');
    }
}
