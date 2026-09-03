<?php

namespace App\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Motor de consulta del módulo Transactions.
 *
 * Reemplaza a `TransactionSearch::search()` de Yii2 devolviendo EXACTAMENTE los
 * mismos números (mismos IFNULL, mismos signos, mismos NULL heredados), pero con
 * otro plan de ejecución. Las diferencias contra el original son solo de ejecución:
 *
 *  1. Un solo agregado de `charge`. El original define tres tablas derivadas
 *     (VAT16 / VAT0 / no deducible) y las vuelve a definir dentro de la
 *     subconsulta de pagos: seis barridos de la tabla `charge`. Aquí es uno solo,
 *     con `SUM(CASE WHEN …)` por cubeta.
 *  2. Consulta en dos fases. Primero se resuelve qué IDs entran en la página con
 *     los filtros indexables; después se agregan SOLO esos IDs. El original agrega
 *     las 33 919 líneas de cargo del sistema aunque muestre 20 filas.
 *  3. `exchange` se une por su índice único (`date_exchange`, `account`). El
 *     original usa un `OR` en el `ON` que anula el índice y obliga a un barrido
 *     completo por cada fila. La rama de la moneda base se resuelve con un CASE.
 *
 * La fase 2 solo se puede saltar cuando el filtro depende de los agregados
 * (Paid/Partial/Unpaid) o cuando se agrupa por booking/proveedor/cliente; en esos
 * casos se corre una sola consulta, pero igual con un único barrido de `charge`.
 */
class TransactionQuery
{
    private ?float $defaultRate = null;

    private bool $defaultRateResolved = false;

    public function __construct(private TransactionFilters $filters) {}

    public static function make(TransactionFilters $filters): self
    {
        return new self($filters);
    }

    // ---------------------------------------------------------------- API

    /**
     * Las dos fases solo convienen con un tope de filas: acotar los agregados a una
     * lista de IDs es la optimización. Sin tope (exportar, reportes) se corre la
     * consulta única, que igual hace un solo recorrido de `charge`.
     */
    public function get(?int $limit = null): Collection
    {
        if ($limit !== null && $this->canUseTwoPhase()) {
            $ids = $this->idQuery()->limit($limit)->pluck('transc_id')->all();

            return $ids === [] ? collect() : $this->rowsFor($ids);
        }

        return collect($this->aggregateQuery()->when($limit, fn ($q) => $q->limit($limit))->get());
    }

    public function paginate(int $perPage = 100, int $page = 1): LengthAwarePaginator
    {
        if ($this->canUseTwoPhase()) {
            $total = $this->countIds();
            $ids = $this->idQuery()->forPage($page, $perPage)->pluck('transc_id')->all();
            $rows = $ids === [] ? collect() : $this->rowsFor($ids);

            return $this->paginator($rows, $total, $perPage, $page);
        }

        $query = $this->aggregateQuery();
        $total = $this->countAggregated($query);
        $rows = collect($query->forPage($page, $perPage)->get());

        return $this->paginator($rows, $total, $perPage, $page);
    }

    /**
     * Sumas de las columnas calculadas sobre TODO el conjunto filtrado
     * (no solo la página). Sustituye a `get_sum` de Yii2.
     *
     * @param  string[]  $columns
     * @return array<string, float>
     */
    /**
     * Cuántas transacciones caen en el filtro, sin traerlas.
     *
     * Reusa el mismo par de caminos que `paginate()`: la cuenta barata cuando
     * el filtro se resuelve en la fase 1, y la de la consulta agregada cuando
     * hay condiciones sobre importes.
     */
    public function count(): int
    {
        return $this->canUseTwoPhase()
            ? $this->countIds()
            : $this->countAggregated($this->aggregateQuery());
    }

    public function totals(array $columns = ['total_amount', 'left_to_pay', 'amount_original_mxn']): array
    {
        $select = implode(', ', array_map(
            fn (string $c) => 'SUM(agg.'.$c.') AS '.$c,
            $columns
        ));

        $inner = $this->aggregateQuery();

        $row = DB::table(DB::raw('('.$inner->toSql().') AS agg'))
            ->mergeBindings($inner)
            ->selectRaw($select)
            ->first();

        return collect($columns)
            ->mapWithKeys(fn (string $c) => [$c => (float) ($row->{$c} ?? 0)])
            ->all();
    }

    // ------------------------------------------------------------- Fase 1

    /**
     * Fase 1: los IDs que entran, resueltos solo con filtros indexables.
     * Es la consulta barata — no toca `charge` ni `payments_by_transaction`.
     */
    public function idQuery(): Builder
    {
        $query = DB::table('transaction as t')
            ->select('t.transc_id')
            ->leftJoin('booking as b', 'b.booking_id', '=', 't.booking')
            ->leftJoin('provider as vendor', 'vendor.provider_id', '=', 't.vendor')
            ->leftJoin('client as customer', 'customer.client_id', '=', 't.customer');

        if ($this->filters->request_date !== null) {
            $query->leftJoin('payment_request as pr', 'pr.request_id', '=', 't.request_id');
        }

        $this->applyRowFilters($query);

        return $query->orderByRaw($this->sortExpression(aggregated: false));
    }

    private function countIds(): int
    {
        $inner = $this->idQuery()->reorder();

        return (int) DB::table(DB::raw('('.$inner->toSql().') AS ids'))
            ->mergeBindings($inner)
            ->count();
    }

    // ------------------------------------------------------------- Fase 2

    /** Fase 2: los agregados, acotados a los IDs de la página. */
    private function rowsFor(array $ids): Collection
    {
        $rows = collect($this->aggregateQuery($ids)->get());

        // La fase 1 ya definió el orden; se respeta aunque el GROUP BY lo altere.
        $position = array_flip($ids);

        return $rows->sortBy(fn ($row) => $position[$row->transc_id] ?? PHP_INT_MAX)->values();
    }

    /**
     * Consulta completa con los montos calculados.
     *
     * @param  int[]|null  $ids  Restringe los agregados a estas transacciones.
     */
    public function aggregateQuery(?array $ids = null): Builder
    {
        $e = new TransactionExpressions($this->filters, $this->defaultExchangeRate());

        $chargeAgg = $this->chargeAggregate($ids);
        $paymentsAgg = $this->paymentsAggregate($ids);

        $query = DB::table('transaction as t')
            ->leftJoin('booking as b', 'b.booking_id', '=', 't.booking')
            ->leftJoin('provider as vendor', 'vendor.provider_id', '=', 't.vendor')
            ->leftJoin('client as customer', 'customer.client_id', '=', 't.customer')
            ->leftJoin('account as a', 'a.account_id', '=', 't.account')
            ->leftJoin('company as co', 'co.company_id', '=', 't.company_id')
            ->leftJoin('payment_request as pr', 'pr.request_id', '=', 't.request_id')
            ->leftJoinSub($chargeAgg, 'ca', 'ca.transc_id', '=', 't.transc_id')
            // El índice único uq_date es (date_exchange, account): con las dos
            // columnas en igualdad, MariaDB resuelve por índice en vez de barrer.
            ->leftJoin('exchange as ex', function ($join) {
                $join->on('ex.date_exchange', '=', 't.tran_date')
                    ->on('ex.account', '=', 't.account');
            });

        // El original usa INNER JOIN cuando se filtra por solicitud de pago:
        // así descarta las transacciones que no pertenecen a esa solicitud.
        $this->filters->request_id !== null
            ? $query->joinSub($paymentsAgg, 'p', 'p.transc_id', '=', 't.transc_id')
            : $query->leftJoinSub($paymentsAgg, 'p', 'p.transc_id', '=', 't.transc_id');

        $query->selectRaw($e->selectList());

        if ($ids !== null) {
            $query->whereIn('t.transc_id', $ids);
        } else {
            $this->applyRowFilters($query);
        }

        $query->groupBy(DB::raw($this->groupByColumn()));

        $this->applyAggregateFilters($query, $e);

        if ($ids === null) {
            $query->orderByRaw($this->sortExpression(aggregated: true));
        }

        return $query;
    }

    private function countAggregated(Builder $query): int
    {
        $inner = (clone $query)->reorder();

        return (int) DB::table(DB::raw('('.$inner->toSql().') AS agg'))
            ->mergeBindings($inner)
            ->count();
    }

    // ------------------------------------------------------- Subconsultas

    /**
     * Agregado de cargos por transacción, en UN solo recorrido de `charge`.
     *
     * Las tres cubetas del original (16 %, 0 % y no deducible) se resuelven con
     * `SUM(CASE WHEN …)`. Un `SUM` sin filas que cumplan da NULL, igual que el
     * LEFT JOIN contra una tabla derivada vacía: la aritmética de afuera no cambia.
     */
    private function chargeAggregate(?array $ids): Builder
    {
        $amount = 'IFNULL(c.price, 0) * IFNULL(c.quantity, 0)';
        $vat16 = 'ct.tax_rate = 0.16 AND ct.non_deductible = 0';
        $vat0 = 'ct.tax_rate = 0 AND ct.non_deductible = 0';
        $nonDec = 'ct.tax_rate = 0 AND ct.non_deductible = 1';

        $query = DB::table('charge as c')
            ->leftJoin('charge_type as ct', 'ct.charge_type_id', '=', 'c.type')
            // `transaction` es palabra reservada: va entre acentos graves para que
            // funcione igual en MariaDB y en el SQLite de las pruebas.
            ->selectRaw(<<<SQL
                c.`transaction` AS transc_id,
                SUM(CASE WHEN {$vat16} THEN {$amount} END) AS vat16_subtotal,
                SUM(CASE WHEN {$vat16} THEN {$amount} * IFNULL(ct.tax_rate, 0) END) AS vat16_tax,
                SUM(CASE WHEN {$vat16} THEN {$amount} * IFNULL(ct.tax_retention, 0) END) AS vat16_ret,
                SUM(CASE WHEN {$vat0} THEN {$amount} END) AS vat0_subtotal,
                SUM(CASE WHEN {$vat0} THEN {$amount} * IFNULL(ct.tax_rate, 0) END) AS vat0_tax,
                SUM(CASE WHEN {$nonDec} THEN {$amount} END) AS nondec_subtotal
            SQL)
            ->groupBy('c.transaction');

        return $this->restrict($query, 'c.transaction', $ids);
    }

    /**
     * Agregado de pagos por transacción.
     *
     * Cada pago se prorratea contra el total del documento (`pct`) para repartirlo
     * entre subtotales, IVA y retención. `paid_exchange_value` es el tipo de cambio
     * ponderado por monto de los pagos: valúa la transacción a la fecha en que se
     * pagó, no a la del documento.
     */
    private function paymentsAggregate(?array $ids): Builder
    {
        $rate = $this->exchangeCase('pacc', 'ex_req');

        $nd = 'IFNULL(ca.nondec_subtotal, 0)';
        $s16 = 'IFNULL(ca.vat16_subtotal, 0)';
        $s0 = 'IFNULL(ca.vat0_subtotal, 0)';
        $t16 = 'IFNULL(ca.vat16_tax, 0)';
        $ret = 'IFNULL(ca.vat16_ret, 0)';
        $total = "({$nd} + {$s16} + {$s0} + {$t16} - {$ret})";

        // División sin proteger, igual que el original: si el documento suma 0,
        // MariaDB devuelve NULL y ese pago no aporta a los prorrateos.
        $pct = "(IFNULL(pbt.amount, 0) / {$total})";

        $query = DB::table('payments_by_transaction as pbt')
            ->leftJoin('payment_request as pr', 'pr.request_id', '=', 'pbt.request_id')
            ->leftJoin('account as pacc', 'pacc.account_id', '=', 'pr.currency_id')
            ->leftJoin('exchange as ex_req', function ($join) {
                $join->on('ex_req.date_exchange', '=', 'pr.date')
                    ->on('ex_req.account', '=', 'pr.currency_id');
            })
            ->leftJoinSub($this->chargeAggregate($ids), 'ca', 'ca.transc_id', '=', 'pbt.transc_id')
            ->selectRaw(<<<SQL
                pbt.transc_id,
                SUM(IFNULL(pbt.amount, 0)) AS tran_paid_amount,
                SUM({$s16} * {$pct}) AS sub_16_paid,
                SUM({$s0} * {$pct}) AS sub_0_paid,
                SUM({$t16} * {$pct}) AS tax_16_paid,
                SUM({$ret} * {$pct}) AS tax_ret_paid,
                SUM({$total} * {$pct}) AS total_paid,
                SUM({$total} * {$pct}) AS amount_original_paid,
                SUM(IFNULL(pbt.amount, 0) * IFNULL({$rate}, 1))
                    / NULLIF(SUM(IFNULL(pbt.amount, 0)), 0) AS paid_exchange_value
            SQL)
            ->groupBy('pbt.transc_id');

        if ($this->filters->request_type !== null) {
            $query->where('pr.type', $this->filters->request_type);
        }

        if ($this->filters->request_id !== null) {
            $query->where('pbt.request_id', $this->filters->request_id);
        }

        return $this->restrict($query, 'pbt.transc_id', $ids);
    }

    /**
     * Acota una subconsulta a las transacciones que interesan. Con la lista de IDs
     * de la página se agregan decenas de filas en vez de la tabla completa; sin
     * ella se reusa el filtro de la fase 1 como semi-join.
     */
    private function restrict(Builder $query, string $column, ?array $ids): Builder
    {
        if ($ids !== null) {
            return $query->whereIn($column, $ids);
        }

        return $query->whereIn($column, $this->idQuery()->reorder());
    }

    // ---------------------------------------------------------- Filtros

    /** Filtros que se pueden resolver fila por fila (sin agregados). */
    private function applyRowFilters(Builder $query): void
    {
        $f = $this->filters;

        // Bookings reales (10) o cotizaciones (9). Es un WHERE sobre el LEFT JOIN,
        // así que también descarta transacciones sin booking — igual que el original.
        $query->where('b.mode', $f->showQuatation ? 9 : 10);

        if ($f->customer !== null) {
            $query->where('t.customer', $f->customer)->where('t.tran_type', 0);
        }

        if ($f->vendor !== null) {
            $query->where('t.vendor', $f->vendor)->where('t.tran_type', '<>', 0);
        }

        if ($f->booking !== null) {
            $query->where('t.booking', $f->booking);
        }

        // Agrupar por proveedor solo tiene sentido en costos, y por cliente en facturas.
        if ($f->groupBy === 'vendor') {
            $query->where('t.tran_type', '<>', 0);
        } elseif ($f->groupBy === 'customer') {
            $query->where('t.tran_type', 0);
        }

        // Selección para timbrar: solo facturas que aún no tienen CFDI.
        if (! empty($f->transc_id_in)) {
            $query->where('t.tran_type', 0)
                ->whereIn('t.transc_id', $f->transc_id_in)
                ->where(fn ($q) => $q->whereNull('t.seal')->orWhere('t.seal', ''));
        }

        if (! empty($f->tran_in)) {
            $query->whereIn('t.transc_id', $f->tran_in);
        }

        if (! empty($f->booking_in)) {
            $query->whereIn('t.booking', $f->booking_in);
        }

        if ($f->types() !== []) {
            $query->whereIn('t.tran_type', $f->types());
        }

        if (! empty($f->type_in)) {
            $query->whereIn('t.tran_type', $f->type_in);
        }

        if ($f->notIn !== null) {
            $query->whereNotIn('t.transc_id', function ($sub) use ($f) {
                $sub->select('transc_id')->from('payments_by_transaction')->where('request_id', $f->notIn);
            });
        }

        if ($range = TransactionFilters::parseRange($f->dates)) {
            $query->whereBetween('t.tran_date', $range);
        }

        if ($range = TransactionFilters::parseRange($f->dates_booking)) {
            $query->whereBetween('b.loading_EDT', $range);
        }

        if ($range = TransactionFilters::parseRange($f->request_date)) {
            $query->whereBetween('pr.date', $range);
        }

        if ($f->transc_id !== null) {
            $query->where('t.transc_id', $f->transc_id);
        }

        if ($f->account !== null) {
            $query->where('t.account', $f->account);
        }

        if ($f->company_id !== null) {
            $query->where('t.company_id', $f->company_id);
        }

        if (filled($f->appliedTo)) {
            $query->where(function ($q) use ($f) {
                $q->where('vendor.fullName', 'like', '%'.$f->appliedTo.'%')
                    ->orWhere('customer.fullName', 'like', '%'.$f->appliedTo.'%');
            });
        }

        foreach (['seal' => 't.seal', 'tran_number' => 't.tran_number', 'booking_number' => 'b.booking_number'] as $prop => $column) {
            if (filled($f->{$prop})) {
                $query->where($column, 'like', '%'.trim($f->{$prop}).'%');
            }
        }

        if ($f->cancelled !== null) {
            $query->where('t.cancelled', $f->cancelled);
        }

        match ($f->showCancelled) {
            0 => $query->where('t.cancelled', 0),
            1 => $query->whereIn('t.cancelled', [0, 1]),
            2 => $query->where('t.cancelled', 1),
            default => null,
        };
    }

    /** Filtros que solo se pueden evaluar una vez agregados los montos. */
    private function applyAggregateFilters(Builder $query, TransactionExpressions $e): void
    {
        $f = $this->filters;
        $left = $e->leftToPayMagnitude();
        $total = $e->totalMagnitude();

        if ($f->onlyUndpaid) {
            $query->havingRaw("{$left} > 0");
        }

        if ($f->paid === null || $f->paid === '') {
            return;
        }

        match ((int) $f->paid) {
            // El original compara contra el alias `tran_paid_amount`. Se envuelve en
            // SUM() por dos razones: MySQL solo admite en HAVING alias, agregados o
            // columnas del GROUP BY, y referirse al alias a secas es ambiguo (existe
            // también la columna `p.tran_paid_amount`), lo que cada motor resuelve a
            // su manera. Agrupando por transacción `p` aporta una sola fila, así que
            // SUM devuelve ese mismo valor.
            0 => $query->havingRaw('SUM(IFNULL(p.tran_paid_amount, 0)) = 0'),
            1 => $query->havingRaw("{$left} = 0 AND {$total} > 0"),
            2 => $query->havingRaw("{$left} > 0 AND {$left} < {$total}"),
            default => null,
        };
    }

    // ---------------------------------------------------------- Auxiliares

    private function canUseTwoPhase(): bool
    {
        return $this->filters->groupedByTransaction()
            && ! $this->filters->needsAggregateFilter()
            && $this->sortFitsIdQuery();
    }

    private function groupByColumn(): string
    {
        return match ($this->filters->groupBy) {
            'booking' => 't.booking',
            'vendor' => 't.vendor',
            'customer' => 't.customer',
            default => 't.transc_id',
        };
    }

    /**
     * Columnas por las que se puede ordenar.
     *
     * `row` es la expresión tal como se puede usar en la FASE 1 —la consulta
     * barata que solo elige los IDs de la página—; `agg` es la de la consulta
     * con agregados. Las que traen `row => null` son importes calculados: no
     * existen todavía en la fase 1, así que ordenar por ellas obliga a la
     * consulta completa (más lenta, pero es la única que conoce el número).
     *
     * @var array<string, array{row: ?string, agg: string}>
     */
    private const SORTABLE = [
        'transc_id' => ['row' => 't.transc_id', 'agg' => 't.transc_id'],
        'booking' => ['row' => 't.booking', 'agg' => 't.booking'],
        'tran_date' => ['row' => 't.tran_date', 'agg' => 't.tran_date'],
        'tran_number' => ['row' => 't.tran_number', 'agg' => 't.tran_number'],
        'tran_type' => ['row' => 't.tran_type', 'agg' => 't.tran_type'],
        'seal' => ['row' => 't.seal', 'agg' => 't.seal'],
        'cancelled' => ['row' => 't.cancelled', 'agg' => 't.cancelled'],
        // La columna «Aplicado a» enseña el cliente o, si no hay, el proveedor.
        // Las dos tablas ya vienen unidas en la fase 1, así que sale barato.
        'applied_to' => [
            'row' => 'COALESCE(customer.fullName, vendor.fullName)',
            'agg' => 'COALESCE(customer.fullName, vendor.fullName)',
        ],
        // Compañía y divisa se unen solo en la consulta con agregados; no se
        // suman a la fase 1 para no encarecer TODAS las consultas por una
        // ordenación que casi no se usa.
        'company' => ['row' => null, 'agg' => 'companyName'],
        'currency' => ['row' => null, 'agg' => 'currency'],
        'amount_original' => ['row' => null, 'agg' => 'amount_original'],
        'exchange_value' => ['row' => null, 'agg' => 'exchange_value'],
        'sub_0_mxn' => ['row' => null, 'agg' => 'sub_0_mxn'],
        'sub_16_mxn' => ['row' => null, 'agg' => 'sub_16_mxn'],
        'tax_16_mxn' => ['row' => null, 'agg' => 'tax_16_mxn'],
        'tax_ret_mxn' => ['row' => null, 'agg' => 'tax_ret_mxn'],
        'total_amount' => ['row' => null, 'agg' => 'total_amount'],
        'tran_paid_amount' => ['row' => null, 'agg' => 'tran_paid_amount'],
        // El «Estado» del renglón se deriva del saldo, así que se ordena por él.
        'left_to_pay' => ['row' => null, 'agg' => 'left_to_pay'],
    ];

    /** @return array{row: ?string, agg: string} */
    private function sortDefinition(): array
    {
        return self::SORTABLE[$this->filters->sort] ?? self::SORTABLE['transc_id'];
    }

    /** ¿La ordenación pedida se puede resolver en la fase barata? */
    public function sortFitsIdQuery(): bool
    {
        return $this->sortDefinition()['row'] !== null;
    }

    private function sortExpression(bool $aggregated): string
    {
        $definicion = $this->sortDefinition();

        return ($aggregated ? $definicion['agg'] : $definicion['row']).' '.$this->sortDirection();
    }

    private function sortDirection(): string
    {
        return strtolower($this->filters->direction) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Tipo de cambio de la moneda base.
     *
     * El original une `exchange` con un OR que, para la cuenta marcada como
     * `default`, engancha su renglón sin importar la fecha. En los datos reales esa
     * cuenta tiene exactamente un renglón (1.0000), así que aquí se resuelve como
     * escalar y el JOIN queda limpio por índice. Si algún día hubiera más de un
     * renglón, el original duplicaría filas y este método lo delata: se queda con
     * el primero y las pruebas de paridad marcarían la diferencia.
     *
     * Se recuerda dentro de la instancia, no en la caché de la aplicación: armar
     * una consulta lo pide varias veces, pero guardarlo en disco entre peticiones
     * no valía el riesgo (ver la nota en `Account::options()`).
     */
    private function defaultExchangeRate(): ?float
    {
        if (! $this->defaultRateResolved) {
            $row = DB::table('exchange')
                ->join('account', 'account.account_id', '=', 'exchange.account')
                ->where('account.default', 1)
                ->orderBy('exchange.exchange_id')
                ->value('exchange.exchange_value');

            $this->defaultRate = $row === null ? null : (float) $row;
            $this->defaultRateResolved = true;
        }

        return $this->defaultRate;
    }

    /** CASE que decide el tipo de cambio de una fila (moneda base vs. fecha). */
    private function exchangeCase(string $accountAlias, string $exchangeAlias): string
    {
        $default = $this->defaultExchangeRate();
        $literal = $default === null ? 'NULL' : (string) $default;

        return "(CASE WHEN {$accountAlias}.`default` = 1 THEN {$literal} ELSE {$exchangeAlias}.exchange_value END)";
    }

    private function paginator(Collection $rows, int $total, int $perPage, int $page): LengthAwarePaginator
    {
        return new Paginator($rows, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }
}
