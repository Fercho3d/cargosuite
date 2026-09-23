<?php

namespace App\Queries;

use App\Support\Milestones\MilestoneCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listado de bookings (embarques).
 *
 * Traducción de `BookingSearch` de Yii2. Además de los datos del embarque trae
 * el **avance de su lista de verificación**: la tabla `check_list` guarda 27
 * casillas, cada una con la fecha en que se marcó, y el porcentaje es cuántas
 * tienen fecha.
 *
 * El original resuelve ese porcentaje con una subconsulta de dos niveles que
 * primero convierte cada fecha en 1 o 0 y luego las suma; aquí es una sola
 * pasada con `COUNT` sobre expresiones, que da el mismo número.
 */
class BookingQuery
{
    /** Las 27 casillas de la lista de verificación, en el orden del original. */
    private const CHECKS = [
        'booking_number', 'pickup_date', 'modality', 'doc_cut_of', 'SI_date', 'cleared',
        'departure', 'bl_payment', 'swb', 'vessel', 'number', 'client', 'loading_port',
        'loading_EDT', 'dicharge_port', 'container_type', 'commodity', 'set_point',
        'dicharge_ETA', 'vacuum_maneuver', 'draf_client', 'gated_IN', 'gated_out',
        'delivered', 'insurance', 'corrected_draft', 'vgm',
    ];

    public function __construct(private BookingFilters $filters) {}

    public static function make(BookingFilters $filters): self
    {
        return new self($filters);
    }

    public function paginate(int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage, ['*'], 'page', $page);
    }

    public function get(?int $limit = null): Collection
    {
        return collect($this->query()->when($limit, fn ($q) => $q->limit($limit))->get());
    }

    public function query(): Builder
    {
        $query = DB::table('booking as b')
            ->leftJoin('booking_continuity as bc', self::ultimaContinuidad(...))
            ->leftJoin('vessel as v', 'v.vessel_id', '=', 'b.vessel')
            ->leftJoin('client as c', 'c.client_id', '=', 'b.client')
            ->leftJoin('loading_ports as lp', 'lp.port_id', '=', 'b.loading_port')
            ->leftJoin('dicharge_port as dp', 'dp.dicharge_port_id', '=', 'b.dicharge_port_id')
            ->leftJoin('pickup_place as pp', 'pp.pick_id', '=', 'b.pick_up_place_id')
            ->leftJoin('users as creador', 'creador.usr_id', '=', 'b.created_by')
            ->leftJoinSub($this->progress(), 'progreso', 'progreso.booking_id', '=', 'b.booking_id')
            ->selectRaw(<<<'SQL'
                b.booking_id,
                b.booking_number,
                b.customer_reference,
                b.commodity,
                b.set_point,
                b.loading_EDT,
                b.dicharge_ETA,
                b.booking_type,
                b.locked,
                b.is_draft,
                b.mode,
                b.arrival,
                b.created_at,
                v.vessel_name,
                c.fullName AS client_name,
                lp.port_name,
                dp.name AS discharge_name,
                pp.name AS pickup_name,
                creador.username AS creator,
                bc.pickup_date,
                bc.SI_date,
                bc.cont_id,
                IFNULL(progreso.total_completed, 0) AS total_completed
            SQL)
            ->when($this->filters->is_draft !== null, fn ($q) => $q->where('b.is_draft', $this->filters->is_draft))
            ->where('b.mode', $this->filters->mode);

        $this->applyFilters($query);

        // Sin GROUP BY: cada JOIN trae a lo más una fila por booking (catálogos
        // por llave primaria, avance ya agrupado y una sola fila de
        // continuidad), así que el resultado es determinista sin depender de
        // `strict => false`.
        return $query->orderByDesc('b.booking_id');
    }

    /**
     * Condición del JOIN a `booking_continuity`: solo la fila más reciente
     * (mayor `cont_id`) de cada booking.
     *
     * En `frego` la columna `booking` es única, pero el esquema no lo exige en
     * todas partes; si un booking llegara a tener dos filas, el listado y el
     * reporte de continuidad lo enseñarían repetido o con fechas mezcladas.
     */
    public static function ultimaContinuidad(JoinClause $join): void
    {
        $join->on('bc.booking', '=', 'b.booking_id')
            ->whereRaw('bc.cont_id = (SELECT MAX(ult.cont_id) FROM booking_continuity ult WHERE ult.booking = b.booking_id)');
    }

    /**
     * Divisor del porcentaje de avance.
     *
     * ⚠️ Aquí hay **tres números distintos** y no es un descuido de la traducción:
     * la tabla `check_list` tiene **28** columnas de fecha, el avance solo cuenta
     * **27** (deja fuera `pick_up_place`) y divide entre **26**.
     *
     * Se conserva tal cual para que los porcentajes coincidan con los que el
     * cliente ve hoy. El efecto es que un booking con las 27 casillas marcadas
     * mostraría 103.85 %; hoy no ocurre porque ninguno las tiene todas, pero es
     * cuestión de tiempo. Corregirlo (dividir entre `count(self::CHECKS)` y sumar
     * `pick_up_place` a la lista) baja de golpe todos los avances: es decisión
     * del cliente, no de la migración.
     */
    private const DIVISOR_HISTORICO = 26;

    /**
     * Avance de la lista de verificación, en porcentaje: cuántas de las casillas
     * tienen fecha.
     *
     * El original lo resuelve con una subconsulta de dos niveles que primero
     * convierte cada fecha en 1 o 0 y luego las suma; aquí es una sola pasada.
     */
    private function progress(): Builder
    {
        if (config('marca.avance') !== 'verificacion') {
            return $this->progresoPorHitos();
        }

        $contadas = implode(' + ', array_map(
            fn (string $check) => "CASE WHEN `{$check}_chk_date` IS NOT NULL THEN 1 ELSE 0 END",
            self::CHECKS,
        ));

        return DB::table('check_list')
            ->selectRaw(
                'booking AS booking_id, ROUND((100 / '.self::DIVISOR_HISTORICO.") * ({$contadas}), 2) AS total_completed"
            )
            ->groupBy('booking');
    }

    /**
     * Avance por hitos: cuántos pasos del catálogo tienen fecha.
     *
     * Es el que ve cualquier instalación que no sea la original. El de las
     * casillas heredadas cuenta 27 verificaciones de CAMPO —que el buque esté
     * bien escrito, que el cliente sea el que es— y no pasos de la operación:
     * en una empresa de camiones ese porcentaje no significa nada, y encima no
     * cuadraba con la lista que se enseña en el detalle.
     */
    private function progresoPorHitos(): Builder
    {
        $total = max(1, MilestoneCatalog::activos()->count());

        return DB::table('hito_por_expediente as hpe')
            ->join('hito as h', 'h.hito_id', '=', 'hpe.hito_id')
            ->where('h.activo', 1)
            ->whereNotNull('hpe.fecha')
            ->selectRaw("hpe.booking AS booking_id, ROUND(100 * COUNT(*) / {$total}, 2) AS total_completed")
            ->groupBy('hpe.booking');
    }

    private function applyFilters(Builder $query): void
    {
        $f = $this->filters;

        $query
            ->when($f->booking_number, fn ($q, $v) => $q->where('b.booking_number', 'like', "%{$v}%"))
            ->when($f->client_name, fn ($q, $v) => $q->where('c.fullName', 'like', "%{$v}%"))
            ->when($f->vessel_name, fn ($q, $v) => $q->where('v.vessel_name', 'like', "%{$v}%"))
            ->when($f->port_name, fn ($q, $v) => $q->where('lp.port_name', 'like', "%{$v}%"))
            ->when($f->commodity, fn ($q, $v) => $q->where('b.commodity', 'like', "%{$v}%"))
            ->when($f->client, fn ($q, $v) => $q->where('b.client', $v))
            ->when($f->vessel, fn ($q, $v) => $q->where('b.vessel', $v))
            ->when($f->dicharge_port_id, fn ($q, $v) => $q->where('b.dicharge_port_id', $v))
            ->when($f->pick_up_place_id, fn ($q, $v) => $q->where('b.pick_up_place_id', $v))
            ->when($f->booking_type, fn ($q, $v) => $q->where('b.booking_type', $v))
            ->when($f->onlyLocked, fn ($q) => $q->where('b.locked', 1));

        foreach ([
            'dates' => 'bc.pickup_date',
            'si_filter' => 'bc.SI_date',
            'loading_EDT' => 'b.loading_EDT',
            'dicharge_ETA' => 'b.dicharge_ETA',
            'created' => 'b.created_at',
        ] as $propiedad => $columna) {
            if (($rango = $f->range($propiedad)) !== null) {
                $query->whereBetween(DB::raw("DATE({$columna})"), $rango);
            }
        }
    }
}
