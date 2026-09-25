<?php

namespace Database\Seeders;

use App\Support\Cotizaciones\Cotizaciones;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cinco cotizaciones de ejemplo sobre las rutas de la demo, una por estado:
 * aceptada, enviada, vencida, borrador (a un prospecto) y rechazada.
 *
 *   php artisan db:seed --class=CotizacionesDemoSeeder
 *
 * No borra nada: se planta si ya hay cotizaciones o si no hay rutas.
 */
class CotizacionesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('cotizacion')->exists() || DB::table('ruta')->doesntExist()) {
            return;
        }

        $rutas = DB::table('ruta')->orderBy('ruta_id')->limit(5)->pluck('ruta_id');
        $clientes = DB::table('client')->orderBy('client_id')->limit(4)->get(['client_id', 'email']);
        $hoy = Carbon::today();

        $casos = [
            ['estado' => 'aceptada', 'cliente' => 0, 'hace' => 9, 'vigencia' => 6, 'ajuste' => 1.0],
            ['estado' => 'enviada', 'cliente' => 1, 'hace' => 2, 'vigencia' => 13, 'ajuste' => 1.0],
            ['estado' => 'enviada', 'cliente' => 2, 'hace' => 20, 'vigencia' => -5, 'ajuste' => 1.0],
            ['estado' => 'borrador', 'cliente' => null, 'hace' => 0, 'vigencia' => 15, 'ajuste' => 1.0],
            // Se le hizo descuento y aun así no la tomó.
            ['estado' => 'rechazada', 'cliente' => 3, 'hace' => 12, 'vigencia' => 3, 'ajuste' => 0.95],
        ];

        foreach ($casos as $i => $caso) {
            $ruta = $rutas[$i % $rutas->count()];
            $cliente = $caso['cliente'] === null ? null : ($clientes[$caso['cliente']] ?? null);
            $creada = $hoy->copy()->subDays($caso['hace']);

            $id = DB::table('cotizacion')->insertGetId([
                'numero' => Cotizaciones::siguienteNumero(),
                'client_id' => $cliente?->client_id,
                'prospecto' => $cliente === null ? 'Lácteos del Norte, S.A. de C.V.' : null,
                'correo' => $cliente?->email ?: 'compras@lacteos-demo.test',
                'ruta_id' => $ruta,
                'fecha_carga' => $creada->copy()->addDays(5)->toDateString(),
                'vigencia' => $hoy->copy()->addDays($caso['vigencia'])->toDateString(),
                'tipo_unidad' => 'Caja seca 53 pies',
                'estado' => $caso['estado'],
                'condiciones' => 'Precios en pesos mexicanos. El IVA y la retención se desglosan aparte. Estadías, maniobras extraordinarias y custodia se cotizan por separado. Sujeto a disponibilidad de unidad.',
                'enviada_en' => $caso['estado'] === 'borrador' ? null : $creada->copy()->setTime(11, 0),
                'respondida_en' => in_array($caso['estado'], ['aceptada', 'rechazada'], true) ? $creada->copy()->addDays(2)->setTime(16, 0) : null,
                'created_by' => 1,
                'created_at' => $creada->copy()->setTime(10, 0),
            ], 'cotizacion_id');

            foreach (Cotizaciones::renglonesDeRuta((int) $ruta, $creada->toDateString(), $cliente?->client_id) as $renglon) {
                $renglon['precio'] = round($renglon['precio'] * $caso['ajuste'], -1);
                DB::table('cotizacion_renglon')->insert($renglon + ['cotizacion_id' => $id]);
            }
        }
    }
}
