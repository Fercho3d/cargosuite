<?php

namespace App\Queries;

use App\Support\ExchangeRates;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Motor de consulta de las solicitudes de pago: lo cobrado a clientes y lo
 * pagado a proveedores, prorrateado por concepto y valuado a dos tipos de cambio.
 *
 * Es la traducción de `PaymentRequestSearch` de Yii2. De él salen los reportes
 * por cliente, por proveedor y el general.
 *
 * **Cómo funciona.** Cada pago (`payments_by_transaction`) se compara contra el
 * total del documento que paga; ese porcentaje reparte el pago entre las cubetas
 * de la transacción (subtotal 16 %, subtotal 0 %, no deducible, IVA y retención).
 * Luego cada cubeta se valúa al tipo de cambio de la solicitud y, aparte, al de
 * una fecha elegida por el usuario; la diferencia entre ambas valuaciones es la
 * ganancia o pérdida cambiaria.
 *
 * **Sobre la fidelidad de las fórmulas.** Se copian tal cual, con sus
 * inconsistencias: en `total_paid` el tipo de cambio multiplica solo al grupo con
 * IVA y deja fuera el no deducible, mientras que en `non_dec` sí lo multiplica, y
 * en `amount_original` el no deducible entra en el mismo paréntesis que los demás.
 * No es un descuido de la traducción — así calcula el sistema en operación, y
 * "arreglarlo" movería cifras que el cliente ya concilió.
 *
 * **Optimización aplicada** (la misma que en `TransactionQuery`): el original
 * define tres tablas derivadas sobre `charge` y las agrega enteras; aquí es un
 * solo recorrido con `SUM(CASE WHEN …)`. Y el `JOIN` de `exchange` se hace por
 * igualdad en vez de con un `OR` que anulaba el índice.
 */
class PaymentRequestQuery
{
    private ?float $defaultRate = null;

    private bool $defaultRateResolved = false;

    public function __construct(private PaymentRequestFilters $filters) {}

    public static function make(PaymentRequestFilters $filters): self
    {
        return new self($filters);
    }

    // ---------------------------------------------------------------- API

    public function get(?int $limit = null): Collection
    {
        return collect($this->query()->when($limit, fn ($q) => $q->limit($limit))->get());
    }

    public function paginate(int $perPage = 100, int $page = 1): LengthAwarePaginator
    {
        $query = $this->query();
        $total = $this->countGrouped($query);
        $rows = collect((clone $query)->forPage($page, $perPage)->get());

        return new Paginator($rows, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * Sumas de las columnas calculadas sobre todo el conjunto filtrado.
     *
     * @param  string[]  $columns
     * @return array<string, float>
     */
    public function totals(array $columns = ['total_paid', 'total_to_pay']): array
    {
        $inner = $this->query();

        $select = implode(', ', array_map(fn (string $c) => "SUM(agg.{$c}) AS {$c}", $columns));

        $row = DB::table(DB::raw('('.$inner->toSql().') AS agg'))
            ->mergeBindings($inner)
            ->selectRaw($select)
            ->first();

        return collect($columns)
            ->mapWithKeys(fn (string $c) => [$c => (float) ($row->{$c} ?? 0)])
            ->all();
    }

    /**
     * Saldo por banco activo: la suma en pesos de las solicitudes pagadas hasta
     * la fecha de corte, con signo (cobros suman, pagos restan), al tipo de
     * cambio de cada solicitud. Es el «Total» que la pantalla de Bancos del
     * original calculaba con `BankEntrySearch` (sin los movimientos de banco,
     * que están apagados). Una solicitud sin tipo de cambio registrado no
     * aporta, igual que allá.
     *
     * @return Collection<int, object> bank_id, bank_name, total
     */
    public function bankBalances(Carbon $hasta): Collection
    {
        $tc = $this->requestRate();

        return collect(DB::table('bank')
            ->leftJoin('payment_request as pr', function ($join) use ($hasta) {
                $join->on('pr.bank_id', '=', 'bank.bank_id')
                    ->where('pr.paid', '=', 1)
                    ->whereDate('pr.date', '<=', $hasta->toDateString());
            })
            ->leftJoin('account as acc', 'acc.account_id', '=', 'pr.currency_id')
            ->leftJoin('exchange as ex', function ($join) {
                $join->on('ex.account', '=', 'acc.account_id')
                    ->on('ex.date_exchange', '=', 'pr.date');
            })
            ->where('bank.active', 1)
            ->groupBy('bank.bank_id', 'bank.bank_name')
            ->orderBy('bank.bank_name')
            ->selectRaw("bank.bank_id, bank.bank_name,
                ROUND(IFNULL(SUM((CASE WHEN pr.type = 1 THEN pr.amount ELSE -pr.amount END) * {$tc}), 0), 2) AS total")
            ->get());
    }

    // ------------------------------------------------------------ Armado

    public function query(): Builder
    {
        $this->ensurePayDateRate();

        $query = DB::table('payment_request as pr')
            ->leftJoin('payments_by_transaction as pbt', 'pbt.request_id', '=', 'pr.request_id')
            ->leftJoinSub($this->chargeAggregate(), 'ca', 'ca.transc_id', '=', 'pbt.transc_id')
            ->leftJoin('bank', 'bank.bank_id', '=', 'pr.bank_id')
            ->leftJoin('account as acc', 'acc.account_id', '=', 'pr.currency_id')
            ->leftJoin('provider', 'provider.provider_id', '=', 'pr.provider_id')
            ->leftJoin('client', 'client.client_id', '=', 'pr.client_id')
            // Igualdad en las dos columnas del índice único `uq_date`; el original
            // usaba un OR entre columnas distintas y se resolvía por barrido.
            ->leftJoin('exchange as ex', function ($join) {
                $join->on('ex.account', '=', 'acc.account_id')
                    ->on('ex.date_exchange', '=', 'pr.date');
            })
            ->leftJoin('exchange as expay', function ($join) {
                $join->on('expay.account', '=', 'acc.account_id')
                    ->where('expay.date_exchange', '=', $this->filters->payDate());
            });

        $query->selectRaw($this->selectList());

        $this->applyFilters($query);

        $query->groupBy(DB::raw($this->groupByColumns()))
            ->orderBy('pr.request_id', 'desc');

        return $query;
    }

    /**
     * Agregado de cargos por transacción, en un solo recorrido.
     *
     * Ojo: aquí la cubeta del 16 % **no excluye** los cargos no deducibles, a
     * diferencia del motor de transacciones. Es como está en el original y hay que
     * dejarlo así, o los reportes de pagos dejarían de cuadrar con los de hoy.
     */
    private function chargeAggregate(): Builder
    {
        $amount = 'IFNULL(c.price, 0) * IFNULL(c.quantity, 0)';
        $vat16 = 'ct.tax_rate = 0.16';
        $vat0 = 'ct.tax_rate = 0 AND ct.non_deductible = 0';
        $nonDec = 'ct.tax_rate = 0 AND ct.non_deductible = 1';

        return DB::table('charge as c')
            ->leftJoin('charge_type as ct', 'ct.charge_type_id', '=', 'c.type')
            ->selectRaw(<<<SQL
                c.`transaction` AS transc_id,
                SUM(CASE WHEN {$vat16} THEN {$amount} END) AS vat16_subtotal,
                SUM(CASE WHEN {$vat16} THEN {$amount} * IFNULL(ct.tax_rate, 0) END) AS vat16_tax,
                SUM(CASE WHEN {$vat16} THEN {$amount} * IFNULL(ct.tax_retention, 0) END) AS vat16_ret,
                SUM(CASE WHEN {$vat0} THEN {$amount} END) AS vat0_subtotal,
                SUM(CASE WHEN {$nonDec} THEN {$amount} END) AS nondec_subtotal
            SQL)
            ->groupBy('c.transaction');
    }

    private function selectList(): string
    {
        $nd = 'IFNULL(ca.nondec_subtotal, 0)';
        $s16 = 'IFNULL(ca.vat16_subtotal, 0)';
        $s0 = 'IFNULL(ca.vat0_subtotal, 0)';
        $t16 = 'IFNULL(ca.vat16_tax, 0)';
        $ret = 'IFNULL(ca.vat16_ret, 0)';

        $conIva = "({$s16} + {$s0} + {$t16} - {$ret})";
        $documento = "({$nd} + {$s16} + {$s0} + {$t16} - {$ret})";
        $pct = "(IFNULL(pbt.amount, 0) / {$documento})";

        $tcSolicitud = $this->requestRate();
        $tcPago = $this->payRate();

        $x = $this->filters->noExchange ? '' : " * {$tcSolicitud}";
        $signo = $this->filters->noNegative ? '' : ' * (CASE WHEN pr.type = 1 THEN 1 ELSE -1 END)';

        // Las dos valuaciones del documento pagado: a TC de la solicitud y a TC
        // de la fecha elegida. Su resta es la diferencia cambiaria.
        $pagadoSolicitud = "SUM(({$nd} + {$conIva}{$x}) * {$pct})";
        $pagadoFecha = "SUM(({$nd} + {$conIva} * {$tcPago}) * {$pct})";

        return implode(",\n", [
            // --- Identificación ---
            'acc.account_id',
            'acc.prefix',
            'pbt.transc_id',
            'provider.fullName AS providerName',
            'client.fullName AS clientName',
            'bank.bank_name',
            'pr.request_id',
            'pr.amount',
            'pr.number',
            'pr.bank_id',
            'pr.date',
            'pr.client_id',
            'pr.provider_id',
            'pr.paid',
            'pr.type',

            // --- Tipos de cambio ---
            "{$tcSolicitud} AS exchange_value",
            "{$tcPago} AS pay_tc",

            // --- Prorrateo ---
            "{$s16} AS subtotal",
            "{$pct} AS pct",

            // --- Cubetas pagadas ---
            "ROUND(SUM(({$nd}{$x}) * {$pct}){$signo}, 4) AS non_dec",
            "ROUND(SUM(({$s16}{$x}) * {$pct}){$signo}, 4) AS sub_16_paid",
            "ROUND(SUM(({$s0}{$x}) * {$pct}){$signo}, 4) AS sub_0_paid",
            "ROUND(SUM(({$t16}{$x}) * {$pct}){$signo}, 4) AS tax_16_paid",
            "ROUND(SUM(({$ret}{$x}) * {$pct}){$signo}, 4) AS tax_ret_paid",

            // --- Totales ---
            "ROUND({$pagadoSolicitud}{$signo}, 4) AS total_paid",
            "ROUND({$pagadoFecha}{$signo}, 4) AS total_to_pay",

            // La diferencia se lee en el sentido de quien paga: en un cobro a
            // cliente conviene que el peso valga más el día del pago; en un pago a
            // proveedor, al revés. Por eso el signo se invierte según el tipo.
            "CASE WHEN pr.type = 1
                  THEN ROUND({$pagadoFecha} - {$pagadoSolicitud}, 4)
                  ELSE ROUND({$pagadoSolicitud} - {$pagadoFecha}, 4)
             END AS diference",

            "ROUND(SUM(({$nd} + {$conIva}) * {$pct}){$signo}, 4) AS amount_original_paid",
            "ROUND(pr.amount{$signo}, 4) AS amount_original_neg",
            "ROUND(SUM({$documento}){$signo}, 4) AS amount_original",
        ]);
    }

    /** TC de la solicitud: el capturado a mano gana sobre el del día. */
    private function requestRate(): string
    {
        $literal = $this->defaultExchangeRate() === null ? 'NULL' : (string) $this->defaultExchangeRate();

        return "(CASE WHEN pr.custom_tc = 1 THEN pr.tc_value
                      WHEN acc.`default` = 1 THEN {$literal}
                      ELSE ex.exchange_value END)";
    }

    /** TC de la fecha de valuación elegida. */
    private function payRate(): string
    {
        $literal = $this->defaultExchangeRate() === null ? 'NULL' : (string) $this->defaultExchangeRate();

        return "(CASE WHEN acc.`default` = 1 THEN {$literal} ELSE expay.exchange_value END)";
    }

    private function applyFilters(Builder $query): void
    {
        $f = $this->filters;

        $query
            ->when($f->request_id, fn ($q, $v) => $q->where('pr.request_id', $v))
            ->when($f->transc_id, fn ($q, $v) => $q->where('pbt.transc_id', $v))
            ->when($f->bank_id, fn ($q, $v) => $q->where('pr.bank_id', $v))
            ->when($f->provider_id, fn ($q, $v) => $q->where('pr.provider_id', $v))
            ->when($f->client_id, fn ($q, $v) => $q->where('pr.client_id', $v))
            ->when($f->currency_id, fn ($q, $v) => $q->where('pr.currency_id', $v))
            ->when($f->type, fn ($q, $v) => $q->where('pr.type', $v))
            ->when($f->number !== null && $f->number !== '', fn ($q) => $q->where('pr.number', $f->number))
            ->when($f->paid !== null, fn ($q) => $q->where('pr.paid', $f->paid));

        if (($rango = $f->dateRange()) !== null) {
            $query->whereBetween(DB::raw('DATE(pr.date)'), $rango);
        }
    }

    private function groupByColumns(): string
    {
        return match ($this->filters->groupBy) {
            'client' => 'pr.client_id',
            'provider' => 'pr.provider_id, pr.currency_id',
            'type' => 'pr.type',
            default => 'pr.request_id',
        };
    }

    private function countGrouped(Builder $query): int
    {
        $inner = (clone $query)->reorder();

        return (int) DB::table(DB::raw('('.$inner->toSql().') AS agg'))
            ->mergeBindings($inner)
            ->count();
    }

    /**
     * El original pide el tipo de cambio de la fecha de valuación antes de
     * consultar, para que la columna no salga vacía la primera vez que se usa
     * una fecha nueva.
     */
    private function ensurePayDateRate(): void
    {
        $fecha = $this->filters->payDate();

        if ($fecha !== null) {
            app(ExchangeRates::class)->ensureFor(Carbon::parse($fecha));
        }
    }

    private function defaultExchangeRate(): ?float
    {
        if (! $this->defaultRateResolved) {
            $valor = DB::table('exchange')
                ->join('account', 'account.account_id', '=', 'exchange.account')
                ->where('account.default', 1)
                ->orderBy('exchange.exchange_id')
                ->value('exchange.exchange_value');

            $this->defaultRate = $valor === null ? null : (float) $valor;
            $this->defaultRateResolved = true;
        }

        return $this->defaultRate;
    }
}
