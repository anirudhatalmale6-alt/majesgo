<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de vida del alta del conductor, separado de account_status.
 *
 * Son dos ejes distintos y mezclarlos se paga caro: account_status es disciplina
 * (activo / suspendido / bloqueado, lo decide la central sobre alguien que YA entró) y
 * onboarding_status es el ingreso (¿terminó de presentar sus papeles?).
 *
 * ⚠ Los conductores que ya están trabajando entraron sin papeles porque los cargó la
 * central. Nacen 'aprobado' — si nacieran 'registrado' se quedarían todos sin poder
 * conectarse en el mismo despliegue, y el servicio se caería de un día para el otro.
 * A ellos se les pone un plazo (docs_due_at) para regularizar, con aviso en la app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // registrado | enviado | aprobado | observado
            $table->string('onboarding_status')->default('registrado')->after('account_status');
            // hasta cuándo puede trabajar sin tener los papeles al día (period de gracia)
            $table->timestamp('docs_due_at')->nullable()->after('onboarding_status');
            $table->timestamp('approved_at')->nullable()->after('docs_due_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                  ->constrained('users')->nullOnDelete();
            // ¿se dio de alta desde la app o lo creó la central?
            $table->boolean('self_registered')->default(false)->after('approved_by');

            $table->index('onboarding_status');
        });

        // Todos los que ya existen quedan aprobados: estaban operando antes de que
        // existiera este requisito.
        DB::table('drivers')->update(['onboarding_status' => 'aprobado', 'approved_at' => now()]);
    }

    public function down(): void
    {
        // ⚠ dropConstrainedForeignId() revienta en sqlite («no such column "approved_by"»),
        // así que el down() quedaba roto justo donde uno confía en él. Se sueltan las
        // columnas y listo: la clave foránea se va con la columna.
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_status', 'docs_due_at', 'approved_at', 'approved_by', 'self_registered',
            ]);
        });
    }
};
