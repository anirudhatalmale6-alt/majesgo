<?php

namespace App\Http\Controllers;

use App\Models\FcmToken;
use Illuminate\Http\Request;

/**
 * Soltar los avisos de un celular que ya no tiene la sesión abierta.
 *
 * EL PROBLEMA QUE ARREGLA. El token de avisos se guardaba al abrir la app y no lo borraba
 * nadie: ni al cerrar sesión. El celular seguía recibiendo los avisos de esa cuenta para
 * siempre. Joel lo vio el 2026-10-02: le llegaban "encontré conductor" y "tu conductor ya
 * llegó" en un celular donde no estaba con su cuenta abierta y con la app cerrada.
 *
 * No es sólo molesto. En el aviso van el nombre del conductor y la dirección del recojo. Un
 * celular prestado, vendido o devuelto se queda escuchando los viajes del dueño anterior.
 *
 * ⚠ VA SIN SESIÓN A PROPÓSITO. Justamente hace falta cuando la sesión YA no está: si
 * exigiera estar dentro, el celular que hay que limpiar nunca podría limpiarse. Por eso la
 * única llave es el propio token, que es un secreto del aparato y no se adivina. Lo peor
 * que puede hacer alguien con un token ajeno es dejar a ese aparato sin avisos, nunca leer
 * nada ni recibirlos en su lugar.
 */
class PushReleaseController extends Controller
{
    public function release(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        // delete() devuelve cuántas filas borró: sirve para comprobarlo de verdad, no de palabra
        $borrados = FcmToken::where('token', $data['token'])->delete();

        return response()->json(['ok' => true, 'released' => $borrados]);
    }
}
