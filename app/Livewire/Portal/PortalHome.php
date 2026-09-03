<?php

namespace App\Livewire\Portal;

use App\Actions\Portal\RequestVendorPayment;
use App\Models\Core\Account;
use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Models\Core\Transaction;
use App\Queries\BookingFilters;
use App\Queries\BookingQuery;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Portal del cliente y del proveedor.
 *
 * En el sistema actual esto vive en una aplicación aparte que comparte la base;
 * aquí es una sección más, con la misma sesión y los mismos datos, pero acotada
 * a lo que le toca ver a quien entra.
 *
 * **La acotación no es cosmética.** Todas las consultas se filtran por el cliente
 * o el proveedor de la cuenta, que sale de la sesión y nunca de la petición: no
 * hay forma de pedir los documentos de otro cambiando un parámetro.
 */
class PortalHome extends Component
{
    use WithPagination;

    /** documentos | embarques */
    #[Url(as: 'ver', except: 'documentos')]
    public string $tab = 'documentos';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Documentos marcados por el proveedor para pedir su pago.
     *
     * @var int[]
     */
    public array $selected = [];

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['tab', 'search'], true)) {
            return;
        }

        $this->resetPage();
        $this->selected = [];
    }

    /** Solo el proveedor agrupa documentos para pedir su pago. */
    public function allowsSelection(): bool
    {
        return ! $this->isClient() && $this->tab === 'documentos';
    }

    /**
     * El proveedor pide que le paguen lo que ya comprobó.
     *
     * Las reglas viven en la acción; aquí solo se recoge el error para
     * enseñarlo. El identificador del proveedor sale de la sesión.
     */
    public function requestPayment(RequestVendorPayment $pedir): void
    {
        abort_if($this->isClient(), 403);

        $solicitud = $pedir->handle(
            array_map('intval', $this->selected),
            (int) auth()->user()->portalProviderId(),
        );

        $this->selected = [];

        session()->flash('status', __('Pedimos el pago de tus documentos. Solicitud ')
            .str_pad((string) $solicitud->request_id, 4, '0', STR_PAD_LEFT).'.');
    }

    /**
     * El cliente da por recibida su factura.
     *
     * Porta `actionSetProcessed`: se marca UNA vez y no se desmarca —el
     * original tiene comentado justamente el interruptor que lo permitía—, y
     * solo sobre facturas propias.
     */
    public function markProcessed(int $transactionId): void
    {
        $clienteId = auth()->user()?->portalClientId();

        abort_if($clienteId === null, 403);

        $factura = Transaction::where('transc_id', $transactionId)
            ->where('customer', $clienteId)
            ->first();

        abort_if($factura === null, 404);

        $factura->forceFill(['processed' => 1])->save();

        session()->flash('status', __('Factura dada por recibida.'));
    }

    public function isClient(): bool
    {
        return auth()->user()?->portalClientId() !== null;
    }

    public function partyName(): string
    {
        $usuario = auth()->user();

        return $this->isClient()
            ? (string) ($usuario->client_id ? Client::find($usuario->client_id)?->fullName : '')
            : (string) ($usuario->provider_id ? Provider::find($usuario->provider_id)?->fullName : '');
    }

    /** Acotado a quien entra: el cliente o el proveedor sale de la SESIÓN. */
    private function filters(): TransactionFilters
    {
        $usuario = auth()->user();

        $filtros = TransactionFilters::make([
            'tran_number' => $this->search ?: null,
            'showCancelled' => 1,
        ]);

        if (($clienteId = $usuario->portalClientId()) !== null) {
            $filtros->customer = $clienteId;
            $filtros->type = [0];
        } else {
            $filtros->vendor = $usuario->portalProviderId();
            $filtros->type = [1, 2];
            $filtros->paymentMode = true;
        }

        return $filtros;
    }

    /** Facturas del cliente, o costos del proveedor. */
    private function documents(): LengthAwarePaginator
    {
        return TransactionQuery::make($this->filters())->paginate(25, $this->getPage());
    }

    /**
     * Suma de lo que hay en el listado, UNA LÍNEA POR DIVISA.
     *
     * Sumar pesos con dólares no significa nada, y estas dos columnas
     * (`total_natural_amount` y `left_to_pay`) vienen en la moneda del propio
     * documento, sin convertir. Por eso se pregunta divisa por divisa —son dos o
     * tres— en vez de sacar un único número que engañaría.
     *
     * Es la suma del FILTRO completo, no la de la página.
     *
     * @return array<int, array{divisa: string, total: float, saldo: float, documentos: int}>
     */
    private function totalsByCurrency(): array
    {
        $lineas = [];

        foreach (Account::options() as $id => $prefijo) {
            $filtros = $this->filters();
            $filtros->account = (int) $id;

            $consulta = TransactionQuery::make($filtros);
            $suma = $consulta->totals(['total_natural_amount', 'left_to_pay']);
            $cuantos = $consulta->count();

            if ($cuantos === 0) {
                continue;
            }

            $lineas[] = [
                'divisa' => $prefijo,
                'total' => $suma['total_natural_amount'],
                'saldo' => $suma['left_to_pay'],
                'documentos' => $cuantos,
            ];
        }

        return $lineas;
    }

    /** Embarques del cliente. Un proveedor no tiene bookings propios. */
    private function bookings(): Collection
    {
        $clienteId = auth()->user()->portalClientId();

        if ($clienteId === null) {
            return collect();
        }

        $filtros = BookingFilters::make(['booking_number' => $this->search ?: null]);
        $filtros->client = $clienteId;

        return BookingQuery::make($filtros)->get(50);
    }

    /**
     * Qué facturas de la página ya dio por recibidas el cliente.
     *
     * Se consulta aparte a propósito: `processed` no está en el `selectList` del
     * motor y meterla ahí cambiaría la consulta que vigilan las pruebas de
     * paridad. Son unos cuantos identificadores, sale gratis.
     *
     * @param  iterable<int, object>  $documentos
     * @return array<int, bool>
     */
    private function processedFlags(iterable $documentos): array
    {
        $ids = collect($documentos)->pluck('transc_id')->all();

        if ($ids === []) {
            return [];
        }

        return Transaction::whereIn('transc_id', $ids)
            ->pluck('processed', 'transc_id')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }

    public function render()
    {
        $documentos = $this->tab === 'documentos' ? $this->documents() : null;

        return view('livewire.portal.portal-home', [
            'documentos' => $documentos,
            'sumas' => $documentos === null ? [] : $this->totalsByCurrency(),
            'procesadas' => $documentos === null ? [] : $this->processedFlags($documentos),
            'embarques' => $this->tab === 'embarques' ? $this->bookings() : collect(),
        ])->layout('components.portal-layout', [
            'title' => $this->isClient() ? 'Mis facturas y embarques' : 'Mis documentos',
        ]);
    }
}
