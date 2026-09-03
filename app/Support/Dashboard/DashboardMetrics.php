<?php

namespace App\Support\Dashboard;

use App\Queries\ProfitByBooking;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras del panel.
 *
 * Todo está acotado al mes en curso a propósito. Los saldos abiertos de toda la
 * historia suman decenas de millones —hay facturas de hace años que nunca se
 * marcaron como pagadas— y un número así en la portada no dice nada de cómo va
 * el negocio hoy.
 *
 * **Sobre la caché**: el módulo de transacciones no usa la caché de la aplicación
 * y hay dos caídas en producción que lo explican (ver `CatalogCacheTest`). Aquí
 * sí se usa, con dos condiciones que evitan justo esos dos accidentes: lo que se
 * guarda es un arreglo de **números y textos sueltos** —ningún modelo de
 * Eloquent, que es lo que volvía como `__PHP_Incomplete_Class`— y lo escribe solo
 * el proceso web al pintar el panel, nunca un comando de consola, que es lo que
 * dejó archivos con otro dueño. Cinco minutos bastan: son cifras de un panel, no
 * un estado de cuenta.
 *
 * **Sobre los pendientes**: los dos contadores de la tarjeta «Pendientes» llevan
 * a `/transacciones` y `/pagos/solicitudes`, que están detrás de
 * `EnsureUserIsAdmin`. Por eso solo se calculan para administradores —y por eso
 * la llave de la caché distingue quién la escribió: si no, el primero en entrar
 * le dejaría su foto al siguiente.
 */
class DashboardMetrics
{
    /** Lo que dura la foto del panel. */
    private const MINUTOS = 5;

    /** Meses que se dibujan en la gráfica. */
    private const MESES = 6;

    /**
     * @param  bool  $esAdmin  Si se incluyen los pendientes, que son solo para administradores.
     * @return array<string, mixed>
     */
    public function all(bool $esAdmin): array
    {
        $mes = now()->startOfMonth();

        return Cache::remember(
            'panel:'.($esAdmin ? 'admin' : 'basico').':'.$mes->format('Y-m').':'.now()->format('Y-m-d-H').':'.intdiv((int) now()->format('i'), self::MINUTOS),
            now()->addMinutes(self::MINUTOS),
            fn () => $this->compute($mes, $esAdmin),
        );
    }

    /** @return array<string, mixed> */
    private function compute(Carbon $mes, bool $esAdmin): array
    {
        $rango = $mes->format('d/m/Y').' - '.$mes->copy()->endOfMonth()->format('d/m/Y');
        $desde = $mes->toDateString();
        $hasta = $mes->copy()->endOfMonth()->toDateString();

        $facturado = TransactionQuery::make(TransactionFilters::make([
            'type_in' => [0], 'dates' => $rango,
        ]))->totals(['total_amount']);

        $utilidad = (new ProfitByBooking(TransactionFilters::make(['dates' => $rango])))->summary()['totals'];

        $panel = [
            // Sin formatear: lo que se guarda no debe depender del idioma de
            // quien haya pintado el panel primero.
            'mes' => $mes->toDateString(),
            'facturado' => (float) ($facturado['total_amount'] ?? 0),
            'utilidad' => (float) ($utilidad['profit_doc'] ?? 0),
            'embarques' => $this->bookingsDelMes($desde, $hasta),
            'contenedores' => $this->contenedoresDelMes($desde, $hasta),
            'serie' => $this->serie(),
            'proximos' => $this->proximos(),
        ];

        if ($esAdmin) {
            $panel['pendientes'] = [
                'timbrar' => config('timbrado.habilitado') ? $this->sinTimbrar() : 0,
                'solicitudes' => (int) DB::table('payment_request')->where('opened', 1)->where('paid', 0)->count(),
            ];
        }

        return $panel;
    }

    private function bookingsDelMes(string $desde, string $hasta): int
    {
        return (int) DB::table('booking')
            ->where('mode', 10)
            ->whereBetween('loading_EDT', [$desde, $hasta])
            ->count();
    }

    private function contenedoresDelMes(string $desde, string $hasta): int
    {
        return (int) DB::table('containers as c')
            ->join('booking as b', 'b.booking_id', '=', 'c.booking')
            ->where('b.mode', 10)
            ->whereBetween('b.loading_EDT', [$desde, $hasta])
            ->sum('c.quantity');
    }

    /** Facturas emitidas por mes, para la gráfica. @return array<string, int> */
    private function serie(): array
    {
        $desde = now()->startOfMonth()->subMonths(self::MESES - 1);

        // Se agrupa en PHP y no con `DATE_FORMAT`, que solo existe en MySQL:
        // son unas cuantas decenas de filas por mes y así la consulta corre
        // igual en la base de pruebas.
        $conteo = DB::table('transaction as t')
            ->join('booking as b', 'b.booking_id', '=', 't.booking')
            ->where('t.tran_type', 0)
            ->where('t.cancelled', 0)
            ->where('b.mode', 10)
            ->where('t.tran_date', '>=', $desde->toDateString())
            ->pluck('t.tran_date')
            ->countBy(fn ($fecha) => substr((string) $fecha, 0, 7));

        $serie = [];

        for ($i = 0; $i < self::MESES; $i++) {
            $punto = $desde->copy()->addMonths($i);
            $serie[$punto->format('Y-m')] = (int) ($conteo[$punto->format('Y-m')] ?? 0);
        }

        return $serie;
    }

    /** Facturas de un booking real que ya deberían tener CFDI y no lo tienen. */
    private function sinTimbrar(): int
    {
        return (int) DB::table('transaction as t')
            ->join('booking as b', 'b.booking_id', '=', 't.booking')
            ->where('t.tran_type', 0)
            ->where('b.mode', 10)
            ->where('t.cancelled', 0)
            ->where('t.invoice_type', 1)
            ->whereNull('t.seal')
            ->count();
    }

    /**
     * Lo que viene: embarques que cargan o arriban en los próximos días.
     *
     * @return array<int, array<string, mixed>>
     */
    private function proximos(): array
    {
        $hoy = now()->toDateString();
        $limite = now()->addDays(10)->toDateString();

        return DB::table('booking as b')
            ->leftJoin('client as c', 'c.client_id', '=', 'b.client')
            ->where('b.mode', 10)
            ->where('b.locked', 0)
            ->where(fn ($q) => $q->whereBetween('b.loading_EDT', [$hoy, $limite])
                ->orWhereBetween('b.dicharge_ETA', [$hoy, $limite]))
            ->limit(40)
            ->get(['b.booking_id', 'b.booking_number', 'b.loading_EDT', 'b.dicharge_ETA', 'c.fullName as cliente'])
            // El orden se arma aquí y no en la consulta: `LEAST` es de MySQL y
            // la base de pruebas no lo tiene. Son unas pocas filas.
            ->sortBy(fn ($fila) => min(
                array_filter([$fila->loading_EDT, $fila->dicharge_ETA]) ?: ['9999-12-31'],
            ))
            ->take(6)
            ->map(fn ($fila) => (array) $fila)
            ->values()
            ->all();
    }
}
