<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Una corrección de saldo dejaría al conductor debajo de cero.
 *
 * Se lanza DENTRO del bloqueo de la fila, con el saldo real de ese momento, para
 * que el mensaje que ve la central diga el número que de verdad hay en la cuenta
 * y no el que tenía en pantalla hace un minuto. Al lanzarse antes de escribir
 * nada, la transacción se deshace sin haber tocado el historial.
 */
class SaldoNegativo extends RuntimeException
{
    public function __construct(public float $saldoActual, public float $objetivo)
    {
        parent::__construct('La corrección dejaría el saldo en ' . $objetivo . ' (actual: ' . $saldoActual . ').');
    }
}
