<?php

namespace App\Livewire\Transactions;

use App\Actions\Transactions\CancelStamp;
use App\Actions\Transactions\SendInvoice;
use App\Actions\Transactions\StampTransaction;
use App\Models\Core\Charge;
use App\Models\Core\ChargeType;
use App\Models\Core\Service;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\Cfdi\CfdiException;
use App\Support\TransactionLock;
use Illuminate\Support\Collection;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Detalle de una transacción: encabezado, conceptos y desglose de impuestos.
 *
 * Equivale a `actionView` del `TransactionController` de Yii2 más el
 * `ChargeController` completo, que allá era un modal aparte. Al vivir en el mismo
 * componente, los totales se recalculan solos al agregar o quitar un concepto.
 *
 * El encabezado se lee del mismo motor que alimenta los listados (igual que
 * `findFullModel`), para que los importes cuadren al centavo con la tabla y no se
 * recalculen con otra fórmula.
 */
class TransactionDetail extends Component
{
    public int $transactionId;

    // --- Formulario de concepto ---
    public bool $editingCharge = false;

    public ?int $chargeId = null;

    public string $chargeType = '';

    public string $serviceId = '';

    public string $quantity = '1';

    public string $price = '';

    /** Formulario de cancelación de CFDI. */
    public bool $cancelling = false;

    public string $cancelReason = '02';

    public string $replacementUuid = '';

    private ?object $headerCache = null;

    private ?Transaction $transactionCache = null;

    public function mount(int $transaction): void
    {
        $this->transactionId = $transaction;
    }

    // ------------------------------------------------------------ Lectura

    /**
     * Fila calculada del encabezado. `showCancelled = 1` para que una transacción
     * cancelada siga siendo consultable, y si no aparece se reintenta en modo
     * cotización: el motor filtra por `booking.mode` y las cotizaciones (modo 9)
     * quedan fuera del modo normal.
     */
    private function header(): object
    {
        if ($this->headerCache !== null) {
            return $this->headerCache;
        }

        foreach ([false, true] as $cotizacion) {
            $filtros = TransactionFilters::make([
                'tran_in' => [$this->transactionId],
                'showCancelled' => 1,
            ]);
            $filtros->showQuatation = $cotizacion;

            $fila = TransactionQuery::make($filtros)->get(1)->first();

            if ($fila !== null) {
                return $this->headerCache = $fila;
            }
        }

        throw new NotFoundHttpException(__('No existe la transacción ').$this->transactionId);
    }

    private function transaction(): Transaction
    {
        return $this->transactionCache ??= Transaction::with([
            'bookingModel', 'currency', 'company', 'client', 'provider',
        ])->findOrFail($this->transactionId);
    }

    private function lock(): TransactionLock
    {
        return TransactionLock::evaluate(
            $this->header(),
            (bool) ($this->transaction()->bookingModel?->locked ?? false),
            auth()->user(),
        );
    }

    /** @return Collection<int, Charge> */
    private function charges(): Collection
    {
        return Charge::with('chargeType')
            ->where('transaction', $this->transactionId)
            ->orderBy('charge_id')
            ->get();
    }

    /**
     * Contraparte del documento: el cliente si es factura, el proveedor si es
     * costo. Es la que decide qué servicios se pueden cobrar.
     */
    private function party(): array
    {
        $transaccion = $this->transaction();

        return (int) $transaccion->tran_type === Transaction::TYPE_INVOICE
            ? [(int) $transaccion->customer, Service::TYPE_CLIENT]
            : [(int) $transaccion->vendor, Service::TYPE_PROVIDER];
    }

    // ------------------------------------------------------ Conceptos

    /** Ningún concepto se toca si la transacción está bloqueada. */
    private function assertEditable(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        abort_if($this->lock()->locked, 403, __('La transacción está bloqueada.'));
    }

    public function addCharge(): void
    {
        $this->assertEditable();
        $this->resetChargeForm();
        $this->editingCharge = true;
    }

    public function editCharge(int $charge): void
    {
        $this->assertEditable();

        $modelo = Charge::where('transaction', $this->transactionId)->findOrFail($charge);

        $this->chargeId = $modelo->charge_id;
        $this->chargeType = (string) $modelo->type;
        $this->serviceId = (string) $modelo->service_id;
        $this->quantity = (string) ($modelo->quantity ?? 1);
        $this->price = (string) ($modelo->price ?? 0);
        $this->editingCharge = true;
    }

    /** Al cambiar el tipo de cargo, el servicio anterior deja de ser válido. */
    public function updatedChargeType(): void
    {
        $this->serviceId = '';
        $this->price = '';
    }

    /**
     * El precio lo pone el catálogo cuando el servicio lo tiene pactado; si el
     * servicio vale 0, es un precio abierto y lo captura el usuario.
     */
    public function updatedServiceId(): void
    {
        $servicio = $this->serviceId === '' ? null : Service::find($this->serviceId);

        $this->price = (string) ($servicio?->price ?? '');
    }

    public function priceIsFixed(): bool
    {
        $servicio = $this->serviceId === '' ? null : Service::find($this->serviceId);

        return $servicio !== null && (float) $servicio->price !== 0.0;
    }

    public function saveCharge(): void
    {
        $this->assertEditable();

        $datos = $this->validate([
            'chargeType' => ['required', 'exists:charge_type,charge_type_id'],
            'serviceId' => ['required', 'exists:service,service_id'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
        ], attributes: [
            'chargeType' => __('tipo de cargo'),
            'serviceId' => 'servicio',
            'quantity' => 'cantidad',
            'price' => 'precio',
        ]);

        $servicio = Service::findOrFail($datos['serviceId']);

        $modelo = $this->chargeId === null
            ? new Charge(['transaction' => $this->transactionId])
            : Charge::where('transaction', $this->transactionId)->findOrFail($this->chargeId);

        $modelo->fill([
            'type' => (int) $datos['chargeType'],
            'service_id' => (int) $datos['serviceId'],
            // La descripción viene del catálogo. En Yii2 solo se copiaba al crear
            // y al editar costos; al editar una factura quedaba la descripción
            // vieja aunque cambiara el servicio. Aquí se copia siempre.
            'description' => $servicio->description,
            'quantity' => (float) $datos['quantity'],
            'price' => (float) $datos['price'],
        ])->save();

        $this->resetChargeForm();
        $this->refreshHeader();
    }

    public function deleteCharge(int $charge): void
    {
        $this->assertEditable();

        Charge::where('transaction', $this->transactionId)->findOrFail($charge)->delete();

        $this->resetChargeForm();
        $this->refreshHeader();
    }

    /**
     * Borra la transacción completa. Los conceptos se van con ella por la llave
     * foránea (`ON DELETE CASCADE`); las condiciones las decide `TransactionLock`.
     */
    public function deleteTransaction(): void
    {
        $transaccion = $this->transaction();
        $booking = (int) $transaccion->booking;

        abort_unless(
            TransactionLock::canDelete(
                $this->header(),
                (bool) ($transaccion->bookingModel?->locked ?? false),
                auth()->user(),
            ),
            403,
            __('Esta transacción no se puede borrar.'),
        );

        $transaccion->delete();

        session()->flash('status', __('Transacción borrada.'));
        $this->redirectRoute('transactions.booking', $booking, navigate: true);
    }

    public function cancelChargeEdit(): void
    {
        $this->resetChargeForm();
    }

    private function resetChargeForm(): void
    {
        $this->reset(['editingCharge', 'chargeId', 'chargeType', 'serviceId', 'price']);
        $this->quantity = '1';
        $this->resetErrorBag();
    }

    /** Los totales del encabezado cambian con cada concepto. */
    private function refreshHeader(): void
    {
        $this->headerCache = null;
    }

    // ------------------------------------------------------------- CFDI

    /** ¿Esta factura se puede timbrar? Sin entrar al PAC: solo lo que se ve aquí. */
    public function canStamp(): bool
    {
        $transaccion = $this->transaction();

        return config('timbrado.habilitado')
            && (auth()->user()?->isAdmin() ?? false)
            && (int) $transaccion->tran_type === Transaction::TYPE_INVOICE
            && (int) $transaccion->invoice_type !== Transaction::INVOICE_TYPE_HISTORY
            && blank($transaccion->seal)
            && ! $transaccion->cancelled
            && $this->charges()->isNotEmpty();
    }

    public function canCancel(): bool
    {
        $transaccion = $this->transaction();

        // La cancelación sí se permite con el timbrado apagado si hay sello:
        // una instalación que dejó de facturar al SAT todavía puede tener que
        // cancelar lo que timbró antes.
        return (auth()->user()?->isAdmin() ?? false)
            && filled($transaccion->seal)
            && ! $transaccion->cancelled;
    }

    public function stamp(StampTransaction $timbrar): void
    {
        abort_unless($this->canStamp(), 403);

        try {
            $uuid = $timbrar->handle($this->transaction());
        } catch (CfdiException $e) {
            $this->addError('cfdi', $e->getMessage());

            return;
        }

        // Los cachés son propiedades privadas de esta petición, no estado de
        // Livewire: se limpian a mano.
        $this->transactionCache = null;
        $this->headerCache = null;

        session()->flash('status', __('Factura timbrada. Folio fiscal: ').$uuid.' '.$this->mailNote($timbrar->mailStatus));
        $this->redirectRoute('transactions.show', $this->transactionId, navigate: true);
    }

    /**
     * Vuelve a mandarle al cliente la factura timbrada.
     *
     * Es `actionReenviar` de Yii2, que allá era una acción del listado sobre
     * varias facturas a la vez; aquí vive donde se ve el documento.
     */
    public function resend(SendInvoice $enviar): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $estado = $enviar->handle($this->transaction(), (string) ($this->header()->booking_number ?? ''));

        session()->flash('status', trim($this->mailNote($estado)));
    }

    /** Cómo contarle al usuario qué pasó con el correo. */
    private function mailNote(?string $estado): string
    {
        return match ($estado) {
            SendInvoice::ENVIADA => __('La factura se le mandó al cliente.'),
            SendInvoice::SIN_DOCUMENTOS => __('No se mandó por correo: la factura todavía no tiene documentos.'),
            SendInvoice::SIN_DESTINATARIOS => __('No se mandó por correo: el cliente no tiene correos de notificación.'),
            SendInvoice::ERROR => __('No se pudo mandar por correo; quedó anotado en la bitácora.'),
            default => '',
        };
    }

    public function startCancel(): void
    {
        abort_unless($this->canCancel(), 403);

        $this->cancelling = true;
        $this->resetErrorBag();
    }

    public function cancelStamp(CancelStamp $cancelar): void
    {
        abort_unless($this->canCancel(), 403);

        try {
            $cancelar->handle(
                $this->transaction(),
                $this->cancelReason,
                $this->replacementUuid ?: null,
            );
        } catch (CfdiException $e) {
            $this->addError('cfdi', $e->getMessage());

            return;
        }

        session()->flash('status', __('Factura cancelada ante el SAT.'));
        $this->redirectRoute('transactions.show', $this->transactionId, navigate: true);
    }

    // --------------------------------------------------------- Pintado

    public function render()
    {
        [$contraparte, $tipoServicio] = $this->party();

        $transaccion = $this->transaction();

        return view('livewire.transactions.transaction-detail', [
            'fila' => $this->header(),
            'transaccion' => $transaccion,
            'cargos' => $this->charges(),
            'candado' => $this->lock(),
            'sePuedeBorrar' => TransactionLock::canDelete(
                $this->header(),
                (bool) ($transaccion->bookingModel?->locked ?? false),
                auth()->user(),
            ),
            'motivosCancelacion' => CancelStamp::MOTIVOS,
            'tiposDeCargo' => ChargeType::optionsFor($contraparte, $tipoServicio),
            'servicios' => $this->chargeType === ''
                ? collect()
                : Service::optionsFor((int) $this->chargeType, $contraparte, $tipoServicio),
        ])->layout('components.app-layout', [
            'title' => trim((string) $transaccion->tran_number) ?: __('Transacción ').$this->transactionId,
        ]);
    }
}
