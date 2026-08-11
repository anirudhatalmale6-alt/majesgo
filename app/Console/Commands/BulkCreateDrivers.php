<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Alta masiva de conductores para la prueba cerrada de Play Store.
 *
 * El CSV debe traer cabecera con estas columnas (el orden no importa; las que sobran se ignoran):
 *   nombre,celular,marca,modelo,placa,color,dni,email
 * Sólo "nombre" y "celular" son obligatorias.
 *
 *   php artisan drivers:bulk conductores.csv --saldo=25 --dry-run
 *   php artisan drivers:bulk conductores.csv --saldo=25
 *
 * Imprime al final la tabla de credenciales (código, celular, clave) para repartir.
 */
class BulkCreateDrivers extends Command
{
    protected $signature = 'drivers:bulk
                            {file : ruta del CSV con los conductores}
                            {--saldo=25 : saldo inicial a acreditar a cada conductor}
                            {--dry-run : sólo valida y muestra lo que haría, sin escribir nada}';

    protected $description = 'Crea conductores en lote desde un CSV y les acredita un saldo inicial';

    /** Sinónimos aceptados en la cabecera del CSV → campo interno. */
    private const HEADERS = [
        'nombre' => 'full_name', 'nombres' => 'full_name', 'full_name' => 'full_name',
        'celular' => 'phone', 'telefono' => 'phone', 'teléfono' => 'phone', 'phone' => 'phone',
        'marca' => 'vehicle_make', 'modelo' => 'vehicle_model',
        'placa' => 'vehicle_plate', 'color' => 'vehicle_color',
        'anio' => 'vehicle_year', 'año' => 'vehicle_year',
        'dni' => 'dni', 'licencia' => 'license_number',
        'email' => 'email', 'correo' => 'email',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_readable($path)) {
            $this->error("No puedo leer el archivo: {$path}");
            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        if ($rows === null) {
            return self::FAILURE;
        }
        if (! $rows) {
            $this->error('El CSV no tiene filas de datos.');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $saldo = round((float) $this->option('saldo'), 2);
        $adminId = User::query()->orderBy('id')->value('id');

        // Validación completa ANTES de escribir nada: o entran todos, o no entra ninguno.
        $seen = [];
        $ready = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // +1 por la cabecera, +1 porque el humano cuenta desde 1
            $name = trim((string) ($row['full_name'] ?? ''));
            $phone = preg_replace('/\D/', '', (string) ($row['phone'] ?? ''));
            if (strlen($phone) > 9) {
                $phone = substr($phone, -9);
            }

            if ($name === '') {
                $errors[] = "Fila {$line}: falta el nombre.";
                continue;
            }
            if (strlen($phone) !== 9) {
                $errors[] = "Fila {$line} ({$name}): el celular debe tener 9 dígitos, llegó '{$row['phone']}'.";
                continue;
            }
            if (isset($seen[$phone])) {
                $errors[] = "Fila {$line} ({$name}): celular {$phone} repetido dentro del CSV (fila {$seen[$phone]}).";
                continue;
            }
            if (Driver::where('phone', $phone)->exists()) {
                $errors[] = "Fila {$line} ({$name}): el celular {$phone} ya está registrado como conductor.";
                continue;
            }

            $seen[$phone] = $line;
            $ready[] = [
                'full_name'      => $name,
                'phone'          => $phone,
                'dni'            => $this->clean($row['dni'] ?? null, 20),
                'email'          => $this->clean($row['email'] ?? null, 120),
                'license_number' => $this->clean($row['license_number'] ?? null, 40),
                'vehicle_make'   => $this->clean($row['vehicle_make'] ?? null, 40),
                'vehicle_model'  => $this->clean($row['vehicle_model'] ?? null, 40),
                'vehicle_plate'  => $this->clean($row['vehicle_plate'] ?? null, 15),
                'vehicle_color'  => $this->clean($row['vehicle_color'] ?? null, 30),
                'vehicle_year'   => $this->clean($row['vehicle_year'] ?? null, 5),
            ];
        }

        if ($errors) {
            $this->error('El CSV tiene ' . count($errors) . ' problema(s). No se creó ningún conductor:');
            foreach ($errors as $e) {
                $this->line('  · ' . $e);
            }
            return self::FAILURE;
        }

        $this->info(count($ready) . ' conductor(es) listos para crear, saldo inicial S/ ' . number_format($saldo, 2) . '.');

        if ($dry) {
            $this->table(
                ['Nombre', 'Celular', 'Vehículo', 'Placa'],
                array_map(fn ($d) => [
                    $d['full_name'],
                    $d['phone'],
                    trim(($d['vehicle_make'] ?? '') . ' ' . ($d['vehicle_model'] ?? '')) ?: '—',
                    $d['vehicle_plate'] ?: '—',
                ], $ready)
            );
            $this->comment('Simulación (--dry-run): no se escribió nada.');
            return self::SUCCESS;
        }

        $created = [];
        foreach ($ready as $d) {
            $plain = $this->password();
            $driver = Driver::create($d + [
                'code'           => $this->nextCode(),
                'password'       => Hash::make($plain),
                'account_status' => 'activo',
                'status'         => 'desconectado',
                'created_by'     => $adminId,
            ]);

            if ($saldo > 0) {
                $driver->applyMovement('ajuste', $saldo, 'Saldo inicial (prueba cerrada)', 'manual', null, $adminId);
            }

            $created[] = [$driver->code, $driver->full_name, $driver->phone, $plain, 'S/ ' . number_format((float) $driver->saldo, 2)];
        }

        $this->newLine();
        $this->info('Conductores creados. Credenciales para repartir:');
        $this->table(['Código', 'Nombre', 'Usuario (celular)', 'Clave', 'Saldo'], $created);
        $this->comment('La clave es de un solo uso para arrancar: cada conductor puede cambiarla luego desde el panel de administración.');

        return self::SUCCESS;
    }

    /** @return array<int,array<string,string>>|null */
    private function readCsv(string $path): ?array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            $this->error("No pude abrir {$path}.");
            return null;
        }

        $header = fgetcsv($fh);
        if (! $header) {
            fclose($fh);
            $this->error('El CSV está vacío.');
            return null;
        }

        // BOM de Excel
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $map = [];
        foreach ($header as $idx => $col) {
            $key = Str::lower(trim((string) $col));
            if (isset(self::HEADERS[$key])) {
                $map[$idx] = self::HEADERS[$key];
            }
        }

        if (! in_array('full_name', $map, true) || ! in_array('phone', $map, true)) {
            fclose($fh);
            $this->error('La cabecera del CSV debe incluir al menos las columnas "nombre" y "celular".');
            $this->line('  Ejemplo: nombre,celular,marca,modelo,placa,color');
            return null;
        }

        $rows = [];
        while (($line = fgetcsv($fh)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // fila en blanco
            }
            $row = [];
            foreach ($map as $idx => $field) {
                $row[$field] = isset($line[$idx]) ? trim((string) $line[$idx]) : null;
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    private function clean(?string $v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : Str::limit($v, $max, '');
    }

    /** Clave corta y fácil de dictar por teléfono (sin caracteres ambiguos). */
    private function password(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private function nextCode(): string
    {
        $max = Driver::pluck('code')
            ->filter(fn ($c) => preg_match('/^MG-\d+$/', (string) $c))
            ->map(fn ($c) => (int) Str::afterLast($c, '-'))
            ->max() ?? 0;

        return 'MG-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }
}
