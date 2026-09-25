<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada precio de la ruta lleva su tipo de cargo: es lo que dice qué IVA y qué
 * retención lleva y con qué clave del SAT se timbra cuando la factura sale de
 * la ruta.
 *
 * A los precios que ya existían se les asigna el tipo de cargo que se llame
 * igual que su concepto; los que no empaten se quedan sin él y la ficha lo avisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarifa_ruta', function (Blueprint $tabla) {
            $tabla->integer('charge_type_id')->nullable();
        });

        if (Schema::hasTable('charge_type')) {
            foreach (DB::table('charge_type')->get(['charge_type_id', 'charge_type_name']) as $tipo) {
                DB::table('tarifa_ruta')->whereNull('charge_type_id')
                    ->where('concepto', $tipo->charge_type_name)
                    ->update(['charge_type_id' => $tipo->charge_type_id]);
            }

            // El flete subcontratado se paga como flete.
            $flete = DB::table('charge_type')->where('charge_type_name', 'Flete')->value('charge_type_id');

            if ($flete !== null) {
                DB::table('tarifa_ruta')->whereNull('charge_type_id')->where('concepto', 'Flete subcontratado')
                    ->update(['charge_type_id' => $flete]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tarifa_ruta', fn (Blueprint $tabla) => $tabla->dropColumn('charge_type_id'));
    }
};
