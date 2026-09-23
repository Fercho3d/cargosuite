<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Impuestos y cuotas de la nómina: ISR, IMSS, INFONAVIT y retenciones de
 * honorarios.
 *
 * Las tablas y los porcentajes cambian cada año, así que viven en la base y se
 * editan desde Catálogos, no en el código. Se precargan los de 2026 como punto
 * de partida: **el contador del cliente tiene que verificarlos**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleado', function (Blueprint $tabla) {
            // ninguno | sueldos | asimilados | honorarios. «ninguno» es lo que
            // había: la nómina reúne lo que se paga y no calcula impuestos.
            $tabla->string('regimen', 12)->default('ninguno');
            // semanal | catorcenal | quincenal | mensual. Vacío = entra a todas.
            $tabla->string('periodicidad', 12)->nullable();
            // Descuento fijo por periodo que manda el aviso de retención.
            $tabla->decimal('infonavit_descuento', 12, 2)->nullable();
        });

        Schema::table('nomina_renglon', function (Blueprint $tabla) {
            // Lo calculó el sistema y se rehace solo; lo capturado a mano no.
            $tabla->boolean('automatico')->default(false);
        });

        Schema::create('tabla_fiscal', function (Blueprint $tabla) {
            $tabla->increments('tabla_fiscal_id');
            $tabla->unsignedSmallInteger('anio');
            $tabla->string('tabla', 30); // isr_mensual | cesantia_patronal
            $tabla->decimal('limite_inferior', 14, 2);
            $tabla->decimal('cuota_fija', 14, 2)->default(0);
            $tabla->decimal('porcentaje', 8, 4);
            $tabla->index(['anio', 'tabla']);
        });

        Schema::create('parametro_fiscal', function (Blueprint $tabla) {
            $tabla->increments('parametro_fiscal_id');
            $tabla->unsignedSmallInteger('anio');
            $tabla->string('clave', 40);
            $tabla->decimal('valor', 14, 4);
            $tabla->string('descripcion', 160)->nullable();
            $tabla->unique(['anio', 'clave']);
        });

        DB::table('tabla_fiscal')->insert(array_merge(
            // Tarifa mensual del art. 96 LISR (Anexo 8 RMF).
            array_map(fn ($f) => ['anio' => 2026, 'tabla' => 'isr_mensual', 'limite_inferior' => $f[0], 'cuota_fija' => $f[1], 'porcentaje' => $f[2]], [
                [0.01, 0, 1.92], [746.05, 14.32, 6.40], [6332.06, 371.83, 10.88],
                [11128.02, 893.63, 16.00], [12935.83, 1182.88, 17.92], [15487.72, 1640.18, 21.36],
                [31236.50, 5004.12, 23.52], [49233.01, 9236.89, 30.00], [93993.91, 22665.17, 32.00],
                [125325.21, 32691.18, 34.00], [375975.62, 117912.32, 35.00],
            ]),
            // Cesantía y vejez patronal (reforma LSS 2020, escalonada a 2030).
            // Límite en veces la UMA; el renglón 0 es para quien gana el mínimo.
            array_map(fn ($f) => ['anio' => 2026, 'tabla' => 'cesantia_patronal', 'limite_inferior' => $f[0], 'cuota_fija' => 0, 'porcentaje' => $f[1]], [
                [0, 3.150], [1.01, 3.676], [1.51, 4.851], [2.01, 5.556],
                [2.51, 6.026], [3.01, 6.361], [3.51, 6.613], [4.01, 7.513],
            ]),
        ));

        DB::table('parametro_fiscal')->insert(array_map(fn ($f) => ['anio' => 2026, 'clave' => $f[0], 'valor' => $f[1], 'descripcion' => $f[2]], [
            ['uma_diaria', 117.31, 'UMA diaria'],
            ['salario_minimo', 315.04, 'Salario mínimo diario general'],
            ['subsidio_porcentaje', 13.8, 'Subsidio al empleo: % de la UMA mensual (verificar decreto del año)'],
            ['subsidio_limite', 10171.00, 'Subsidio al empleo: ingreso mensual máximo (verificar decreto del año)'],
            ['aguinaldo_dias', 15, 'Días de aguinaldo (factor de integración)'],
            ['prima_vacacional', 25, 'Prima vacacional % (factor de integración)'],
            ['tope_sbc_umas', 25, 'Tope del salario base de cotización, en UMAs'],
            ['imss_cuota_fija', 20.40, 'IMSS enf. y mat. cuota fija patronal % de la UMA'],
            ['imss_excedente_patronal', 1.10, 'IMSS excedente de 3 UMAs, patronal %'],
            ['imss_excedente_obrero', 0.40, 'IMSS excedente de 3 UMAs, obrero %'],
            ['imss_dinero_patronal', 0.70, 'IMSS prestaciones en dinero, patronal %'],
            ['imss_dinero_obrero', 0.25, 'IMSS prestaciones en dinero, obrero %'],
            ['imss_pensionados_patronal', 1.05, 'IMSS gastos médicos pensionados, patronal %'],
            ['imss_pensionados_obrero', 0.375, 'IMSS gastos médicos pensionados, obrero %'],
            ['imss_invalidez_patronal', 1.75, 'IMSS invalidez y vida, patronal %'],
            ['imss_invalidez_obrero', 0.625, 'IMSS invalidez y vida, obrero %'],
            ['imss_riesgo_trabajo', 0.54355, 'Prima de riesgo de trabajo de la empresa %'],
            ['imss_guarderias', 1.00, 'IMSS guarderías y prestaciones sociales, patronal %'],
            ['retiro', 2.00, 'SAR retiro, patronal %'],
            ['cesantia_obrero', 1.125, 'Cesantía y vejez, obrero %'],
            ['infonavit', 5.00, 'INFONAVIT aportación patronal %'],
            ['honorarios_iva', 16.00, 'Honorarios: IVA trasladado %'],
            ['honorarios_retencion_isr', 10.00, 'Honorarios: retención de ISR %'],
            ['honorarios_retencion_iva', 10.6667, 'Honorarios: retención de IVA % (2/3 del IVA)'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('parametro_fiscal');
        Schema::dropIfExists('tabla_fiscal');
        Schema::table('nomina_renglon', fn (Blueprint $tabla) => $tabla->dropColumn('automatico'));
        Schema::table('empleado', fn (Blueprint $tabla) => $tabla->dropColumn(['regimen', 'periodicidad', 'infonavit_descuento']));
    }
};
