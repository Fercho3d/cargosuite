<?php

namespace App\Livewire\Transactions;

use App\Actions\Transactions\CancelStamp;
use App\Actions\Transactions\SendInvoice;
use App\Actions\Transactions\StampTransaction;
use App\Models\CfdiCancelacion;
use App\Models\Core\Account;
use App\Models\Core\Booking;
use App\Models\Core\Company;
use App\Models\Core\Transaction;
use App\Queries\ProfitByBooking;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\Cfdi\CfdiException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Listado de transacciones.
 *
 * Es la misma pantalla para los cuatro listados del sistema original
 * (`invoice`, `bill`, `all` y las transacciones de un booking): cambian el filtro
 * base y las columnas, no la mecánica.
 *
 * Filtrar, ordenar y paginar no recargan la página: Livewire repinta solo la
 * tabla. Junto con `wire:navigate` en el menú, la navegación se siente de app.
 */
class TransactionTable extends Component
{
    use WithPagination;

    /** invoice | bill | all | booking */
    public string $screen = 'invoice';

    public ?int $bookingId = null;

    #[Url(as: 'num', except: '')]
    public string $tranNumber = '';

    #[Url(as: 'bk', except: '')]
    public string $bookingNumber = '';

    #[Url(as: 'q', except: '')]
    public string $appliedTo = '';

    #[Url(as: 'f', except: '')]
    public string $dates = '';

    #[Url(as: 'co', except: '')]
    public string $companyId = '';

    #[Url(as: 'ccy', except: '')]
    public string $accountId = '';

    /** '' = todas, 0 = sin pagar, 1 = pagadas, 2 = parciales. */
    #[Url(as: 'pago', except: '')]
    public string $paid = '';

    #[Url(as: 'canc', except: '0')]
    public string $showCancelled = '0';

    /**
     * Estado de la cancelación ante el SAT (claves de `CfdiCancelacion::VISTA_*`);
     * '' = sin filtrar. Enseña también las canceladas, sin tener que tocar el
     * otro filtro.
     */
    #[Url(as: 'cfdi', except: '')]
    public string $cfdiEstado = '';

    /** Tipo de documento (clave de `TransactionFilters::documentTypes()`); '' = todos. */
    #[Url(as: 'tipo', except: '')]
    public string $docType = '';

    #[Url(as: 'ord', except: 'transc_id')]
    public string $sort = 'transc_id';

    #[Url(as: 'dir', except: 'desc')]
    public string $direction = 'desc';

    #[Url(as: 'n', except: 50)]
    public int $perPage = 50;

    /** Totales de TODO el filtro: se muestran mientras `showTotals` esté encendido. */
    public ?array $totals = null;

    /** Casilla «mostrar sumatoria» del pie; se recuerda en cookie (ver `mount`). */
    public bool $showTotals = false;

    /**
     * Utilidad por booking de la pantalla de Facturas. También bajo demanda: el
     * original la calculaba en cada carga y era la parte más cara de la pantalla.
     */
    public ?array $profit = null;

    /**
     * Transacciones marcadas para agrupar en una solicitud de pago.
     *
     * @var int[]
     */
    public array $selected = [];

    /**
     * Resultado del último timbrado en lote: cuántas se timbraron y qué facturas
     * fallaron con su motivo. Se muestra arriba del listado.
     */
    public ?array $stampResult = null;

    /** Resultado de «Enviar documentos»: cuántas salieron y cuáles no, con su motivo. */
    public ?array $sendResult = null;

    /**
     * Cuántas facturas se timbran por tanda. Cada una es una llamada al PAC, así
     * que se acota para no exceder el tiempo de la petición; si hay más, se
     * timbran en varias vueltas.
     */
    private const STAMP_BATCH = 25;

    /** Milisegundos que tardó la consulta de la última pintada. */
    public float $queryMs = 0;

    private ?Booking $bookingCache = null;

    /** Propiedades que cambian el conjunto de renglones (ver `updated()`). */
    private const FILTERS = [
        'tranNumber',
        'bookingNumber',
        'appliedTo',
        'dates',
        'companyId',
        'accountId',
        'paid',
        'showCancelled',
        'docType',
        'cfdiEstado',
        'perPage',
    ];

    /**
     * Livewire pisa `Paginator::defaultView()` con su propia vista en cada
     * render, así que la vista propia hay que declararla aquí; el registro del
     * `AppServiceProvider` solo cubre los paginadores fuera de Livewire.
     */
    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function paginationSimpleView(): string
    {
        return 'vendor.pagination.app';
    }

    public function mount(string $screen = 'invoice', ?int $booking = null): void
    {
        $this->screen = $screen;
        $this->bookingId = $booking;
        // En un booking la sumatoria va siempre, como en el original: ahí vive el profit.
        $this->showTotals = $screen === 'booking' || request()->cookie('mostrar_totales') === '1';

        // Por omisión el listado arranca acotado al año en curso (del 1 de enero
        // a hoy): así no trae años de historia de golpe —las pantallas van
        // ligeras y «Ver todas» no revienta la memoria—. La pantalla de un
        // booking no se acota: ahí ya se ve solo ese booking.
        if ($this->dates === '' && $this->screen !== 'booking') {
            $this->dates = $this->defaultDates();
        }
    }

    /** Rango por omisión: del 1 de enero de este año a hoy («dd/mm/aaaa - dd/mm/aaaa»). */
    private function defaultDates(): string
    {
        return now()->startOfYear()->format('d/m/Y').' - '.now()->format('d/m/Y');
    }

    /** Cualquier cambio de filtro vuelve a la primera página e invalida totales. */
    public function updated(string $property): void
    {
        // Solo los FILTROS rehacen la consulta. El hook se dispara con cualquier
        // propiedad, así que sin esta lista `selected` se vaciaba a sí misma en
        // cuanto se marcaba una casilla (la casilla se veía "saltar" sola).
        if (! in_array($property, self::FILTERS, true)) {
            return;
        }

        $this->resetPage();
        $this->totals = null;
        $this->profit = null;
        $this->selected = [];
        $this->stampResult = null;
        $this->sendResult = null;
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'desc';
        }

        $this->resetPage();
    }

    /**
     * Aplica los filtros capturados. Los campos son diferidos (no consultan por
     * tecla): al enviar el formulario sus valores llegan de golpe, `updated()`
     * los detecta y aquí solo se vuelve a la primera página. Es el botón
     * «Filtrar», que hace la captura ligera.
     */
    public function filtrar(): void
    {
        $this->resetPage();
    }

    /**
     * «Ver todas»: trae el filtro completo en una sola página, sin paginar. El
     * tope es muy alto (no una página real de ese tamaño): la consulta devuelve
     * las filas que haya, que en la práctica son unos miles.
     */
    public function verTodas(): void
    {
        $this->perPage = 100000;
        $this->resetPage();
    }

    /**
     * «Ver todos los años»: quita el rango por omisión de un clic. Y «Limpiar
     * filtros» deja la pantalla sin rango: limpiar es limpiar, no volver al año.
     */
    public function showAllYears(): void
    {
        $this->dates = '';
        $this->updated('dates');
    }

    /** Texto del rango vigente para la cabecera. */
    public function datesLabel(): string
    {
        if ($this->dates === '') {
            return __('todos los años');
        }

        [$desde, $hasta] = array_pad(explode(' - ', $this->dates, 2), 2, '');

        return __('del :desde al :hasta', ['desde' => $desde, 'hasta' => $hasta]);
    }

    public function clearFilters(): void
    {
        $this->reset(['tranNumber', 'bookingNumber', 'appliedTo', 'dates', 'companyId', 'accountId', 'paid', 'docType', 'cfdiEstado']);
        $this->showCancelled = '0';
        $this->totals = null;
        $this->profit = null;
        $this->selected = [];
        $this->stampResult = null;
        $this->sendResult = null;
        $this->resetPage();
    }

    /** Manda a armar una solicitud de pago con lo que esté marcado. */
    public function createPaymentRequest(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        if ($this->selected === []) {
            $this->addError('selected', __('Marca al menos una transacción.'));

            return;
        }

        if (! $this->selectionIsGroupable()) {
            return;
        }

        $this->redirectRoute('payments.requests.create', [
            'ids' => implode(',', $this->selected),
            'volver' => $this->currentUrl(),
        ], navigate: true);
    }

    /**
     * Antes de llevar a la pantalla de pago, las transacciones marcadas tienen
     * que poder agruparse en UNA solicitud: misma divisa y misma contraparte,
     * porque una solicitud se paga con un solo cheque a un solo tercero. Es la
     * regla que el controlador de Yii2 aplicaba al agrupar, adelantada aquí al
     * momento de seleccionar para no pasar a una pantalla donde el guardado
     * fallaría sin decir por qué.
     */
    private function selectionIsGroupable(): bool
    {
        $marcadas = Transaction::whereIn('transc_id', $this->selected)->get(['tran_type', 'account', 'customer', 'vendor']);

        if ($marcadas->pluck('account')->unique()->count() > 1) {
            $this->addError('selected', __('No se pueden agrupar transacciones de distinta divisa en la misma solicitud.'));

            return false;
        }

        // En la pantalla del booking conviven facturas y costos: el tipo lo
        // dicen las marcadas, y no se mezclan (el original tenía un botón para
        // cada uno: «Pay Invoice» y «Pay Bill»).
        $tipos = $marcadas->pluck('tran_type')->map(fn ($tipo) => (int) $tipo === Transaction::TYPE_INVOICE)->unique();

        if ($tipos->count() > 1) {
            $this->addError('selected', __('No se pueden mezclar facturas y costos en la misma solicitud.'));

            return false;
        }

        $esCobro = $tipos->first() ?? $this->screen === 'invoice';

        if ($marcadas->pluck($esCobro ? 'customer' : 'vendor')->unique()->count() > 1) {
            $this->addError('selected', __($esCobro
                ? 'Todas las facturas de una solicitud tienen que ser del mismo cliente.'
                : 'Todos los costos de una solicitud tienen que ser del mismo proveedor.'));

            return false;
        }

        return true;
    }

    /**
     * Timbra en lote las facturas seleccionadas, como el botón «Seal» del
     * sistema viejo. Cada timbrado es una llamada real al PAC, así que se
     * procesan de a pocas por tanda y se informa una por una: las que se
     * timbraron y las que no, con su motivo (ya timbrada, sin conceptos, etc.).
     */
    public function stampSelected(StampTransaction $stamp): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        if (! $this->allowsStamping()) {
            return;
        }

        // Solo se timbran facturas al cliente: en la pantalla del booking los
        // costos marcados se dejan de lado.
        $facturas = Transaction::whereIn('transc_id', $this->selected)->where('tran_type', Transaction::TYPE_INVOICE);
        // Las que ya tienen sello se omiten en silencio: marcar una factura
        // timbrada es normal (para reenviarla), y no es un error que reportar.
        $yaTimbradas = (clone $facturas)->whereNotNull('seal')->where('seal', '<>', '')->count();
        $porTimbrar = $facturas->where(fn ($q) => $q->whereNull('seal')->orWhere('seal', ''));
        $totalFacturas = (clone $porTimbrar)->count();
        $lote = $porTimbrar->limit(self::STAMP_BATCH)->get();

        if ($lote->isEmpty()) {
            $this->addError('selected', $yaTimbradas > 0
                ? __('Las facturas marcadas ya están timbradas.')
                : __('Marca al menos una factura para timbrar.'));

            return;
        }

        // Cada factura es una llamada al PAC; puede tardar.
        set_time_limit(0);

        $done = 0;
        $errors = [];

        foreach ($lote as $factura) {
            try {
                $stamp->handle($factura);
                $done++;
            } catch (CfdiException $e) {
                $errors[$factura->tran_number ?: (string) $factura->transc_id] = $e->getMessage();
            }
        }

        $this->stampResult = [
            'done' => $done,
            'errors' => $errors,
            'pending' => max(0, $totalFacturas - $lote->count()),
            'skipped' => $yaTimbradas,
        ];

        $this->selected = [];
    }

    /**
     * Manda al cliente el PDF y el XML de las facturas marcadas: el «Send Docs»
     * del listado viejo (`actionReenviar`).
     */
    public function sendSelected(SendInvoice $enviar): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        if ($this->screen !== 'invoice') {
            return;
        }

        if ($this->selected === []) {
            $this->addError('selected', __('Marca al menos una factura para mandar.'));

            return;
        }

        $sent = 0;
        $errors = [];
        $unsealed = [];

        // Como el original, basta con que haya PDF: las facturas históricas
        // llevan el suyo cargado a mano y sin sello, y también se reenvían.
        // Se avisa cuáles salieron sin sello, pero no se detienen.
        foreach (Transaction::with('bookingModel')->whereIn('transc_id', array_slice($this->selected, 0, self::STAMP_BATCH))->get() as $factura) {
            $estado = $enviar->handle($factura, (string) ($factura->bookingModel?->booking_number ?? ''));
            $numero = $factura->tran_number ?: (string) $factura->transc_id;

            if ($estado !== SendInvoice::ENVIADA) {
                $errors[$numero] = SendInvoice::note($estado);

                continue;
            }

            $sent++;

            if (blank($factura->seal)) {
                $unsealed[] = $numero;
            }
        }

        $this->sendResult = ['sent' => $sent, 'errors' => $errors, 'unsealed' => $unsealed];
        $this->selected = [];
    }

    /**
     * Los filtros de la pantalla, tal como viajan en la dirección.
     *
     * @return array<string, mixed>
     */
    private function urlParams(): array
    {
        return array_filter([
            'num' => $this->tranNumber,
            'bk' => $this->bookingNumber,
            'q' => $this->appliedTo,
            'f' => $this->dates,
            'co' => $this->companyId,
            'ccy' => $this->accountId,
            'pago' => $this->paid,
            'canc' => $this->showCancelled,
            'cfdi' => $this->cfdiEstado,
            'tipo' => $this->docType,
            'ord' => $this->sort,
            'dir' => $this->direction,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /** Esta misma pantalla, con su filtro: a dónde volver desde otra sección. */
    public function currentUrl(): string
    {
        $ruta = match ($this->screen) {
            'bill' => 'transactions.bill',
            'all' => 'transactions.all',
            'booking' => 'transactions.booking',
            default => 'transactions.invoice',
        };

        $parametros = $this->urlParams();

        if ($this->screen === 'booking') {
            $parametros['booking'] = $this->bookingId;
        }

        return route($ruta, $parametros, absolute: false);
    }

    /**
     * Dirección de la descarga, con el MISMO filtro que se está viendo.
     *
     * Los nombres son los que la pantalla ya usa en la dirección, así que el
     * archivo trae exactamente la tabla de enfrente —y el enlace se puede
     * compartir igual que el de la pantalla.
     */
    public function exportUrl(): string
    {
        $parametros = $this->urlParams();

        if ($this->bookingId !== null) {
            $parametros['booking'] = $this->bookingId;
        }

        return route('transactions.export', ['screen' => $this->screen] + $parametros);
    }

    /**
     * ¿Esta pantalla permite marcar renglones para timbrar o solicitar pago?
     * También la de un booking real, como el `index.php` del original con
     * `mode == 10`; las cotizaciones no se cobran ni se timbran.
     */
    public function allowsSelection(): bool
    {
        return match ($this->screen) {
            'invoice', 'bill' => true,
            'booking' => ! ($this->booking()?->isQuotation() ?? true),
            default => false,
        };
    }

    /** ¿Se ofrece «Timbrar» aquí? En un booking, solo mientras siga abierto. */
    public function allowsStamping(): bool
    {
        return $this->screen === 'invoice'
            || ($this->screen === 'booking' && $this->allowsSelection() && ! ($this->booking()?->locked ?? true));
    }

    /**
     * Por qué este renglón no se puede marcar para una solicitud de pago, o
     * null si sí. El original deshabilitaba la casilla de los documentos ya
     * saldados (con importe y sin saldo); aquí también la de los cancelados,
     * que no tienen nada que cobrar ni pagar.
     */
    /**
     * Marca o desmarca todas las de la página, sin tocar las que no se pueden
     * marcar. Timbrar cincuenta facturas no puede empezar por cincuenta clics:
     * la rejilla del sistema original traía esta casilla en la cabecera.
     *
     * @param  iterable<object|array>  $rows
     */
    public function toggleAll(iterable $rows): void
    {
        // Desde el navegador los renglones llegan como arreglos.
        $marcables = collect($rows)
            ->map(fn ($row) => (object) $row)
            ->filter(fn ($row) => $this->unselectableReason($row) === null)
            ->pluck('transc_id')
            ->map(intval(...))
            ->all();

        $todasMarcadas = $marcables !== [] && array_diff($marcables, $this->selected) === [];

        $this->selected = $todasMarcadas
            ? array_values(array_diff($this->selected, $marcables))
            : array_values(array_unique([...$this->selected, ...$marcables]));
    }

    /** Factura que se está cancelando desde el listado, y el motivo elegido. */
    public ?int $cancelling = null;

    public string $cancelReason = '02';

    public string $replacementUuid = '';

    /**
     * Timbra una sola factura desde el listado.
     *
     * El lote sirve para la tanda del día; para una factura suelta, obligar a
     * marcarla y pulsar dos botones sobra. Es el botón por renglón que tenía la
     * rejilla del sistema original.
     */
    public function stampRow(int $transaccion, StampTransaction $stamp): void
    {
        abort_unless($this->allowsStamping() && (auth()->user()?->isAdmin() ?? false), 403);

        $factura = Transaction::findOrFail($transaccion);

        try {
            $stamp->handle($factura);
        } catch (CfdiException $e) {
            $this->addError('cfdi', $e->getMessage());

            return;
        }

        $this->stampResult = ['done' => 1, 'errors' => [], 'pending' => 0, 'skipped' => 0];
    }

    /** Abre la elección de motivo para cancelar esa factura. */
    public function startCancel(int $transaccion): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->cancelling = $transaccion;
        $this->cancelReason = '02';
        $this->replacementUuid = '';
        $this->resetErrorBag();
    }

    public function cancelCancel(): void
    {
        $this->cancelling = null;
        $this->resetErrorBag();
    }

    /**
     * Pide la cancelación ante el SAT sin salir del listado.
     *
     * El mensaje dice lo que de verdad contestó el PAC: casi nunca es
     * «cancelada», sino una solicitud que el receptor tiene que autorizar.
     */
    public function cancelRow(CancelStamp $cancelar): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        if ($this->cancelling === null) {
            return;
        }

        try {
            $resultado = $cancelar->handle(
                Transaction::findOrFail($this->cancelling),
                $this->cancelReason,
                $this->replacementUuid ?: null,
            );
        } catch (CfdiException $e) {
            $this->addError('cfdi', $e->getMessage());

            return;
        }

        session()->flash('status', $resultado->esCancelacionConfirmada()
            ? __('Factura cancelada ante el SAT.')
            : __($resultado->mensaje));
        session()->flash('status_detail', $resultado->codigo);

        $this->cancelling = null;
    }

    /**
     * Marca solo las facturas de la página que todavía no tienen sello.
     *
     * Las ya timbradas siguen siendo marcables a mano porque «Enviar
     * documentos» necesita justo esas: el PDF y el XML salen del timbrado. Lo
     * que no tiene sentido es marcarlas para volver a timbrar, y de eso se
     * encarga este botón.
     *
     * @param  iterable<object|array>  $rows
     */
    public function selectUnstamped(iterable $rows): void
    {
        $this->selected = collect($rows)
            ->map(fn ($row) => (object) $row)
            ->filter(fn ($row) => blank($row->seal ?? null)
                && (int) ($row->tran_type ?? Transaction::TYPE_INVOICE) === Transaction::TYPE_INVOICE
                && $this->unselectableReason($row) === null)
            ->pluck('transc_id')
            ->map(intval(...))
            ->values()
            ->all();

        if ($this->selected === []) {
            $this->addError('selected', __('No hay facturas sin timbrar en esta página.'));
        }
    }

    public function unselectableReason(object $row): ?string
    {
        if ($row->cancelled) {
            return __('Cancelada: no se timbra, no se envía y no entra en una solicitud de pago.');
        }

        /*
         * Que esté saldada solo estorba para cobrar o pagar. En Facturas lo que
         * se hace con lo marcado es timbrar y mandar documentos, y eso no tiene
         * nada que ver con si ya se cobró: apagar ahí la casilla dejaba la
         * pantalla entera sin poder marcar nada.
         */
        if ($this->screen === 'invoice') {
            return null;
        }

        $saldada = round((float) $row->amount_original, 2) !== 0.0
            && round((float) $row->left_to_pay, 2) === 0.0;

        return $saldada ? __('Ya está saldada: no queda nada por cobrar ni pagar.') : null;
    }

    /**
     * Tipos de documento que se ofrecen en el filtro «Tipo» de esta pantalla:
     * solo los que caben en su filtro base (en Facturas no se ofrece «Costo»).
     *
     * @return array<string, string> clave => etiqueta
     */
    public function typeOptions(): array
    {
        $delaPantalla = match ($this->screen) {
            'invoice' => [Transaction::TYPE_INVOICE],
            'bill' => [Transaction::TYPE_BILL, Transaction::TYPE_CREDIT_BILL],
            default => [],
        };

        return collect(TransactionFilters::documentTypes())
            ->filter(fn (array $definicion) => $delaPantalla === [] || in_array($definicion['type'], $delaPantalla, true))
            ->map(fn (array $definicion) => Transaction::typeLabel($definicion['invoice_type'], $definicion['type']))
            ->all();
    }

    private function booking(): ?Booking
    {
        return $this->bookingCache ??= ($this->bookingId ? Booking::find($this->bookingId) : null);
    }

    /** Utilidad por booking: facturas menos costos, a TC del documento y del pago. */
    public function calculateProfit(): void
    {
        $this->profit = (new ProfitByBooking($this->filters()))->summary();
    }

    /**
     * Parte del profit del booking que le toca a esta factura, repartido según
     * su subtotal, como la columna «Profit factura (doc)» del original. Es un
     * prorrateo: los costos son del booking completo. Null en los costos y en
     * las notas de crédito al cliente, que no entran en el ingreso.
     *
     * @param  array<string, mixed>|null  $profit
     */
    public function invoiceProfit(object $row, ?array $profit): ?float
    {
        $esIngreso = (int) $row->tran_type === Transaction::TYPE_INVOICE
            && (int) $row->invoice_type !== Transaction::INVOICE_TYPE_CREDIT;

        if ($profit === null || ! $esIngreso || (float) $profit['inv_doc'] == 0.0) {
            return null;
        }

        return $profit['profit_doc'] * abs((float) $row->amount_original_mxn) / $profit['inv_doc'];
    }

    /** Suma las columnas de dinero sobre el conjunto filtrado completo. */
    private function calculateTotals(): void
    {
        $this->totals = $this->query()->totals([
            'amount_original', 'sub_16_mxn', 'sub_0_mxn', 'tax_16_mxn', 'non_dec', 'tax_ret_mxn', 'total_amount',
            'total_amount_paid_tc', 'total_natural_amount', 'tran_paid_amount', 'left_to_pay',
        ]);
    }

    /**
     * La casilla «mostrar sumatoria» se recuerda en una cookie (un año), para
     * que quien la prende la encuentre puesta la próxima vez, en cualquier
     * pantalla del listado. Al apagarla se borra el pie.
     */
    public function updatedShowTotals(bool $value): void
    {
        cookie()->queue('mostrar_totales', $value ? '1' : '0', 60 * 24 * 365);

        if (! $value) {
            $this->totals = null;
        }
    }

    private function filters(): TransactionFilters
    {
        $filters = TransactionFilters::make([
            'tran_number' => $this->tranNumber ?: null,
            'booking_number' => $this->bookingNumber ?: null,
            'appliedTo' => $this->appliedTo ?: null,
            'dates' => $this->dates ?: null,
            'company_id' => $this->companyId !== '' ? (int) $this->companyId : null,
            'account' => $this->accountId !== '' ? (int) $this->accountId : null,
            'paid' => $this->paid !== '' ? (int) $this->paid : null,
            'showCancelled' => (int) $this->showCancelled,
            'cfdiEstado' => $this->cfdiEstado ?: null,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ]);

        // Cada pantalla es el mismo motor con otro filtro base, igual que las
        // acciones actionInvoice / actionBill / actionAll / actionIndex de Yii2.
        // El motor filtra por `booking.mode`, así que la pantalla de un booking
        // tiene que decirle si es cotización (modo 9): si no, salía vacía.
        match ($this->screen) {
            'invoice' => $filters->type = [0],
            'bill' => [$filters->type = [1, 2], $filters->paymentMode = true],
            'booking' => [
                $filters->booking = $this->bookingId,
                $filters->showQuatation = $this->booking()?->isQuotation() ?? false,
            ],
            default => null,
        };

        // Va después del filtro base porque solo lo estrecha.
        $filters->applyDocumentType($this->docType ?: null);

        return $filters;
    }

    private function query(): TransactionQuery
    {
        return TransactionQuery::make($this->filters());
    }

    public function render()
    {
        $start = microtime(true);
        $rows = $this->query()->paginate($this->perPage, $this->getPage());
        $this->queryMs = round((microtime(true) - $start) * 1000, 1);

        // Con la sumatoria encendida, el pie se calcula en cada pintada para que
        // siga al filtro que se esté viendo.
        if ($this->showTotals) {
            $this->calculateTotals();
        } else {
            $this->totals = null;
        }

        return view('livewire.transactions.transaction-table', [
            'rows' => $rows,
            // Columna CFDI: qué facturas de esta página tienen una cancelación
            // pedida y aún sin consumar. Una consulta chica por los IDs de la
            // página, en vez de meter otra unión en el motor de listados.
            'cancelaciones' => CfdiCancelacion::query()
                ->whereIn('transc_id', collect($rows->items())->pluck('transc_id')->all())
                ->get()
                ->keyBy('transc_id'),
            'companies' => Company::options(),
            'currencies' => Account::options(),
            'booking' => $this->booking(),
            // Columna «Solicitud» de Costos: qué solicitud de pago pidió cada costo.
            'requestNumbers' => $this->screen === 'bill' ? Transaction::requestNumbersFor($rows->items()) : [],
            // Columna «Utilidad del booking» de Costos: de un costo interesa si
            // el booking al que pertenece deja dinero. Una consulta por página.
            'bookingProfits' => $this->screen === 'bill'
                ? (new ProfitByBooking($this->filters()))->forBookings(
                    collect($rows->items())->pluck('booking_id')->map(fn ($id) => (int) $id)->all()
                )
                : [],
            // En la pantalla de un booking la utilidad va siempre, como en el
            // original: es una sola consulta chica.
            'bookingProfit' => $this->screen === 'booking'
                ? ((new ProfitByBooking($this->filters()))->summary()['rows'][0] ?? null)
                : null,
        ])->layout('components.app-layout', ['title' => $this->title()]);
    }

    public function title(): string
    {
        return match ($this->screen) {
            'invoice' => __('Facturas'),
            'bill' => __('Costos'),
            'booking' => __('Transacciones del booking'),
            default => __('Todas las transacciones'),
        };
    }
}
