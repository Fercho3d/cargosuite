<?php

namespace App\Livewire\Operations;

use App\Models\Core\Booking;
use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Support\Expediente;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Alta y edición de un booking.
 *
 * Es el formulario más grande del sistema. En Yii2 vivía repartido en pestañas
 * de una pantalla de 376 líneas; aquí se agrupa por lo que significa cada cosa:
 * identificación, transporte, ruta, carga y notas.
 *
 * Un booking **cerrado** (`locked`) no se edita: es la señal de que operación ya
 * lo dio por terminado y su facturación quedó fija.
 */
class BookingForm extends Component
{
    public ?int $bookingId = null;

    public bool $locked = false;

    // --- Identificación ---
    public string $bookingNumber = '';

    public ?string $hb = null;

    public ?string $customerReference = null;

    public string $clientId = '';

    public ?string $bookingType = null;

    // --- Transporte ---
    public string $vesselId = '';

    /** Nombre de un buque que aún no está en el catálogo. */
    public string $newVessel = '';

    public ?string $carrierId = null;

    public ?string $transportId = null;

    public ?string $brokerId = null;

    // --- Ruta ---
    public string $loadingPort = '';

    public string $loadingDate = '';

    public string $dischargePort = '';

    public string $arrivalDate = '';

    public string $pickupPlace = '';

    public ?string $finalDestination = null;

    // --- Carga ---
    public ?string $containerType = null;

    public ?string $commodity = null;

    public ?string $setPoint = null;

    public ?string $remarks = null;

    // --- Flota propia ---
    public ?string $operadorId = null;

    public ?string $unidadId = null;

    public ?string $cajaId = null;

    /**
     * Campos propios de esta instalación, por clave. Van en un arreglo y no en
     * propiedades porque no se saben al escribir la clase: los define quien
     * instala el sistema desde `/catalogos/campos-expediente`.
     *
     * @var array<string, mixed>
     */
    public array $propios = [];

    /**
     * Cotización (`mode = 9`) en vez de booking (`mode = 10`). Se decide al
     * entrar con `?modo=cotizacion`, como el «Add Quotation» del original.
     */
    public bool $esCotizacion = false;

    public function mount(?int $booking = null): void
    {
        // Dar de alta lo puede hacer cualquier usuario interno, como en el
        // original (`create` era para todo el que tuviera sesión); editar
        // sigue siendo de administradores.
        abort_unless($booking === null || (auth()->user()?->isAdmin() ?? false), 403);

        $this->propios = Expediente::valores($booking);

        if ($booking !== null) {
            $this->carga(Booking::findOrFail($booking));

            return;
        }

        // El arribo arranca igual a la carga, como en el original: el operador
        // lo ajusta si lo sabe, y si no, no se inventa una fecha.
        $this->loadingDate = now()->toDateString();
        $this->arrivalDate = $this->loadingDate;

        // «Copiar» del detalle: el formulario llega lleno con los datos de otro
        // booking salvo número, buque y candado, como el `copy_id` del original.
        if (($origen = request()->integer('copiar')) > 0) {
            $this->copia(Booking::findOrFail($origen));
        }

        if (request()->query('modo') === 'cotizacion') {
            $this->esCotizacion = true;
        }

        // «Nueva importación» / «Nueva exportación» del listado.
        if (array_key_exists($tipo = request()->integer('tipo'), Booking::typeLabels())) {
            $this->bookingType = (string) $tipo;
        }
    }

    private function copia(Booking $origen): void
    {
        $this->carga($origen);
        $this->propios = Expediente::valores($origen->booking_id);

        $this->bookingId = null;
        $this->locked = false;
        $this->bookingNumber = '';
        $this->vesselId = '';
    }

    private function carga(Booking $modelo): void
    {
        $this->bookingId = $modelo->booking_id;
        $this->locked = (bool) $modelo->locked;
        $this->bookingNumber = (string) $modelo->booking_number;
        $this->hb = $modelo->HB;
        $this->customerReference = $modelo->customer_reference;
        $this->clientId = (string) $modelo->client;
        $this->bookingType = $this->asOption($modelo->booking_type);
        $this->vesselId = (string) $modelo->vessel;
        $this->carrierId = $this->asOption($modelo->carrier_id);
        $this->transportId = $this->asOption($modelo->transport_id);
        $this->brokerId = $this->asOption($modelo->custom_brocker_id);
        $this->loadingPort = (string) $modelo->loading_port;
        $this->loadingDate = $modelo->loading_EDT?->toDateString() ?? '';
        $this->dischargePort = (string) $modelo->dicharge_port_id;
        $this->arrivalDate = $modelo->dicharge_ETA?->toDateString() ?? '';
        $this->pickupPlace = (string) $modelo->pick_up_place_id;
        $this->finalDestination = $this->asOption($modelo->final_destination_id);
        $this->containerType = $this->asOption($modelo->container_type);
        $this->commodity = $modelo->commodity;
        $this->setPoint = $modelo->set_point;
        $this->remarks = $modelo->remarks;
        $this->operadorId = $this->asOption($modelo->operador_id);
        $this->unidadId = $this->asOption($modelo->unidad_id);
        $this->cajaId = $this->asOption($modelo->caja_id);
        $this->esCotizacion = $modelo->isQuotation();
    }

    private function asOption(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : (string) $valor;
    }

    public function save(): void
    {
        $esNuevo = $this->bookingId === null;

        abort_unless($esNuevo || (auth()->user()?->isAdmin() ?? false), 403);
        abort_if($this->locked, 422, __('Este booking está cerrado y no se puede editar.'));

        $this->blanksToNull();

        // Lo que esta instalación no pide ni se valida ni se escribe. Ojo con
        // el orden: se filtra ANTES de validar, porque una regla sobre un campo
        // que la pantalla no enseñó dejaría el formulario imposible de guardar.
        $datos = $this->validate(Expediente::soloVisibles([
            'bookingNumber' => ['required', 'string', 'max:128'],
            'hb' => ['nullable', 'string', 'max:50'],
            'customerReference' => ['nullable', 'string', 'max:64'],
            'clientId' => ['required', Rule::exists('client', 'client_id')],
            'bookingType' => ['nullable', 'integer', Rule::in(array_keys(Booking::typeLabels()))],
            'vesselId' => [Rule::requiredIf(blank($this->newVessel)), 'nullable', Rule::exists('vessel', 'vessel_id')],
            'newVessel' => ['nullable', 'string', 'max:100'],
            'carrierId' => ['nullable', Rule::exists('provider', 'provider_id')],
            'transportId' => ['nullable', Rule::exists('provider', 'provider_id')],
            'brokerId' => ['nullable', Rule::exists('provider', 'provider_id')],
            'loadingPort' => ['required', Rule::exists('loading_ports', 'port_id')],
            'loadingDate' => ['required', 'date'],
            'dischargePort' => ['required', Rule::exists('dicharge_port', 'dicharge_port_id')],
            // Sin `after_or_equal:loadingDate` a propósito: el original acepta un
            // arribo anterior a la carga y hay bookings históricos así.
            'arrivalDate' => ['required', 'date'],
            'pickupPlace' => ['required', Rule::exists('pickup_place', 'pick_id')],
            'finalDestination' => ['nullable', Rule::exists('final_destination', 'final_destination_id')],
            'containerType' => ['nullable', Rule::exists('container_types', 'contType_id')],
            'commodity' => ['nullable', 'string', 'max:50'],
            'setPoint' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string'],
            'operadorId' => ['nullable', Rule::exists('operador', 'operador_id')],
            'unidadId' => ['nullable', Rule::exists('unidad', 'unidad_id')],
            'cajaId' => ['nullable', Rule::exists('unidad', 'unidad_id')],
        ] + Expediente::reglasPropias()), attributes: $this->etiquetas() + Expediente::etiquetasPropias());

        $modelo = $this->bookingId === null ? new Booking : Booking::findOrFail($this->bookingId);

        // Un campo apagado se OMITE del forceFill en vez de escribirse en nulo:
        // si alguien lo apaga en una instalación con historial, lo que ya estaba
        // capturado tiene que quedarse como está.
        $modelo->forceFill(Expediente::aColumnas([
            'bookingNumber' => $datos['bookingNumber'] ?? null,
            'hb' => $datos['hb'] ?? null,
            'customerReference' => $datos['customerReference'] ?? null,
            'clientId' => (int) $datos['clientId'],
            'bookingType' => $this->entero($datos['bookingType'] ?? null),
            // Solo se resuelve si el campo está encendido: si no, un alta
            // rápida de buque colada por la petición crearía un renglón fantasma.
            'vesselId' => Expediente::visible('vesselId') ? $this->resolveVessel() : null,
            'carrierId' => $this->entero($datos['carrierId'] ?? null),
            'transportId' => $this->entero($datos['transportId'] ?? null),
            'brokerId' => $this->entero($datos['brokerId'] ?? null),
            'loadingPort' => (int) $datos['loadingPort'],
            'loadingDate' => $datos['loadingDate'],
            'dischargePort' => (int) $datos['dischargePort'],
            'arrivalDate' => $datos['arrivalDate'],
            'pickupPlace' => (int) $datos['pickupPlace'],
            'finalDestination' => $this->entero($datos['finalDestination'] ?? null),
            'containerType' => $this->entero($datos['containerType'] ?? null),
            'commodity' => $datos['commodity'] ?? null,
            'setPoint' => $datos['setPoint'] ?? null,
            'remarks' => $datos['remarks'] ?? null,
            'operadorId' => $this->entero($datos['operadorId'] ?? null),
            'unidadId' => $this->entero($datos['unidadId'] ?? null),
            'cajaId' => $this->entero($datos['cajaId'] ?? null),
        ]) + ['modified_by' => auth()->id()]);

        if ($esNuevo) {
            // Nace como borrador, como en el original: los contenedores se
            // capturan después en el detalle, y la confirmación al cliente se
            // manda al confirmarlo, ya con carga. Mandarla aquí le hacía llegar
            // un PDF sin contenedores.
            $modelo->forceFill([
                'is_draft' => 1,
                'mode' => $this->esCotizacion ? Booking::MODE_QUOTATION : Booking::MODE_BOOKING,
                'locked' => 0,
                'created_by' => auth()->id(),
            ]);
        }

        $modelo->save();

        Expediente::guardaValores((int) $modelo->booking_id, $this->propios);

        session()->flash('status', match (true) {
            ! $esNuevo => __('Booking actualizado.'),
            $this->esCotizacion => __('Cotización guardada como borrador.'),
            default => __('Booking guardado como borrador. Captura sus contenedores y confírmalo para avisar al cliente.'),
        });
        $this->redirectRoute('operations.bookings.show', $modelo->booking_id, navigate: true);
    }

    /**
     * Buque elegido, dando de alta el catálogo si el usuario escribió uno nuevo.
     *
     * El original permite lo mismo: los buques cambian de nombre y de servicio
     * seguido, y detener la captura para ir al catálogo no tiene sentido.
     */
    private function resolveVessel(): int
    {
        if (blank($this->newVessel)) {
            return (int) $this->vesselId;
        }

        $existente = DB::table('vessel')->where('vessel_name', trim($this->newVessel))->value('vessel_id');

        return (int) ($existente ?? DB::table('vessel')->insertGetId(['vessel_name' => trim($this->newVessel)], 'vessel_id'));
    }

    private function entero(?string $valor): ?int
    {
        return $valor === null || $valor === '' ? null : (int) $valor;
    }

    /** Los selectores vacíos llegan como cadena vacía; para validar conviene null. */
    private function blanksToNull(): void
    {
        foreach ([
            'hb', 'customerReference', 'bookingType', 'carrierId', 'transportId',
            'brokerId', 'finalDestination', 'containerType', 'commodity', 'setPoint', 'remarks',
        ] as $campo) {
            if (trim((string) $this->{$campo}) === '') {
                $this->{$campo} = null;
            }
        }
    }

    /** @return array<string, string> */
    private function etiquetas(): array
    {
        return [
            'bookingNumber' => __('número de booking'),
            'hb' => 'HB',
            'customerReference' => __('referencia del cliente'),
            'clientId' => 'cliente',
            'vesselId' => 'buque',
            'newVessel' => 'buque nuevo',
            'carrierId' => 'naviera',
            'transportId' => 'transportista',
            'brokerId' => 'agente aduanal',
            'loadingPort' => __('puerto de carga'),
            'loadingDate' => __('fecha de carga'),
            'dischargePort' => __('puerto de descarga'),
            'arrivalDate' => __('fecha de arribo'),
            'pickupPlace' => __('lugar de recolección'),
            'finalDestination' => 'destino final',
            'containerType' => __('tipo de contenedor'),
            'commodity' => __('mercancía'),
            'setPoint' => 'temperatura',
        ];
    }

    public function titulo(): string
    {
        return match (true) {
            $this->bookingId !== null => $this->esCotizacion ? __('Editar cotización') : __('Editar booking'),
            $this->esCotizacion => __('Nueva cotización'),
            default => __('Nuevo booking'),
        };
    }

    public function render()
    {
        return view('livewire.operations.booking-form', [
            'clientes' => Client::options(),
            'buques' => DB::table('vessel')->orderBy('vessel_name')->pluck('vessel_name', 'vessel_id')->all(),
            'navieras' => Provider::optionsByType(Provider::TYPE_CARRIER),
            'transportistas' => Provider::optionsByType(Provider::TYPE_TRANSPORT),
            'agentes' => Provider::optionsByType(Provider::TYPE_BROKER),
            'puertosCarga' => DB::table('loading_ports')->where('deleted', 0)->orderBy('port_name')->pluck('port_name', 'port_id')->all(),
            'puertosDescarga' => DB::table('dicharge_port')->where('deleted', 0)->orderBy('name')->pluck('name', 'dicharge_port_id')->all(),
            'lugares' => DB::table('pickup_place')->orderBy('name')->pluck('name', 'pick_id')->all(),
            'destinos' => DB::table('final_destination')->where('deleted', 0)->orderBy('name')->pluck('name', 'final_destination_id')->all(),
            'tiposContenedor' => DB::table('container_types')->orderBy('container_name')->pluck('container_name', 'contType_id')->all(),
            // Solo los activos: un operador dado de baja o una unidad vendida no
            // deben poder asignarse a un viaje nuevo.
            'operadores' => DB::table('operador')->where('activo', 1)->orderBy('nombre')->pluck('nombre', 'operador_id')->all(),
            'tractores' => DB::table('unidad')->where('activo', 1)->where('tipo', 'tractor')->orderBy('numero')->pluck('numero', 'unidad_id')->all(),
            'cajas' => DB::table('unidad')->where('activo', 1)->where('tipo', 'caja')->orderBy('numero')->pluck('numero', 'unidad_id')->all(),
        ])->layout('components.app-layout', [
            'title' => $this->titulo(),
        ]);
    }
}
