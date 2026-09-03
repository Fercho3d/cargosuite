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

    /** Totales de TODO el filtro; se calculan solo si el usuario los pide. */
    public ?array $totals = null;

    public float $queryMs = 0;

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

    public function clearFilters(): void
    {
        $this->reset(['bookingNumber', 'tranNumber', 'dates', 'paid']);
        $this->totals = null;
        $this->resetPage();
    }

    public function calculateTotals(): void
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

        return view('livewire.transactions.booking-report', [
            'rows' => $rows,
        ])->layout('components.app-layout', ['title' => __('Reporte por booking')]);
    }
}
