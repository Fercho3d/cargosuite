<?php

namespace Database\Seeders;

use App\Support\Payroll\Payroll;
use Database\Seeders\Perfiles\PerfilDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La plantilla y una nómina abierta del periodo en curso.
 *
 * Va aparte de `DemoSeeder` para poder correrlo **solo** sobre una instalación
 * que ya está en uso, cuando el módulo de nómina llega después que los datos:
 *
 *   php artisan db:seed --class=NominaDemoSeeder
 *
 * Por eso no borra nada y se planta si ya hay empleados: en una base con datos
 * de verdad, un sembrador que limpia es un accidente esperando a pasar.
 *
 * La nómina se siembra **abierta**, no pagada: una demostración con todo cerrado
 * no deja tocar nada, y lo primero que quiere hacer quien la mira es agregarle
 * un bono a alguien.
 */
class NominaDemoSeeder extends Seeder
{
    private ?PerfilDemo $perfilPedido = null;

    /** El perfil que se está sembrando, cuando viene de `DemoSeeder`. */
    public function conPerfil(PerfilDemo $perfil): static
    {
        $this->perfilPedido = $perfil;

        return $this;
    }

    public function run(): void
    {
        if (DB::table('empleado')->exists()) {
            $this->command?->warn('Ya hay empleados dados de alta: no se siembra la plantilla.');

            return;
        }

        $perfil = $this->perfilPedido ?? PerfilDemo::elegido();
        $empleados = [];

        // Cada operador, además, está en nómina y ligado a su ficha de flota:
        // es lo que hace que sus liquidaciones de viaje entren a su recibo.
        foreach (DB::table('operador')->orderBy('operador_id')->get() as $i => $operador) {
            $empleados[] = [
                'nombre' => $operador->nombre,
                'puesto' => 'Operador',
                'departamento' => 'Operación',
                'operador_id' => $operador->operador_id,
                'salario_diario' => 520 + ($i % 4) * 40,
                'ingreso' => $operador->ingreso,
                'clabe' => '0121800012345'.str_pad((string) (600 + $i), 5, '0', STR_PAD_LEFT),
            ];
        }

        foreach ($perfil->plantilla() as $i => $persona) {
            $empleados[] = [
                'nombre' => $persona['nombre'],
                'puesto' => $persona['puesto'],
                'departamento' => 'Administración',
                'operador_id' => null,
                'salario_diario' => $persona['salario'],
                'ingreso' => Carbon::now()->subYears(3)->addMonths($i * 4)->toDateString(),
                'clabe' => '0121800012345'.str_pad((string) (700 + $i), 5, '0', STR_PAD_LEFT),
            ];
        }

        foreach ($empleados as $i => $empleado) {
            DB::table('empleado')->insert($empleado + [
                'numero' => 'E-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'banco' => 'BBVA',
                'activo' => 1,
            ]);
        }

        $desde = Carbon::now()->startOfMonth();
        $hasta = $desde->copy()->addDays(14);

        $nomina = DB::table('nomina')->insertGetId([
            'numero' => Payroll::siguienteNumero(),
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'periodicidad' => 'quincenal',
            'estado' => 'abierta',
            'created_at' => Carbon::now()->toDateTimeString(),
        ], 'nomina_id');

        foreach (Payroll::empleadosActivos() as $empleado) {
            foreach (Payroll::propuesta($empleado, $desde->toDateString(), $hasta->toDateString()) as $renglon) {
                DB::table('nomina_renglon')->insert($renglon + [
                    'nomina_id' => $nomina,
                    'empleado_id' => $empleado->empleado_id,
                ]);
            }
        }

        $this->command?->info(count($empleados).' empleados y una nómina abierta del periodo en curso.');
    }
}
