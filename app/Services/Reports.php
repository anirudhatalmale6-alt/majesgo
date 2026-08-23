<?php

namespace App\Services;

use App\Models\Ride;
use App\Models\UserReport;
use Illuminate\Validation\ValidationException;

/**
 * Alta de denuncias entre usuarios. Las dos apps escriben acá para que la validación
 * del motivo y la regla de "una por viaje" vivan en un solo lugar.
 */
class Reports
{
    /**
     * Registra la denuncia. Si el mismo denunciante ya había denunciado ese viaje,
     * actualiza la que tenía en vez de crear otra: el usuario no distingue entre
     * "ya mandé una" y "la estoy corrigiendo", y duplicar solo ensucia la bandeja.
     */
    public static function submit(
        Ride $ride,
        string $reporterType,
        int $reporterId,
        string $reportedType,
        int $reportedId,
        string $reason,
        ?string $details
    ): UserReport {
        $reasons = UserReport::reasonsFor($reportedType);

        if (! isset($reasons[$reason])) {
            throw ValidationException::withMessages(['reason' => 'Elige un motivo de la lista.']);
        }

        $details = trim((string) $details) ?: null;

        // "Otro motivo" sin explicación no le sirve a nadie en la central.
        if ($reason === 'otro' && $details === null) {
            throw ValidationException::withMessages(['details' => 'Cuéntanos brevemente qué pasó.']);
        }

        return UserReport::updateOrCreate(
            [
                'ride_id'       => $ride->id,
                'reporter_type' => $reporterType,
                'reporter_id'   => $reporterId,
            ],
            [
                'reported_type' => $reportedType,
                'reported_id'   => $reportedId,
                'reason'        => $reason,
                'details'       => $details,
                // Si la central ya la había revisado y el usuario la corrige, vuelve a la cola.
                'status'        => 'pendiente',
                'admin_note'    => null,
                'reviewed_at'   => null,
                'reviewed_by'   => null,
            ]
        );
    }
}
