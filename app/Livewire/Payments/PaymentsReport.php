<?php

namespace App\Livewire\Payments;

use App\Models\Core\Bank;
use App\Queries\PaymentRequestFilters;
use App\Queries\PaymentRequestQuery;
use Illuminate\Support\Collection;
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
 * Cada renglón se puede desplegar para ver las solicitudes de pago que lo
 * componen; en el original eso eran tres acciones AJAX aparte.
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

    /** Clave del renglón desplegado (cliente, proveedor o tipo). */
    public ?string $expanded = null;

    public float $queryMs = 0;

    public function mount(string $mode = 'customer'): void
    {
        $this->mode = $mode;
    }

    public function updated(): void
    {
        $this->expanded = null;
    }

    public function toggle(string $clave): void
    {
        $this->expanded = $this->expanded === $clave ? null : $clave;
    }

    public function clearFilters(): void
    {
        $this->reset(['dates', 'datePay', 'bankId', 'expanded']);
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
     * Solicitudes de pago que componen un renglón.
     *
     * Réplica de las acciones de detalle: agrupan por solicitud y desactivan la
     * inversión de signo, para que el desglose se lea en positivo.
     *
     * Con una diferencia deliberada: aquí el desglose hereda el filtro de
     * «pagadas» del renglón que abre. En el original solo lo llevaba el detalle
     * general; los de cliente y proveedor traían también las no pagadas, así que
     * el desglose no sumaba lo que decía el renglón.
     */
    private function detail(): Collection
    {
        if ($this->expanded === null) {
            return collect();
        }

        $filtros = $this->filters();
        $filtros->groupBy = 'request';
        $filtros->noNegative = true;

        match ($this->mode) {
            'vendor' => $filtros->provider_id = (int) $this->expanded,
            'general' => $filtros->type = (int) $this->expanded,
            default => $filtros->client_id = (int) $this->expanded,
        };

        return PaymentRequestQuery::make($filtros)->get();
    }

    public function render()
    {
        $inicio = microtime(true);
        $filas = PaymentRequestQuery::make($this->filters())->get();
        $this->queryMs = round((microtime(true) - $inicio) * 1000, 1);

        return view('livewire.payments.payments-report', [
            'filas' => $filas,
            'detalle' => $this->detail(),
            'banks' => Bank::options(),
        ])->layout('components.app-layout', ['title' => $this->title()]);
    }
}
