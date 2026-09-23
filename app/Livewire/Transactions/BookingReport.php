<?php

namespace App\Livewire\Transactions;

use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reporte por booking: ingreso, egreso y utilidad de cada embarque.
 *
 * Es `actionReportByBooking` de Yii2 — el mismo motor de los listados, agrupando
 * por `booking` en vez de por transacción. Las columnas `income` y `expense` ya
 * las calcula el motor: ingreso es lo facturado al cliente sin IVA y egreso lo
 * facturado por proveedores, ambos convertidos a pesos.
 *
 * Ojo con dos columnas heredadas: al agrupar por booking, el número de
 * transacción y el estado de pago salen de **una fila cualquiera del grupo**
 * (así lo resuelve MySQL y así lo enseña el original). No son un dato del
 * booking; se conservan para no cambiarle el reporte al cliente.
 */
class BookingReport extends Component
{
    use WithPagination;

    #[Url(as: 'bk', except: '')]
    public string $bookingNumber = '';

    #[Url(as: 'num', except: '')]
    public string $tranNumber = '';

    /** Rango sobre la fecha de carga del booking, formato "dd/mm/aaaa - dd/mm/aaaa". */
    #[Url(as: 'f', except: '')]
    public string $dates = '';

    /** '' = todas, 0 = sin pagar, 1 = pagadas, 2 = parciales. */
    #[Url(as: 'pago', except: '')]
    public string $paid = '';

    #[Url(as: 'n', except: 50)]
    public int $perPage = 50;

    /** Totales de TODO el filtro; se muestran mientras `showTotals` esté encendido. */
    public ?array $totals = null;

    /** Casilla «mostrar sumatoria» del pie; se recuerda en cookie (ver `mount`). */
    public bool $showTotals = false;

    public float $queryMs = 0;

    public function mount(): void
    {
        $this->showTotals = request()->cookie('mostrar_totales') === '1';

        // Por omisión, del 1 de enero de este año a hoy (ver el mismo criterio en
        // el listado de transacciones): no trae años de historia de golpe.
        if ($this->dates === '') {
            $this->dates = now()->startOfYear()->format('d/m/Y').' - '.now()->format('d/m/Y');
        }
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function paginationSimpleView(): string
    {
        return 'vendor.pagination.app';
    }

    public function updated(string $property): void
    {
        if ($property === 'page') {
            return;
        }

        $this->resetPage();
        $this->totals = null;
    }

    /** Limpiar deja el reporte sin rango, no de vuelta al año en curso. */
    public function clearFilters(): void
    {
        $this->reset(['bookingNumber', 'tranNumber', 'dates', 'paid']);
        $this->totals = null;
        $this->resetPage();
    }

    /** «Ver todos los años»: quita el rango por omisión de un clic. */
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

    /**
     * La casilla «mostrar sumatoria» se recuerda en una cookie (la misma que el
     * listado de transacciones), para que quede puesta en cualquier pantalla.
     */
    public function updatedShowTotals(bool $value): void
    {
        cookie()->queue('mostrar_totales', $value ? '1' : '0', 60 * 24 * 365);

        if (! $value) {
            $this->totals = null;
        }
    }

    private function calculateTotals(): void
    {
        $this->totals = $this->query()->totals(['income', 'expense']);
    }

    private function query(): TransactionQuery
    {
        $filtros = TransactionFilters::make([
            'booking_number' => $this->bookingNumber ?: null,
            'tran_number' => $this->tranNumber ?: null,
            'dates_booking' => $this->dates ?: null,
            'paid' => $this->paid !== '' ? (int) $this->paid : null,
        ]);

        $filtros->groupBy = 'booking';

        return TransactionQuery::make($filtros);
    }

    /** Descarga con el mismo filtro que se está viendo. */
    public function exportUrl(): string
    {
        $parametros = array_filter([
            'bk' => $this->bookingNumber,
            'num' => $this->tranNumber,
            'f' => $this->dates,
            'pago' => $this->paid,
        ], fn ($valor) => $valor !== null && $valor !== '');

        return route('transactions.export', ['screen' => 'report'] + $parametros);
    }

    public function render()
    {
        $inicio = microtime(true);
        $rows = $this->query()->paginate($this->perPage, $this->getPage());
        $this->queryMs = round((microtime(true) - $inicio) * 1000, 1);

        // Con la sumatoria encendida, el pie se calcula en cada pintada para que
        // siga al filtro que se esté viendo.
        if ($this->showTotals) {
            $this->calculateTotals();
        } else {
            $this->totals = null;
        }

        return view('livewire.transactions.booking-report', [
            'rows' => $rows,
        ])->layout('components.app-layout', ['title' => __('Reporte por booking')]);
    }
}
