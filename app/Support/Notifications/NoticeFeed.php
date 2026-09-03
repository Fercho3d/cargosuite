<?php

namespace App\Support\Notifications;

use App\Actions\Operations\ContinuityAlerts;
use App\Mail\ContinuityAlertMail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lo que está esperando a que alguien lo atienda, junto y con un clic para ir.
 *
 * Es la alternativa al correo: los mismos avisos que el sistema mandaría por
 * cron, pero dentro de la aplicación y sin llenarle el buzón a nadie.
 *
 * **Solo mira embarques vivos** —abiertos y con carga en los últimos meses— y esa
 * es la diferencia entre una bandeja útil y un montón de ruido: sobre la base de
 * hoy hay 1,394 tareas vencidas, casi todas de bookings de hace años que nadie
 * cerró. Acotado a lo vivo quedan tres.
 *
 * Se guarda en caché unos minutos porque la campana se pinta en todas las
 * páginas. Lo cacheado son arreglos planos, por lo mismo que en el panel.
 */
class NoticeFeed
{
    /** Cuánto dura la foto de la bandeja. */
    private const MINUTOS = 5;

    /** Ventana de lo que se considera un embarque vivo. */
    private const DIAS = 180;

    /** Cuántos avisos se listan por grupo. */
    private const POR_GRUPO = 25;

    /** @return Collection<int, Notice> */
    public function all(): Collection
    {
        $crudos = Cache::remember(
            'avisos:'.now()->format('Y-m-d-H').':'.intdiv((int) now()->format('i'), self::MINUTOS),
            now()->addMinutes(self::MINUTOS),
            fn () => $this->compute(),
        );

        return collect($crudos)->map(fn (array $a) => new Notice(
            grupo: $a['grupo'],
            titulo: $a['titulo'],
            detalle: $a['detalle'],
            ruta: $a['ruta'],
            nivel: $a['nivel'],
            fecha: $a['fecha'],
        ));
    }

    public function count(): int
    {
        return $this->all()->count();
    }

    /** @return array<int, array<string, mixed>> */
    private function compute(): array
    {
        return collect()
            ->merge($this->tareasVencidas())
            ->merge($this->sinTimbrar())
            ->merge($this->sinFacturar())
            ->merge($this->sinContenedores())
            ->merge($this->solicitudesAbiertas())
            ->map(fn (Notice $aviso) => $aviso->toArray())
            ->all();
    }

    /** Embarques abiertos con carga reciente: lo que de verdad está en curso. */
    private function bookingsVivos(): Collection
    {
        return collect(
            DB::table('booking')
                ->where('mode', 10)
                ->where('locked', 0)
                ->where('loading_EDT', '>=', now()->subDays(self::DIAS)->toDateString())
                ->get(['booking_id', 'booking_number', 'loading_EDT'])
        );
    }

    /**
     * Tareas de la lista de verificación cuya fecha ya pasó.
     *
     * @return Collection<int, Notice>
     */
    private function tareasVencidas(): Collection
    {
        $vivos = $this->bookingsVivos()->keyBy(fn ($b) => trim((string) $b->booking_number));

        return collect(app(ContinuityAlerts::class)->handle(ContinuityAlertMail::VENCIDO, enviar: false))
            ->filter(fn (array $aviso) => $vivos->has(trim($aviso['booking'])))
            ->take(self::POR_GRUPO)
            ->map(fn (array $aviso) => new Notice(
                grupo: 'tareas',
                titulo: $aviso['label'],
                detalle: trim($aviso['booking']),
                ruta: route('operations.continuity'),
                nivel: Notice::URGENTE,
                fecha: $aviso['date'],
            ))
            ->values();
    }

    /** @return Collection<int, Notice> */
    private function sinTimbrar(): Collection
    {
        // Donde no se factura al SAT no hay nada que timbrar, y este aviso
        // saldría siempre y para todas las facturas.
        if (! config('timbrado.habilitado')) {
            return collect();
        }

        return collect(
            DB::table('transaction as t')
                ->join('booking as b', 'b.booking_id', '=', 't.booking')
                ->leftJoin('client as c', 'c.client_id', '=', 't.customer')
                ->where('t.tran_type', 0)
                ->where('b.mode', 10)
                ->where('t.cancelled', 0)
                ->where('t.invoice_type', 1)
                ->whereNull('t.seal')
                ->orderByDesc('t.tran_date')
                ->limit(self::POR_GRUPO)
                ->get(['t.transc_id', 't.tran_number', 't.tran_date', 'c.fullName as cliente'])
        )->map(fn ($fila) => new Notice(
            grupo: 'timbrado',
            titulo: (string) ($fila->tran_number ?: '#'.$fila->transc_id),
            detalle: (string) ($fila->cliente ?? ''),
            ruta: route('transactions.show', $fila->transc_id),
            nivel: Notice::URGENTE,
            fecha: $fila->tran_date,
        ));
    }

    /** Embarques que ya cargaron y todavía no tienen factura: dinero sin cobrar. */
    private function sinFacturar(): Collection
    {
        return collect(
            DB::table('booking as b')
                ->leftJoin('client as c', 'c.client_id', '=', 'b.client')
                ->where('b.mode', 10)
                ->where('b.locked', 0)
                ->whereBetween('b.loading_EDT', [now()->subDays(self::DIAS)->toDateString(), now()->toDateString()])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('transaction as t')
                    ->whereColumn('t.booking', 'b.booking_id')
                    ->where('t.tran_type', 0)
                    ->where('t.cancelled', 0))
                ->orderByDesc('b.loading_EDT')
                ->limit(self::POR_GRUPO)
                ->get(['b.booking_id', 'b.booking_number', 'b.loading_EDT', 'c.fullName as cliente'])
        )->map(fn ($fila) => new Notice(
            grupo: 'sin_facturar',
            titulo: trim((string) $fila->booking_number) ?: '#'.$fila->booking_id,
            detalle: (string) ($fila->cliente ?? ''),
            ruta: route('operations.bookings.show', $fila->booking_id),
            nivel: Notice::URGENTE,
            fecha: $fila->loading_EDT,
        ));
    }

    /** Un embarque sin contenedores no puede facturarse ni generar costos. */
    private function sinContenedores(): Collection
    {
        return collect(
            DB::table('booking as b')
                ->leftJoin('client as c', 'c.client_id', '=', 'b.client')
                ->where('b.mode', 10)
                ->where('b.locked', 0)
                ->where('b.loading_EDT', '>=', now()->subDays(self::DIAS)->toDateString())
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('containers as cn')
                    ->whereColumn('cn.booking', 'b.booking_id'))
                ->orderByDesc('b.loading_EDT')
                ->limit(self::POR_GRUPO)
                ->get(['b.booking_id', 'b.booking_number', 'b.loading_EDT', 'c.fullName as cliente'])
        )->map(fn ($fila) => new Notice(
            grupo: 'sin_carga',
            titulo: trim((string) $fila->booking_number) ?: '#'.$fila->booking_id,
            detalle: (string) ($fila->cliente ?? ''),
            ruta: route('operations.bookings.show', $fila->booking_id),
            fecha: $fila->loading_EDT,
        ));
    }

    /** @return Collection<int, Notice> */
    private function solicitudesAbiertas(): Collection
    {
        return collect(
            DB::table('payment_request as pr')
                ->leftJoin('provider as p', 'p.provider_id', '=', 'pr.provider_id')
                ->where('pr.opened', 1)
                ->where('pr.paid', 0)
                ->orderByDesc('pr.date')
                ->limit(self::POR_GRUPO)
                ->get(['pr.request_id', 'pr.number', 'pr.date', 'p.fullName as proveedor'])
        )->map(fn ($fila) => new Notice(
            grupo: 'pagos',
            titulo: (string) ($fila->number ?: '#'.$fila->request_id),
            detalle: (string) ($fila->proveedor ?? ''),
            ruta: route('payments.requests'),
            fecha: $fila->date,
        ));
    }
}
