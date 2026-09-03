<?php

namespace App\Livewire\Payments;

use App\Models\Core\Bank;
use App\Models\Core\PaymentByTransaction;
use App\Models\Core\PaymentRequest;
use App\Queries\PaymentRequestFilters;
use App\Queries\PaymentRequestQuery;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Collection;
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

    /** Número de la solicitud. Coincidencia EXACTA, como el filtro del original. */
    #[Url(as: 'num', except: '')]
    public string $number = '';

    #[Url(as: 'n', except: 50)]
    public int $perPage = 50;

    /** Solicitud desplegada, para ver sus transacciones. */
    public ?int $expanded = null;

    /**
     * La solicitud que se acaba de crear, para señalarla en verde.
     *
     * No va en la dirección a propósito: es «la de este momento». Se recibe una
     * sola vez al llegar del formulario y se apaga en cuanto el usuario toca
     * cualquier cosa.
     */
    public ?int $highlight = null;

    public float $queryMs = 0;

    /**
     * Livewire solo inyecta en `mount()` los parámetros de la RUTA; los de la
     * cadena de consulta hay que leerlos de la petición.
     */
    public function mount(): void
    {
        $nueva = request()->query('nueva');

        $this->highlight = is_numeric($nueva) ? (int) $nueva : null;
    }

    public function updated(string $property): void
    {
        if ($property === 'page') {
            return;
        }

        $this->resetPage();
        $this->expanded = null;
        $this->highlight = null;
    }

    public function clearFilters(): void
    {
        $this->reset(['type', 'bankId', 'paid', 'dates', 'number', 'expanded', 'highlight']);
        $this->resetPage();
    }

    public function toggle(int $requestId): void
    {
        $this->expanded = $this->expanded === $requestId ? null : $requestId;
        $this->highlight = null;
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
    public function markPaid(int $requestId): void
    {
        $this->assertAdmin();

        $solicitud = PaymentRequest::findOrFail($requestId);

        abort_if((bool) $solicitud->paid, 422, __('La solicitud ya está pagada.'));

        abort_if(
            PaymentByTransaction::where('request_id', $requestId)->doesntExist(),
            422,
            __('La solicitud no tiene transacciones.'),
        );

        $solicitud->forceFill(['paid' => 1, 'opened' => 0])->save();

        $this->highlight = null;
        session()->flash('status', __('Solicitud ').$this->folio($solicitud->request_id).' marcada como pagada.');
    }

    /** Vuelve a abrir una solicitud pagada, para corregirla. */
    public function reopen(int $requestId): void
    {
        $this->assertAdmin();

        PaymentRequest::findOrFail($requestId)->forceFill(['paid' => 0, 'opened' => 1])->save();

        $this->highlight = null;
        session()->flash('status', __('Solicitud ').$this->folio($requestId).' reabierta.');
    }

    /**
     * Borra la solicitud y suelta las transacciones que agrupaba.
     *
     * El original borraba solo la solicitud y dejaba comentado el `unpay()` de
     * cada transacción, así que los renglones de pago quedaban huérfanos y las
     * transacciones seguían apareciendo como cobradas. Aquí se borran los
     * renglones también: una solicitud que ya no existe no puede seguir pagando.
     */
    public function delete(int $requestId): void
    {
        $this->assertAdmin();

        $solicitud = PaymentRequest::findOrFail($requestId);

        abort_if((bool) $solicitud->paid, 422, __('Una solicitud pagada no se borra: primero hay que reabrirla.'));

        PaymentByTransaction::where('request_id', $requestId)->delete();
        $solicitud->delete();

        $this->expanded = null;
        $this->highlight = null;
        session()->flash('status', __('Solicitud ').$this->folio($requestId).' borrada.');
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
        $filtros = PaymentRequestFilters::make([
            'bank_id' => $this->bankId !== '' ? (int) $this->bankId : null,
            'type' => $this->type !== '' ? (int) $this->type : null,
            'dates' => $this->dates ?: null,
            'number' => trim($this->number) ?: null,
        ]);

        if ($this->paid !== '') {
            $filtros->paid = (int) $this->paid;
        }

        return $filtros;
    }

    /** Transacciones que agrupa la solicitud desplegada. */
    private function transactions(): Collection
    {
        if ($this->expanded === null) {
            return collect();
        }

        $filtros = TransactionFilters::make(['request_id' => $this->expanded]);
        $filtros->paymentMode = true;
        $filtros->noExchange = true;

        return TransactionQuery::make($filtros)->get();
    }

    public function render()
    {
        $inicio = microtime(true);
        $filas = PaymentRequestQuery::make($this->filters())->paginate($this->perPage, $this->getPage());
        $this->queryMs = round((microtime(true) - $inicio) * 1000, 1);

        return view('livewire.payments.payment-request-list', [
            'filas' => $filas,
            'transacciones' => $this->transactions(),
            'banks' => Bank::options(),
        ])->layout('components.app-layout', ['title' => __('Solicitudes de pago')]);
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }
}
