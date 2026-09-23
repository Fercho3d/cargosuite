<?php

namespace App\Livewire\Operations;

use App\Queries\BookingQuery;
use App\Queries\TransactionFilters;
use App\Support\Milestones\BookingMilestones;
use App\Support\Milestones\Checklist;
use App\Support\Milestones\MilestoneCatalog;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reporte de continuidad: en qué punto va cada embarque.
 *
 * La tabla `booking_continuity` guarda una fecha por hito (recolección, corte
 * documental, instrucciones, gate in, despacho, zarpe, pago del BL, SWB,
 * entrega, gate out…). Esta pantalla los pone en una rejilla para verlos de
 * corrido, y deja capturarlos ahí mismo: es lo que operación hace todo el día.
 */
class ContinuityReport extends Component
{
    use WithPagination;

    /** Los hitos, en el orden en que ocurren. */
    /**
     * Los hitos ya no son una constante: salen del catálogo `hito`, así que un
     * negocio distinto captura los suyos desde `/catalogos/hitos` sin tocar una
     * línea de código. Antes eran catorce columnas de `booking_continuity`.
     *
     * @return array<string, string> clave => etiqueta traducida
     */
    public static function hitos(): array
    {
        return MilestoneCatalog::etiquetas();
    }

    #[Url(as: 'bk', except: '')]
    public string $bookingNumber = '';

    #[Url(as: 'cliente', except: '')]
    public string $clientName = '';

    /** Rango sobre la fecha de recolección, "dd/mm/aaaa - dd/mm/aaaa". */
    #[Url(as: 'f', except: '')]
    public string $dates = '';

    /**
     * Ver las fechas de cumplimiento (`check_list`) en vez de las planeadas.
     * En ese modo la rejilla es de solo lectura: el cumplimiento se marca en
     * el detalle del booking, donde aplican sus reglas de orden.
     */
    #[Url(as: 'cumplidas', except: false)]
    public bool $verCumplidas = false;

    /** Renglón y columna en captura: "cont_id|hito". */
    public ?string $editing = null;

    public string $value = '';

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['page', 'editing', 'value'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['bookingNumber', 'clientName', 'dates']);
        $this->resetPage();
    }

    /**
     * Abre la captura de un hito concreto de un booking.
     *
     * Para cualquier usuario interno, como el `setdate` del original. La fecha
     * va con hora (`datetime-local`), que es como la guardaba el sistema viejo;
     * quien no la sepa deja las 00:00 que vienen puestas.
     */
    public function editMilestone(int $bookingId, string $hito, ?string $actual = null): void
    {
        abort_if($this->verCumplidas, 422, __('Las fechas de cumplimiento se marcan desde el detalle del booking.'));
        abort_unless(MilestoneCatalog::porClave($hito)?->activo ?? false, 404);

        $this->editing = $bookingId.'|'.$hito;
        $this->value = BookingMilestones::paraCaptura($actual);
        $this->resetErrorBag();
    }

    public function saveMilestone(): void
    {
        [$bookingId, $hito] = explode('|', (string) $this->editing);

        abort_unless(MilestoneCatalog::porClave($hito)?->activo ?? false, 404);

        $this->validate(
            ['value' => ['nullable', 'date']],
            attributes: ['value' => mb_strtolower(self::hitos()[$hito])],
        );

        BookingMilestones::guarda((int) $bookingId, $hito, $this->value ?: null, auth()->id());

        $this->cancel();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'value']);
        $this->resetErrorBag();
    }

    public function render()
    {
        $filas = DB::table('booking as b')
            ->leftJoin('booking_continuity as bc', BookingQuery::ultimaContinuidad(...))
            ->leftJoin('client as c', 'c.client_id', '=', 'b.client')
            ->leftJoin('vessel as v', 'v.vessel_id', '=', 'b.vessel')
            ->where('b.is_draft', 0)
            ->where('b.mode', 10)
            ->when($this->bookingNumber, fn ($q, $v) => $q->where('b.booking_number', 'like', "%{$v}%"))
            ->when($this->clientName, fn ($q, $v) => $q->where('c.fullName', 'like', "%{$v}%"))
            ->when(
                ($rango = TransactionFilters::parseRange($this->dates ?: null)) !== null,
                fn ($q) => $q->whereBetween(DB::raw('DATE(bc.pickup_date)'), $rango)
            )
            ->orderByDesc('b.booking_id')
            ->paginate(25, [
                'b.booking_id', 'b.booking_number', 'c.fullName as client_name', 'v.vessel_name',
            ], 'page', $this->getPage());

        // Las fechas de los 25 renglones en UNA consulta: la rejilla no puede
        // preguntar expediente por expediente.
        $ids = $filas->pluck('booking_id')->map(intval(...))->all();
        $fechas = $this->verCumplidas
            ? array_map(fn (array $hitos) => array_map(fn (array $marca) => $marca['fecha'], $hitos), Checklist::deVarios($ids))
            : BookingMilestones::deVarios($ids);

        return view('livewire.operations.continuity-report', [
            'filas' => $filas,
            'fechas' => $fechas,
        ])->layout('components.app-layout', ['title' => __('Continuidad')]);
    }
}
