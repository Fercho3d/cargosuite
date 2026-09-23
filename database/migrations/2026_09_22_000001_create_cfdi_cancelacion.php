<?php

use App\Support\Cfdi\CancelResult;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de las cancelaciones de CFDI.
 *
 * Hacía falta porque cancelar ante el SAT **no termina cuando el PAC contesta**:
 * salvo las que se cancelan sin aceptación, la solicitud queda esperando a que
 * el receptor la autorice o a que se le venza el plazo de 72 horas, y mientras
 * tanto la factura sigue vigente. El esquema heredado no tiene dónde guardar esa
 * espera: `transaction.cancelled` es un sí o un no.
 *
 * Tabla **nueva y aditiva**: el sistema viejo (Yii2) no la conoce y sigue viendo
 * las columnas de siempre sin cambio alguno. Sin llaves foráneas, como el resto:
 * en producción varias tablas son MyISAM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfdi_cancelacion', function (Blueprint $tabla) {
            $tabla->increments('id');

            // Una solicitud viva por transacción: volver a pedirla actualiza la fila.
            $tabla->unsignedInteger('transc_id')->unique();
            $tabla->string('uuid', 40);

            $tabla->string('motivo', 2);                      // 01–04 del catálogo del SAT
            $tabla->string('sustituye', 40)->nullable();      // folio que la sustituye (motivo 01)

            // Lo que contestó el PAC (ver `App\Support\Cfdi\CancelResult`).
            $tabla->string('estado', 12)->default(CancelResult::SOLICITADA);
            $tabla->string('codigo', 20)->nullable();
            $tabla->string('mensaje', 255)->nullable();

            // Lo que contesta el SAT, que es la verdad fiscal.
            $tabla->string('sat_estado', 20)->nullable();     // Vigente | Cancelado | No Encontrado
            $tabla->string('sat_estatus', 40)->nullable();    // En proceso | Plazo vencido | …

            $tabla->unsignedInteger('solicitado_por')->nullable();
            $tabla->dateTime('solicitado_at');
            $tabla->dateTime('verificado_at')->nullable();

            // El comando diario recorre las que siguen esperando respuesta.
            $tabla->index('estado');
        });

        $this->sembrarLoQueYaPaso();
    }

    public function down(): void
    {
        Schema::dropIfExists('cfdi_cancelacion');
    }

    /**
     * Las facturas que el sistema dio por canceladas antes de que esto existiera.
     *
     * Se marcaron en cuanto la llamada al PAC no tronó, sin mirar la respuesta,
     * así que algunas siguen vigentes ante el SAT esperando al receptor. Quedan
     * como solicitudes para que `cfdi:revisar-cancelaciones` le pregunte al SAT
     * y las corrija solas.
     *
     * **No se toca su `cancelled`**: cambiarlo a ciegas sería el mismo error al
     * revés. Lo cambia la consulta al SAT, con respuesta en la mano.
     */
    private function sembrarLoQueYaPaso(): void
    {
        // `transaction` es del esquema heredado, no de estas migraciones: en una
        // instalación limpia todavía no está y no hay nada que rellenar.
        if (! Schema::hasTable('transaction')) {
            return;
        }

        $ahora = now();

        DB::table('transaction')
            ->where('cancelled', 1)
            ->whereNotNull('seal')
            ->where('seal', '<>', '')
            ->orderBy('transc_id')
            ->select('transc_id', 'seal', 'cancel_reason_id', 'new_seal', 'modified_at', 'modified_by')
            ->chunk(500, function ($facturas) use ($ahora) {
                DB::table('cfdi_cancelacion')->insert($facturas->map(fn ($factura) => [
                    'transc_id' => $factura->transc_id,
                    'uuid' => $factura->seal,
                    'motivo' => $factura->cancel_reason_id ?: '02',
                    'sustituye' => $factura->new_seal ?: null,
                    'estado' => CancelResult::SOLICITADA,
                    'mensaje' => 'Cancelada antes de que el sistema leyera la respuesta del PAC: queda por confirmar con el SAT.',
                    'solicitado_por' => $factura->modified_by,
                    'solicitado_at' => $factura->modified_at ?: $ahora,
                ])->all());
            });
    }
};
