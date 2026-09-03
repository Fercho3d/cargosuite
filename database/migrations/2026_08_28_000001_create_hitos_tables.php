<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los hitos del expediente dejan de ser columnas.
 *
 * `booking_continuity` guarda una columna por hito (recolección, corte
 * documental, zarpe…), así que cambiar la lista exigía una migración de esquema
 * y el módulo solo servía para carga marítima. Un taller tiene otros hitos:
 * recepción, diagnóstico, autorización, entrega.
 *
 * A partir de aquí los hitos son un catálogo (`hito`) y sus fechas, filas
 * (`hito_por_expediente`). Se rellenan con lo que ya había.
 *
 * ⚠️ **Las columnas viejas NO se borran y se siguen escribiendo** (ver
 * `App\Support\Milestones\BookingMilestones`): la instalación original convive
 * con el sistema Yii2, que las lee, y de ellas salen todavía el PDF de
 * confirmación —comparado carácter por carácter en las pruebas de paridad—, dos
 * columnas del listado y los avisos de tareas atrasadas.
 *
 * Sin llaves foráneas a propósito: en producción varias tablas son MyISAM y no
 * las admiten (mismo criterio que `user_preferences`).
 */
return new class extends Migration
{
    /**
     * Los 14 hitos del sistema de origen, en el orden en que se enseñan.
     * `columna` es la de `booking_continuity` que le corresponde.
     *
     * @var list<array{clave: string, etiqueta: string, columna: string}>
     */
    private const HEREDADOS = [
        ['clave' => 'pickup_date', 'etiqueta' => 'Recolección', 'columna' => 'pickup_date'],
        ['clave' => 'doc_cut_of', 'etiqueta' => 'Corte documental', 'columna' => 'doc_cut_of'],
        ['clave' => 'SI_date', 'etiqueta' => 'Instrucciones', 'columna' => 'SI_date'],
        ['clave' => 'draf_client', 'etiqueta' => 'Draft cliente', 'columna' => 'draf_client'],
        ['clave' => 'corrected_draft', 'etiqueta' => 'Draft corregido', 'columna' => 'corrected_draft'],
        ['clave' => 'vgm', 'etiqueta' => 'VGM', 'columna' => 'vgm'],
        ['clave' => 'gated_IN', 'etiqueta' => 'Gate in', 'columna' => 'gated_IN'],
        ['clave' => 'cleared', 'etiqueta' => 'Despacho', 'columna' => 'cleared'],
        ['clave' => 'departure', 'etiqueta' => 'Zarpe', 'columna' => 'departure'],
        ['clave' => 'bl_payment', 'etiqueta' => 'Pago del BL', 'columna' => 'bl_payment'],
        ['clave' => 'swb', 'etiqueta' => 'SWB', 'columna' => 'swb'],
        ['clave' => 'delivered', 'etiqueta' => 'Entregado', 'columna' => 'delivered'],
        ['clave' => 'gated_out', 'etiqueta' => 'Gate out', 'columna' => 'gated_out'],
        ['clave' => 'insurance', 'etiqueta' => 'Seguro', 'columna' => 'insurance'],
    ];

    public function up(): void
    {
        Schema::create('hito', function (Blueprint $tabla) {
            $tabla->increments('hito_id');
            $tabla->string('clave', 40)->unique();
            $tabla->string('etiqueta', 60);
            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);

            // Columna de `booking_continuity` que este hito refleja. Nula = hito
            // nuevo, que solo vive en la tabla nueva.
            $tabla->string('columna_legado', 40)->nullable();

            $tabla->index(['activo', 'orden']);
        });

        Schema::create('hito_por_expediente', function (Blueprint $tabla) {
            $tabla->increments('id');
            $tabla->unsignedInteger('booking');
            $tabla->unsignedInteger('hito_id');
            $tabla->dateTime('fecha')->nullable();
            $tabla->unsignedInteger('modified_by')->nullable();
            $tabla->dateTime('modified_at')->nullable();

            $tabla->unique(['booking', 'hito_id']);
            $tabla->index('hito_id');
        });

        $this->siembraCatalogo();
        $this->rellenaDesdeLasColumnas();
    }

    public function down(): void
    {
        Schema::dropIfExists('hito_por_expediente');
        Schema::dropIfExists('hito');
    }

    private function siembraCatalogo(): void
    {
        $orden = 0;

        foreach (self::HEREDADOS as $hito) {
            $orden += 10;

            DB::table('hito')->insert([
                'clave' => $hito['clave'],
                'etiqueta' => $hito['etiqueta'],
                'orden' => $orden,
                'activo' => 1,
                'columna_legado' => $hito['columna'],
            ]);
        }
    }

    /**
     * Pasa a filas lo que ya estaba en columnas.
     *
     * Se hace por lotes y solo con las fechas capturadas: la tabla tiene una
     * fila por expediente y la mayoría de los hitos están vacíos, así que
     * insertar los nulos multiplicaría por catorce sin guardar nada.
     */
    private function rellenaDesdeLasColumnas(): void
    {
        if (! Schema::hasTable('booking_continuity')) {
            return;
        }

        $catalogo = DB::table('hito')->pluck('hito_id', 'columna_legado');
        $columnas = array_keys($catalogo->all());

        DB::table('booking_continuity')
            ->orderBy('cont_id')
            ->select(array_merge(['booking', 'modified_by', 'modified_at'], $columnas))
            ->chunk(500, function ($filas) use ($catalogo, $columnas) {
                $porInsertar = [];

                foreach ($filas as $fila) {
                    foreach ($columnas as $columna) {
                        if (empty($fila->{$columna})) {
                            continue;
                        }

                        $porInsertar[] = [
                            'booking' => $fila->booking,
                            'hito_id' => $catalogo[$columna],
                            'fecha' => $fila->{$columna},
                            'modified_by' => $fila->modified_by,
                            'modified_at' => $fila->modified_at,
                        ];
                    }
                }

                if ($porInsertar !== []) {
                    DB::table('hito_por_expediente')->insertOrIgnore($porInsertar);
                }
            });
    }
};
