<?php

namespace App\Livewire\Operations;

use App\Actions\Bookings\GenerateBookingBilling;
use App\Actions\Bookings\PlanBookingBilling;
use App\Models\Core\Account;
use App\Models\Core\Booking;
use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Models\Core\Transaction;
use App\Support\Billing\BillingBlock;
use App\Support\Billing\BillingPlan;
use App\Support\Billing\ServiceCandidate;
use App\Support\Billing\ServiceMatcher;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Generación automática de la factura y los costos de un booking.
 *
 * En Yii2 esto era un botón que disparaba cuatro generaciones seguidas y
 * regresaba a la pantalla anterior con un «yes» o un «no» por cada una: no se
 * veía qué se había escrito hasta ir a buscarlo a la lista de transacciones, y
 * si algo salía mal quedaba escrito a medias.
 *
 * Aquí primero se enseña **qué servicios empataron y qué documentos saldrían**, y
 * solo entonces se escribe. Es la misma prudencia que se le puso al timbrado, y
 * por la misma razón: son documentos financieros.
 *
 * La propuesta viene **entera y marcada**: confirmar sin tocar nada escribe
 * exactamente lo que escribía el sistema viejo. Lo que la pantalla añade es
 * poder verlo antes —y quitar lo que no corresponda— en vez de descubrirlo
 * después en la lista de transacciones.
 */
class BillingGenerator extends Component
{
    public int $bookingId;

    /** Llaves de los renglones que se van a escribir. */
    public array $selected = [];

    /** @var array<string, list<ServiceCandidate>>|null */
    private ?array $cache = null;

    private ?Booking $model = null;

    public function mount(int $booking, ServiceMatcher $emparejador): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $modelo = Booking::findOrFail($booking);
        abort_if((bool) $modelo->locked, 422, __('Este booking está cerrado: su facturación quedó fija.'));

        $this->bookingId = $modelo->booking_id;
        $this->model = $modelo;
        $this->cache = $emparejador->candidates($modelo);

        $this->selected = array_map(fn (ServiceCandidate $candidato) => $candidato->key(), $this->all());
    }

    public function booking(): Booking
    {
        return $this->model ??= Booking::findOrFail($this->bookingId);
    }

    /** Fecha con la que se van a escribir los documentos: hoy, como en el original. */
    public function date(): Carbon
    {
        return now()->startOfDay();
    }

    /** @return array<string, list<ServiceCandidate>> */
    public function candidates(): array
    {
        return $this->cache ??= app(ServiceMatcher::class)->candidates($this->booking());
    }

    /** @return list<ServiceCandidate> */
    public function all(): array
    {
        return array_merge(...array_values($this->candidates()));
    }

    /**
     * Los renglones de un bloque. Se enseñan todos: el original no descartaba
     * ninguno y aquí tampoco.
     *
     * @return list<ServiceCandidate>
     */
    public function visible(BillingBlock $bloque): array
    {
        return $this->candidates()[$bloque->value] ?? [];
    }

    public function plan(): BillingPlan
    {
        $elegidos = array_values(array_filter(
            $this->all(),
            fn (ServiceCandidate $candidato) => in_array($candidato->key(), $this->selected, true),
        ));

        return app(PlanBookingBilling::class)->handle($this->booking(), $elegidos);
    }

    /** Transacciones que el booking ya tiene, para no duplicarlas sin darse cuenta. */
    public function existing()
    {
        return Transaction::where('booking', $this->bookingId)
            ->where('cancelled', 0)
            ->orderBy('transc_id')
            ->get(['transc_id', 'tran_number', 'tran_type', 'vendor', 'customer']);
    }

    /** Vuelve a marcar el bloque completo. */
    public function selectAll(string $bloque): void
    {
        $llaves = array_map(
            fn (ServiceCandidate $candidato) => $candidato->key(),
            $this->visible(BillingBlock::from($bloque)),
        );

        $this->selected = array_values(array_unique([...$this->selected, ...$llaves]));
    }

    public function clearBlock(string $bloque): void
    {
        $llaves = array_map(
            fn (ServiceCandidate $candidato) => $candidato->key(),
            $this->candidates()[$bloque] ?? [],
        );

        $this->selected = array_values(array_diff($this->selected, $llaves));
    }

    public function generate(GenerateBookingBilling $generar): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $creadas = $generar->handle($this->booking(), $this->plan(), auth()->user());

        session()->flash('status', trans_choice(
            __('{1}Se creó :count documento.|[2,*]Se crearon :count documentos.'),
            count($creadas),
            ['count' => count($creadas)],
        ));

        $this->redirectRoute('operations.bookings.show', $this->bookingId, navigate: true);
    }

    public function render()
    {
        $booking = $this->booking();

        return view('livewire.operations.billing-generator', [
            'booking' => $booking,
            'plan' => $this->plan(),
            'existentes' => $this->existing(),
            'bloques' => BillingBlock::cases(),
            'divisas' => Account::options(),
            'cliente' => Client::find($booking->client)?->fullName,
            'proveedores' => [
                BillingBlock::Carrier->value => Provider::find($booking->carrier_id)?->fullName,
                BillingBlock::Transport->value => Provider::find($booking->transport_id)?->fullName,
                BillingBlock::Broker->value => Provider::find($booking->custom_brocker_id)?->fullName,
            ],
        ])->layout('components.app-layout', ['title' => __('Generar factura y costos')]);
    }
}
