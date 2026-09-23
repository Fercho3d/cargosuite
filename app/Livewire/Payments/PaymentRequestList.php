<?php

namespace App\Livewire\Payments;

use App\Actions\Payments\RecalculatePaidColumns;
use App\Models\Core\Bank;
use App\Models\Core\Client;
use App\Models\Core\PaymentByTransaction;
use App\Models\Core\PaymentRequest;
use App\Models\Core\Provider;
use App\Queries\PaymentRequestFilters;
use App\Queries\PaymentRequestQuery;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Solicitudes de pago: el documento que agrupa varias transacciones para
 * cobrarlas o pagarlas juntas.
 *
 * Equivale a `actionIndex` del `PaymentRequestController` de Yii2, más las
 * acciones de marcar como pagada, reabrir y borrar.
 */
class PaymentRequestList extends Component
{
    use WithPagination;

    /** '' = todas, 1 = cobros a cliente, 2 = pagos a proveedor. */
    #[Url(as: 'tipo', except: '')]
    public string $type = '';

    #[Url(as: 'banco', except: '')]
    public string $bankId = '';

    /** '' = todas, 0 = pendientes, 1 = pagadas. */
    #[Url(as: 'estado', except: '')]
    public string $paid = '';

    #[Url(as: 'f', except: '')]
    public string $dates = '';

    /**
     * Sin rango de fechas a propósito. Sin esto, la pantalla vacía arranca en
     * el año en curso (ver `mount()`), y al volver del detalle o recargar se
     * perdería el «Ver todos los años».
     */
    #[Url(as: 'todos', except: false)]
    public bool $allYears = false;

    /** Folio (ID) de la solicitud: se acepta con o sin ceros a la izquierda. */
    #[Url(as: 'folio', except: '')]
    public string $folioId = '';

    /** Número de la solicitud. Coincidencia EXACTA, como el filtro del original. */
    #[Url(as: 'num', except: '')]
    public string $number = '';

    #[Url(as: 'n', except: 50)]
    public int $perPage = 50;

    /*
     * Filtros que llegan desde los reportes de cobros y pagos al abrir un
     * renglón: el cliente y el proveedor (con su divisa). No tienen campo
     * propio; se quitan con «Limpiar filtros».
     */
    #[Url(as: 'cliente', except: '')]
    public string $clientId = '';

    #[Url(as: 'proveedor', except: '')]
    public string $providerId = '';

    #[Url(as: 'divisa', except: '')]
    public string $currencyId = '';

    /**
     * Fecha con la que se revalúa lo pagado («dd/mm/aaaa»); de ella salen
     * «TC pago», «Total a pagar» y «Diferencia». Hoy por omisión, como el
     * `date_pay` del `actionIndex` original; los reportes la mandan con la suya.
     */
    #[Url(as: 'tc', except: '')]
    public string $datePay = '';

    /** Reporte del que se llegó, para regresar a él con su filtro. */
    #[Url(as: 'volver', except: '')]
    public string $volver = '';

    /**
     * La solicitud que se acaba de crear, para señalarla en verde.
     *
     * No va en la dirección a propósito: es «la de este momento». Se recibe una
     * sola vez al llegar del formulario y se apaga en cuanto el usuario toca
     * cualquier cosa.
     */
    public ?int $highlight = null;

    /** Switch «mostrar sumatoria»; comparte la cookie con el listado de transacciones. */
    public bool $showTotals = false;

    public float $queryMs = 0;

    /**
     * Livewire solo inyecta en `mount()` los parámetros de la RUTA; los de la
     * cadena de consulta hay que leerlos de la petición.
     */
    public function mount(): void
    {
        $nueva = request()->query('nueva');

        $this->highlight = is_numeric($nueva) ? (int) $nueva : null;
        $this->showTotals = request()->cookie('mostrar_totales') === '1';

        // Solo se regresa a direcciones propias del sistema.
        if (! str_starts_with($this->volver, '/') || str_starts_with($this->volver, '//')) {
            $this->volver = '';
        }

        // Como en transacciones: arranca en el año en curso para que «Ver todas»
        // no traiga años de historia de golpe y se quede sin memoria.
        if ($this->dates === '' && ! $this->allYears) {
            $this->dates = now()->startOfYear()->format('d/m/Y').' - '.now()->format('d/m/Y');
        }

        if ($this->datePay === '') {
            $this->datePay = now()->format('d/m/Y');
        }
    }

    public function updatedShowTotals(bool $value): void
    {
        cookie()->queue('mostrar_totales', $value ? '1' : '0', 60 * 24 * 365);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['page', 'showTotals'], true)) {
            return;
        }

        $this->resetPage();
        $this->highlight = null;

        // Borrar el rango a mano también es «todos los años».
        if ($property === 'dates') {
            $this->allYears = $this->dates === '';
        }
    }

    /** «Ver todas»: el filtro completo en una sola página, como en transacciones. */
    public function verTodas(): void
    {
        $this->perPage = 100000;
        $this->resetPage();
    }

    /** Quita el rango de fechas: toda la historia, no solo el año en curso. */
    public function verTodosLosAnios(): void
    {
        $this->dates = '';
        $this->allYears = true;
        $this->resetPage();
    }

    /** Deja la pantalla sin ningún filtro, tampoco el rango de fechas. */
    public function clearFilters(): void
    {
        $this->reset(['type', 'bankId', 'paid', 'folioId', 'number', 'highlight', 'clientId', 'providerId', 'currencyId']);
        $this->verTodosLosAnios();
        $this->datePay = now()->format('d/m/Y');
    }

    /**
     * La lista con su filtro: a dónde vuelve el detalle. Se arma con los filtros
     * y no con la dirección de la petición, que tras cambiar un filtro es la de
     * Livewire (`/livewire-…/update`) y no la de la pantalla.
     */
    public function currentUrl(): string
    {
        return route('payments.requests', array_filter([
            'tipo' => $this->type,
            'banco' => $this->bankId,
            'estado' => $this->paid,
            'f' => $this->dates,
            'todos' => $this->allYears ? '1' : null,
            'folio' => $this->folioId,
            'num' => $this->number,
            'n' => $this->perPage === 50 ? null : $this->perPage,
            'cliente' => $this->clientId,
            'proveedor' => $this->providerId,
            'divisa' => $this->currencyId,
            'tc' => $this->datePay,
            'volver' => $this->volver,
        ], fn ($valor) => $valor !== null && $valor !== ''), absolute: false);
    }

    // ------------------------------------------------------------ Acciones

    /**
     * Marca la solicitud como pagada.
     *
     * En Yii2 esta acción además recorría las transacciones llamando a un método
     * `paid()` que **no existe en el modelo**: Yii lo convierte en
     * `UnknownMethodException`, el `catch` la atrapa y la pantalla responde con un
     * error, aunque la solicitud ya quedó marcada. Como los renglones de pago ya
     * nacen con `paid = 1` desde que se crea la solicitud, ese recorrido no tenía
     * nada que hacer: aquí se marca la solicitud y ya.
     */
    public function markPaid(int $requestId, RecalculatePaidColumns $recalc): void
    {
        $this->assertAdmin();

        $solicitud = PaymentRequest::findOrFail($requestId);

        // Los motivos de negocio se muestran arriba del listado (`@error('markPaid')`);
        // el `abort` queda solo para los permisos.
        $motivo = match (true) {
            (bool) $solicitud->paid => __('La solicitud ya está pagada.'),
            PaymentByTransaction::where('request_id', $requestId)->doesntExist() => __('La solicitud no tiene transacciones.'),
            default => null,
        };

        if ($motivo !== null) {
            $this->addError('markPaid', $motivo);

            return;
        }

        $solicitud->forceFill(['paid' => 1, 'opened' => 0])->save();
        $recalc->forRequest($requestId);

        $this->highlight = null;
        session()->flash('status', __('Solicitud :folio marcada como pagada.', ['folio' => $this->folio($solicitud->request_id)]));
    }

    /** Vuelve a abrir una solicitud pagada, para corregirla. */
    public function reopen(int $requestId, RecalculatePaidColumns $recalc): void
    {
        $this->assertAdmin();

        PaymentRequest::findOrFail($requestId)->forceFill(['paid' => 0, 'opened' => 1])->save();
        $recalc->forRequest($requestId);

        $this->highlight = null;
        session()->flash('status', __('Solicitud :folio reabierta.', ['folio' => $this->folio($requestId)]));
    }

    /**
     * Borra la solicitud y suelta las transacciones que agrupaba. Solo el super
     * administrador, como en el original.
     *
     * El original borraba solo la solicitud y dejaba comentado el `unpay()` de
     * cada transacción, así que los renglones de pago quedaban huérfanos y las
     * transacciones seguían apareciendo como cobradas. Aquí se borran los
     * renglones también: una solicitud que ya no existe no puede seguir pagando.
     */
    public function delete(int $requestId, RecalculatePaidColumns $recalc): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $solicitud = PaymentRequest::findOrFail($requestId);

        if ($solicitud->paid) {
            $this->addError('markPaid', __('Una solicitud pagada no se borra: primero hay que reabrirla.'));

            return;
        }

        $transacciones = PaymentByTransaction::where('request_id', $requestId)->pluck('transc_id')->all();
        PaymentByTransaction::where('request_id', $requestId)->delete();
        $solicitud->delete();
        $recalc->handle($transacciones);

        $this->highlight = null;
        session()->flash('status', __('Solicitud :folio borrada.', ['folio' => $this->folio($requestId)]));
    }

    public function folio(int|string|null $requestId): string
    {
        return str_pad((string) $requestId, 4, '0', STR_PAD_LEFT);
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    // ------------------------------------------------------------ Consulta

    private function filters(): PaymentRequestFilters
    {
        $folio = (int) ltrim($this->folioId, " 0\t");

        $filtros = PaymentRequestFilters::make([
            'request_id' => $folio ?: null,
            'bank_id' => $this->bankId !== '' ? (int) $this->bankId : null,
            'type' => $this->type !== '' ? (int) $this->type : null,
            // El folio es único: se busca en cualquier fecha.
            'dates' => $folio ? null : ($this->dates ?: null),
            // Sin `?:`: el número de cheque «0» también se busca.
            'number' => trim($this->number) !== '' ? trim($this->number) : null,
            'client_id' => $this->clientId !== '' ? (int) $this->clientId : null,
            'provider_id' => $this->providerId !== '' ? (int) $this->providerId : null,
            'currency_id' => $this->currencyId !== '' ? (int) $this->currencyId : null,
            // Una fecha a medio teclear no revalúa, en vez de tirar la consulta.
            'date_pay' => preg_match('~^\d{1,2}/\d{1,2}/\d{4}$~', trim($this->datePay)) ? trim($this->datePay) : null,
        ]);

        if ($this->paid !== '') {
            $filtros->paid = (int) $this->paid;
        }

        return $filtros;
    }

    public function render()
    {
        $inicio = microtime(true);
        $consulta = PaymentRequestQuery::make($this->filters());
        $filas = $consulta->paginate($this->perPage, $this->getPage());
        $totals = $this->showTotals ? $consulta->totals([
            'sub_0_paid', 'sub_16_paid', 'tax_16_paid', 'non_dec', 'tax_ret_paid',
            'total_paid', 'total_to_pay', 'diference',
        ]) : null;
        $this->queryMs = round((microtime(true) - $inicio) * 1000, 1);

        return view('livewire.payments.payment-request-list', [
            'filas' => $filas,
            'totals' => $totals,
            'banks' => Bank::options(),
            // Nombre de quien filtra el reporte de origen, para decir qué se ve.
            'contraparte' => match (true) {
                $this->clientId !== '' => Client::whereKey($this->clientId)->value('fullName'),
                $this->providerId !== '' => Provider::whereKey($this->providerId)->value('fullName'),
                default => null,
            },
        ])->layout('components.app-layout', ['title' => __('Solicitudes de pago')]);
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }
}
