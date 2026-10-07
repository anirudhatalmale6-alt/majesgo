<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de documentos que la central le exige a un conductor.
 *
 * Va en una tabla y no en el código a propósito: la municipalidad cambia los requisitos
 * y esos cambios no pueden depender de que alguien toque el programa y despliegue. Con
 * esto la central agrega "certificado de salud" un martes y al día siguiente todos los
 * conductores lo ven en su lista de pendientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // identificador estable, no cambia al renombrar
            $table->string('name');                   // lo que lee el conductor
            $table->string('help')->nullable();       // aclaración corta debajo del nombre
            $table->boolean('required')->default(true);
            $table->boolean('has_expiry')->default(false);   // SOAT, licencia, antecedentes…
            $table->boolean('needs_number')->default(false); // nº de licencia, nº de póliza
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });

        // Los que pidió la municipalidad de Majes. Son datos, no reglas del programa:
        // se editan desde el panel.
        //
        // ⚠ El rostro y la foto del vehículo NO van acá aunque la municipalidad también
        // los exija: ya los gobierna DriverPhotos, con su propia bandeja y su propio
        // candado. Duplicarlos daría dos sistemas pidiendo lo mismo y dos respuestas
        // distintas a «¿este conductor está en regla?».
        $now = now();
        $filas = [
            ['dni',            'DNI o carnet de extranjería', 'Las dos caras, legibles',                 1, 0, 1],
            ['licencia',       'Licencia de conducir A-II',   'Vigente, categoría A-II o superior',      1, 1, 1],
            ['antecedentes',   'Certificado de antecedentes', 'Penales y policiales',                    1, 1, 0],
            ['soat',           'SOAT del vehículo',           'Póliza vigente',                          1, 1, 1],
            ['revision',       'Revisión técnica',            'Certificado vigente',                     1, 1, 0],
            ['capacitacion',   'Capacitación MTC',            'Constancia del curso',                    1, 1, 0],
            ['circulacion',    'Tarjeta de circulación MPC',  'Emitida por la Municipalidad de Majes',   1, 1, 1],
        ];

        foreach ($filas as $i => [$key, $name, $help, $req, $exp, $num]) {
            DB::table('document_types')->insert([
                'key'          => $key,
                'name'         => $name,
                'help'         => $help,
                'required'     => $req,
                'has_expiry'   => $exp,
                'needs_number' => $num,
                'sort_order'   => ($i + 1) * 10,
                'active'       => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
