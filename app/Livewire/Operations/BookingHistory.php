<?php

namespace App\Livewire\Operations;

use App\Models\Core\Booking;
use App\Support\History\BookingTimeline;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * La historia de un booking: quién cambió qué y cuándo.
 *
 * Equivale a `BookingController::actionHistory()` de Yii2, que repartía las
 * cuatro bitácoras en cuatro rejillas. Aquí van juntas en una línea de tiempo,
 * que es como se leen: para saber qué le pasó a un embarque uno quiere el orden
 * de los hechos, no cuatro tablas que hay que cruzar a ojo.
 */
class BookingHistory extends Component
{
    use WithPagination;

    /** Movimientos por página, como las rejillas del original. */
    private const POR_PAGINA = 100;

    public int $bookingId;

    /** Bitácora que se está viendo, o vacío para todas. */
    public string $origen = '';

    public function mount(int $booking): void
    {
        // Para cualquier usuario interno, como el `history` del original.
        $this->bookingId = Booking::findOrFail($booking)->booking_id;
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function updatedOrigen(): void
    {
        $this->resetPage();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function timeline(): Collection
    {
        return app(BookingTimeline::class)->forBooking($this->bookingId);
    }

    public function render()
    {
        $historia = $this->timeline();

        // Los contenedores son varios orígenes distintos (uno por contenedor);
        // en el filtro se ofrecen juntos.
        $filtrada = $this->origen === ''
            ? $historia
            : $historia->filter(fn (array $evento) => str_starts_with($evento['origen'], $this->origen));

        // Se pagina ya calculada: cada movimiento se compara con el anterior de
        // su bitácora, así que las cuatro series se leen completas (en `frego`
        // el booking con más historia no llega a 170 renglones). Lo que se
        // limita es lo que se pinta.
        $eventos = new LengthAwarePaginator(
            $filtrada->forPage($this->getPage(), self::POR_PAGINA)->values(),
            $filtrada->count(),
            self::POR_PAGINA,
            $this->getPage(),
        );

        return view('livewire.operations.booking-history', [
            'booking' => Booking::findOrFail($this->bookingId),
            'eventos' => $eventos,
            'total' => $historia->count(),
            'origenes' => $historia->map(fn (array $evento) => str_starts_with($evento['origen'], __('Contenedor'))
                ? __('Contenedor')
                : $evento['origen'])->unique()->sort()->values(),
        ])->layout('components.app-layout', ['title' => __('Historial del booking')]);
    }
}
