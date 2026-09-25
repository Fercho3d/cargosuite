<?php

namespace Database\Seeders;

use Database\Seeders\Perfiles\PerfilDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rutas con tarifa, costos y subcontratistas, y el diésel de las últimas
 * semanas.
 *
 * Como `NominaDemoSeeder`, va aparte para poder correrlo solo sobre una
 * instalación que ya está en uso:
 *
 *   php artisan db:seed --class=RutasDemoSeeder
 *
 * No borra nada y se planta si ya hay rutas. Los números son los mismos con los
 * que la demostración factura y costea los viajes (ver `PerfilCamiones`): 28 $/km
 * con mínimo de 6 500, casetas a 2.90 $/km y subcontrato a ~26 $/km.
 */
class RutasDemoSeeder extends Seeder
{
    private ?PerfilDemo $perfilPedido = null;

    public function conPerfil(PerfilDemo $perfil): static
    {
        $this->perfilPedido = $perfil;

        return $this;
    }

    public function run(): void
    {
        if (DB::table('ruta')->exists()) {
            $this->command?->warn('Ya hay rutas dadas de alta: no se siembran.');

            return;
        }

        $perfil = $this->perfilPedido ?? PerfilDemo::elegido();
        $hoy = Carbon::today();
        $subcontratistas = DB::table('provider')->where('type_id', 2)->orderBy('provider_id')->limit(2)->pluck('provider_id');
        $casetas = DB::table('provider')->where('fullName', 'like', 'Peajes%')->value('provider_id');
        $cliente = DB::table('client')->orderBy('client_id')->value('client_id');
        // El tipo de cargo del concepto (IVA, retención, clave SAT); el subcontrato se paga como flete.
        $tipos = DB::table('charge_type')->pluck('charge_type_id', 'charge_type_name');
        $tipoDe = fn (string $concepto) => $tipos[$concepto === 'Flete subcontratado' ? 'Flete' : $concepto] ?? null;

        foreach (collect($perfil->rutas())->unique(fn ($r) => $r['origen'].'-'.$r['destino'])->values() as $n => $r) {
            $km = (float) $r['km'];
            $ruta = DB::table('ruta')->insertGetId([
                'origen_id' => $r['origen'],
                'destino_id' => $r['destino'],
                'km' => $km,
                // 65 km/h de promedio, paradas incluidas.
                'horas' => round($km / 65, 1),
                'rendimiento' => 2.2,
                'activo' => true,
            ], 'ruta_id');

            $tarifas = [
                // El flete subió hace dos meses: el de antes queda como historial.
                ['venta', 'Flete', null, null, round(max(6200, $km * 26.5), -1), $hoy->copy()->subMonths(8)],
                ['venta', 'Flete', null, null, round(max(6500, $km * 28), -1), $hoy->copy()->subMonths(2)],
                ['venta', 'Maniobras', null, null, 2500, $hoy->copy()->subMonths(8)],
                ['costo', 'Casetas', null, $casetas, round($km * 2.9, -1), $hoy->copy()->subMonths(8)],
                // Viáticos del operador: 450 por día de viaje.
                ['costo', 'Viáticos', null, null, 450 * max(1, (int) ceil($km / 650)), $hoy->copy()->subMonths(8)],
            ];

            // Un cliente con tarifa especial en las primeras rutas.
            if ($cliente !== null && $n < 3) {
                $tarifas[] = ['venta', 'Flete', $cliente, null, round(max(6500, $km * 28) * 0.95, -1), $hoy->copy()->subMonths(2)];
            }

            foreach ($subcontratistas as $i => $proveedor) {
                $tarifas[] = ['subcontrato', 'Flete subcontratado', null, $proveedor, round($km * (25.5 + $i * 1.5), -1), $hoy->copy()->subMonths(3)];
            }

            foreach ($tarifas as [$tipo, $concepto, $client, $provider, $precio, $desde]) {
                DB::table('tarifa_ruta')->insert([
                    'ruta_id' => $ruta, 'tipo' => $tipo, 'concepto' => $concepto,
                    'client_id' => $client, 'provider_id' => $provider, 'charge_type_id' => $tipoDe($concepto),
                    'precio' => $precio, 'vigente_desde' => $desde->toDateString(),
                    'created_by' => 1, 'created_at' => $desde->copy()->setTime(9, 0),
                ]);
            }
        }

        // El diésel de las últimas seis semanas: se mueve centavos casi a diario.
        if (! DB::table('precio_diesel')->exists()) {
            $precio = 25.10;

            for ($dia = 42; $dia >= 0; $dia--) {
                $precio = round($precio + [0.03, -0.02, 0.01, 0.04, -0.01, 0.02, 0][$dia % 7], 2);
                DB::table('precio_diesel')->insert([
                    'fecha' => $hoy->copy()->subDays($dia)->toDateString(),
                    'precio' => $precio,
                    'created_by' => 1,
                    'created_at' => $hoy->copy()->subDays($dia)->setTime(8, 0),
                ]);
            }
        }
    }
}
