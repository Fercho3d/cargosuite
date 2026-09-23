<?php

namespace App\Livewire\Payments;

use App\Models\Core\Bank;
use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Queries\PaymentRequestFilters;
use App\Queries\PaymentRequestQuery;
use App\Queries\TransactionFilters;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reportes de cobros y pagos: por cliente, por proveedor y el general.
 *
 * Son `actionReportByCustomer`, `actionReportByVendor` y `actionReportGeneral` de
 * Yii2. Las tres pantallas comparten columnas y motor —solo cambian la agrupación
 * y el tipo—, así que aquí son un componente con tres modos en lugar de tres
 * pantallas casi iguales.
 *
 * Cada renglón abre la lista de solicitudes de pago que lo componen, ya
 * filtrada; en el original eso eran tres acciones AJAX que se desplegaban ahí.
 */
class PaymentsReport extends Component
{
    /** customer | vendor | general */
    public string $mode = 'customer';

    /** Rango sobre la fecha de la solicitud, "dd/mm/aaaa - dd/mm/aaaa". */
    #[Url(as: 'f', except: '')]
    public string $dates = '';

    /**
     * Fecha con la que se revalúa lo pagado. Es opcional: sin ella, las columnas
     * de valuación y diferencia cambiaria salen vacías, igual que en el original.
     */
    #[Url(as: 'tc', except: '')]
    public string $datePay = '';

    #[Url(as: 'banco', except: '')]
    public string $bankId = '';

    /** Un solo cliente (modo `customer`) o proveedor (modo `vendor`). */
    #[Url(as: 'cliente', except: '')]
    public string $clientId = '';

    #[Url(as: 'proveedor', except: '')]
    public string $providerId = '';

    public float $queryMs = 0;

    public function mount(string $mode = 'customer'): void
    {
        $this->mode = $mode;
    }

    public function clearFilters(): void
    {
        $this->reset(['dates', 'datePay', 'bankId', 'clientId', 'providerId']);
    }

    /** Este reporte con su filtro: a dónde vuelve la lista de solicitudes. */
    public function currentUrl(): string
    {
        return route('payments.report.'.$this->mode, array_filter([
            'f' => $this->dates,
            'tc' => $this->datePay,
            'banco' => $this->bankId,
            'cliente' => $this->clientId,
            'proveedor' => $this->providerId,
        ]), absolute: false);
    }

    /** Columna que identifica cada renglón según el modo. */
    public function keyColumn(): string
    {
        return match ($this->mode) {
            'vendor' => 'provider_id',
            'general' => 'type',
            default => 'client_id',
        };
    }

    public function title(): string
    {
        return match ($this->mode) {
            'vendor' => __('Pagos por proveedor'),
            'general' => __('Cobros y pagos, general'),
            default => __('Cobros por cliente'),
        };
    }

    /** Filtros base de cada pantalla, tal como los fija el controlador original. */
    private function filters(): PaymentRequestFilters
    {
        $filtros = PaymentRequestFilters::make([
            'dates' => $this->dates ?: null,
            'date_pay' => $this->datePay ?: null,
            'bank_id' => $this->bankId !== '' ? (int) $this->bankId : null,
            'client_id' => $this->mode === 'customer' && $this->clientId !== '' ? (int) $this->clientId : null,
            'provider_id' => $this->mode === 'vendor' && $this->providerId !== '' ? (int) $this->providerId : null,
        ]);

        $filtros->paid = 1;

        match ($this->mode) {
            'vendor' => [$filtros->groupBy = 'provider', $filtros->type = 2, $filtros->noNegative = true],
            'general' => $filtros->groupBy = 'type',
            default => [$filtros->groupBy = 'client', $filtros->type = 1],
        };

        return $filtros;
    }

    /**
     * Las solicitudes de pago que forman un renglón, en la lista completa de
     * solicitudes: pagadas, con el mismo filtro y con regreso a este reporte.
     *
     * Sin rango de fechas el reporte abarca toda la historia; se manda uno
     * explícito porque la lista, vacía, arranca en el año en curso. Es muy
     * amplio a propósito: hay solicitudes con fechas mal capturadas (1984).
     */
    public function requestsUrl(object $fila): string
    {
        $filtro = match ($this->mode) {
            'vendor' => ['tipo' => 2, 'proveedor' => $fila->provider_id, 'divisa' => $fila->account_id],
            'general' => ['tipo' => $fila->type],
            default => ['tipo' => 1, 'cliente' => $fila->client_id],
        };

        return route('payments.requests', array_filter($filtro + [
            'estado' => 1,
            'banco' => $this->bankId,
            'f' => $this->dates ?: '01/01/1900 - 31/12/2099',
            'tc' => $this->datePay,
            'volver' => $this->currentUrl(),
        ], fn ($valor) => $valor !== null && $valor !== ''), absolute: false);
    }

    /**
     * Corte de los saldos por banco: el fin del rango de fechas del reporte o,
     * sin rango, hoy.
     */
    public function bankCutoff(): Carbon
    {
        $rango = TransactionFilters::parseRange($this->dates ?: null);

        return $rango === null ? Carbon::today() : Carbon::parse($rango[1]);
    }

    public function render()
    {
        $inicio = microtime(true);
        $consulta = PaymentRequestQuery::make($this->filters());
        $filas = $consulta->get();
        // El «Total» por banco de la pantalla de Bancos del original, ahora
        // aquí: solo en el general, que es el que abarca cobros y pagos.
        $saldos = $this->mode === 'general' ? $consulta->bankBalances($this->bankCutoff()) : collect();
        $this->queryMs = round((microtime(true) - $inicio) * 1000, 1);

        return view('livewire.payments.payments-report', [
            'filas' => $filas,
            'saldos' => $saldos,
            'banks' => Bank::options(),
            'terceros' => match ($this->mode) {
                'customer' => Client::options(),
                'vendor' => Provider::options(),
                default => [],
            },
        ])->layout('components.app-layout', ['title' => $this->title()]);
    }
}
