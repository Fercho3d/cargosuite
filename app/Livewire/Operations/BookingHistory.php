<?php

namespace App\Livewire\Operations;

use App\Models\Core\Booking;
use App\Support\History\BookingTimeline;
use Illuminate\Support\Collection;
use Livewire\Component;

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
    public int $bookingId;

    /** Bitácora que se está viendo, o vacío para todas. */
    public string $origen = '';

    public function mount(int $booking): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->bookingId = Booking::findOrFail($booking)->booking_id;
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

        return view('livewire.operations.booking-history', [
            'booking' => Booking::findOrFail($this->bookingId),
            'eventos' => $filtrada->values(),
            'total' => $historia->count(),
            'origenes' => $historia->map(fn (array $evento) => str_starts_with($evento['origen'], __('Contenedor'))
                ? __('Contenedor')
                : $evento['origen'])->unique()->sort()->values(),
        ])->layout('components.app-layout', ['title' => __('Historial del booking')]);
    }
}
