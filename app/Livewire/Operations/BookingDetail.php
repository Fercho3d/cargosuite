<?php

namespace App\Livewire\Operations;

use App\Actions\Bookings\SendBookingConfirmation;
use App\Models\Core\Booking;
use App\Queries\BookingFilters;
use App\Queries\BookingQuery;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\BookingFiles;
use App\Support\Expediente;
use App\Support\Fleet\TripExpenses;
use App\Support\History\BookingTimeline;
use App\Support\Milestones\BookingMilestones;
use App\Support\Milestones\Checklist;
use App\Support\Milestones\MilestoneCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Detalle de un booking: la ruta, los contenedores, su facturación y el avance
 * de la lista de verificación.
 *
 * Equivale a `actionView` del `BookingController` de Yii2, que repartía lo mismo
 * en varias pestañas.
 */
class BookingDetail extends Component
{
    use WithFileUploads;

    public int $bookingId;

    // --- Formulario de contenedor ---
    public bool $editingContainer = false;

    public ?int $containerId = null;

    public string $containerNumber = '';

    public string $containerSeal = '';

    public string $containerType = '';

    public string $containerQuantity = '1';

    public string $containerCommodity = '';

    // --- Gastos de viaje (solo con flota propia) ---
    public ?string $hitoEditando = null;

    public string $hitoFecha = '';

    public ?int $gastoId = null;

    public string $gastoTipo = 'combustible';

    public string $gastoFecha = '';

    public string $gastoDescripcion = '';

    public string $gastoImporte = '';

    public string $gastoLitros = '';

    public string $gastoOdometro = '';

    public string $gastoFolio = '';

    public string $containerPickup = '';

    // --- Modalidad (booking_continuity.modality) ---
    public string $modality = '';

    // --- Lista de verificación del booking (cinco fechas en `booking`) ---
    public ?string $bookingCheckEditando = null;

    public string $bookingCheckFecha = '';

    // --- Instrucciones de embarque ---
    /** @var array<string, string|null> */
    public array $instructions = [];

    public bool $editingInstructions = false;

    // --- Documentos del booking ---
    /** Campo al que se está subiendo, para no mezclar los archivos. */
    public ?int $uploadField = null;

    public $upload = null;

    /**
     * El encabezado, recordado durante la petición: una acción lo consulta
     * varias veces (permisos, candado, unidad del viaje) y no tiene por qué ir
     * a la base en cada una. `render()` lo olvida para pintar lo recién escrito.
     */
    private ?object $headerCache = null;

    /**
     * Los ocho campos de las instrucciones de embarque: cómo viene cada parte en
     * el documento y cómo debería decir. Es el «SI» del que cuelga el BL.
     */
    private const INSTRUCCIONES = [
        'shipper' => 'Shipper',
        'consignee' => 'Consignee',
        'notify_party' => 'Notify party',
        'description' => 'Descripción de la mercancía',
    ];

    public function mount(int $booking): void
    {
        $this->bookingId = $booking;
        $this->loadInstructions();
        $this->modality = (string) DB::table('booking_continuity')->where('booking', $booking)->value('modality');
    }

    // ------------------------------------------- Instrucciones de embarque

    private function loadInstructions(): void
    {
        // `findOrFail` levantaría `ModelNotFoundException`, que fuera de una
        // petición HTTP (una prueba de Livewire, por ejemplo) no se traduce a
        // 404. Se usa la misma excepción que `header()` para que un booking que
        // no existe responda igual por los dos caminos.
        $modelo = Booking::find($this->bookingId)
            ?? throw new NotFoundHttpException(__('No existe el booking ').$this->bookingId);

        foreach (array_keys(self::INSTRUCCIONES) as $parte) {
            foreach (['is', 'should'] as $lado) {
                $this->instructions["{$parte}_{$lado}"] = $modelo->{"{$parte}_{$lado}"};
            }
        }
    }

    /** @return array<string, string> */
    public function instructionParts(): array
    {
        return array_map(fn (string $etiqueta) => __($etiqueta), self::INSTRUCCIONES);
    }

    public function editInstructions(): void
    {
        $this->assertEditable();

        $this->editingInstructions = true;
        $this->resetErrorBag();
    }

    public function cancelInstructions(): void
    {
        $this->editingInstructions = false;
        $this->loadInstructions();
        $this->resetErrorBag();
    }

    /**
     * Guarda solo esos ocho campos, como el `actionSaveIntructions` del original,
     * que usaba un escenario aparte para no tocar el resto del booking.
     */
    public function saveInstructions(): void
    {
        $this->assertEditable();

        $reglas = [];

        foreach (array_keys(self::INSTRUCCIONES) as $parte) {
            foreach (['is', 'should'] as $lado) {
                $reglas["instructions.{$parte}_{$lado}"] = ['nullable', 'string', 'max:1000'];
            }
        }

        $this->validate($reglas);

        Booking::findOrFail($this->bookingId)
            ->forceFill(array_merge($this->instructions, ['modified_by' => auth()->id()]))
            ->save();

        $this->editingInstructions = false;
        session()->flash('status', __('Instrucciones de embarque guardadas.'));
    }

    /** El encabezado sale del mismo motor que el listado, para que el avance cuadre. */
    private function header(): object
    {
        if ($this->headerCache !== null) {
            return $this->headerCache;
        }

        foreach ([10, 9] as $modo) {
            $filtros = BookingFilters::make([]);
            $filtros->mode = $modo;
            // Un borrador también se abre: aquí se le capturan los
            // contenedores y se confirma.
            $filtros->is_draft = null;

            $fila = BookingQuery::make($filtros)->query()
                ->where('b.booking_id', $this->bookingId)
                ->first();

            if ($fila !== null) {
                return $this->headerCache = $fila;
            }
        }

        throw new NotFoundHttpException(__('No existe el booking ').$this->bookingId);
    }

    /** @return Collection<int, object> */
    private function containers(): Collection
    {
        return collect(
            DB::table('containers as c')
                ->leftJoin('container_types as ct', 'ct.contType_id', '=', 'c.container_type')
                ->where('c.booking', $this->bookingId)
                ->orderBy('c.container_ID')
                ->get([
                    'c.container_ID', 'c.number', 'c.seal', 'c.quantity', 'c.comodity',
                    'c.pick_up_date', 'ct.container_name',
                ])
        );
    }

    /** Facturas y costos del booking, con la misma aritmética que el módulo. */
    private function transactions(): Collection
    {
        $filtros = TransactionFilters::make(['booking' => $this->bookingId, 'showCancelled' => 1]);

        return TransactionQuery::make($filtros)->get();
    }

    /**
     * Los hitos del expediente: en qué va, y con qué fecha se marcó cada paso.
     *
     * ⚠️ Antes esto pintaba las 27 columnas `_chk_date` de `check_list` con sus
     * rótulos escritos a mano —«Zarpe», «SWB», «VGM», «Buque»—, así que en una
     * empresa de camiones la lista no significaba nada **y no se podía marcar**:
     * era de solo lectura. Ahora sale del catálogo de hitos, que cada instalación
     * ajusta desde Catálogos, y se marca aquí mismo.
     *
     * Cada hito trae dos capas: la fecha **planeada** (`fecha`, la que se
     * captura) y, si tiene casilla en `check_list`, el **cumplimiento**
     * (`cumplida`, `por`) con su «delivery time» (`retraso`). `marcada` es lo
     * que pinta la palomita: el cumplimiento cuando hay casilla y la fecha
     * cuando no la hay.
     *
     * @return list<array{clave: string, etiqueta: string, fecha: ?string, casilla: bool, cumplida: ?string, por: ?string, retraso: ?array{texto: string, aTiempo: bool}, marcada: bool}>
     */
    private function hitos(): array
    {
        $planeadas = BookingMilestones::de($this->bookingId);
        $cumplidas = Checklist::de($this->bookingId);
        $autores = $this->nombresDeUsuario(array_filter(array_column($cumplidas, 'por')));

        return MilestoneCatalog::activos()
            ->map(function (object $hito) use ($planeadas, $cumplidas, $autores) {
                $fecha = $planeadas[$hito->clave] ?? null;
                $casilla = Checklist::casilla($hito) !== null;
                $cumplida = $cumplidas[$hito->clave]['fecha'] ?? null;

                return [
                    'clave' => $hito->clave,
                    'etiqueta' => $hito->etiqueta,
                    'fecha' => $fecha,
                    'casilla' => $casilla,
                    'cumplida' => $cumplida,
                    'por' => $autores[$cumplidas[$hito->clave]['por'] ?? 0] ?? null,
                    'retraso' => $casilla ? Checklist::retraso($fecha, $cumplida) : null,
                    'marcada' => $casilla ? $cumplida !== null : $fecha !== null,
                ];
            })
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function nombresDeUsuario(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('usr_id', $ids)
            ->get(['usr_id', 'name', 'username'])
            ->mapWithKeys(fn (object $u) => [(int) $u->usr_id => (string) ($u->name ?: $u->username)])
            ->all();
    }

    /**
     * Marca un hito como cumplido ahora, o lo desmarca si ya estaba.
     *
     * Escribe el cumplimiento en `check_list` y NO toca la fecha planeada.
     * Reglas del original: marcar es de cualquier usuario interno, pero quien
     * no es administrador no puede saltarse la tarea anterior ni tocar una ya
     * marcada; desmarcar es de administradores.
     *
     * Un hito sin casilla (los que añade un negocio distinto) no tiene segunda
     * capa donde guardar el cumplimiento, así que su fecha hace de ambas y se
     * marca con la de hoy, como hasta ahora; quitarla sigue siendo de
     * administradores, como desmarcar.
     */
    public function marcaHito(string $clave): void
    {
        $this->assertAbierto();

        $hito = MilestoneCatalog::porClave($clave);
        abort_unless($hito?->activo ?? false, 404);

        $this->hitoEditando = null;
        $this->resetErrorBag('hito');

        if (Checklist::casilla($hito) === null) {
            $tenia = BookingMilestones::de($this->bookingId)[$clave] ?? null;

            if ($tenia !== null) {
                $this->assertAdmin();
            }

            BookingMilestones::guarda($this->bookingId, $clave, $tenia === null ? now()->toDateString() : null, auth()->id());

            return;
        }

        $this->alternaCasilla(Checklist::casilla($hito));
    }

    /**
     * Marca o desmarca una verificación de datos del booking (número, cliente,
     * buque…): las casillas que abren la lista en el original.
     */
    public function marcaDato(string $casilla): void
    {
        $this->assertAbierto();
        abort_unless(Checklist::conDatosDelBooking() && isset(Checklist::DATOS_DEL_BOOKING[$casilla]), 404);

        $this->resetErrorBag('hito');
        $this->alternaCasilla($casilla);
    }

    /**
     * La regla de marcado, común a hitos y datos: quien no es administrador va
     * en orden y no desmarca.
     */
    private function alternaCasilla(string $casilla): void
    {
        $esAdmin = auth()->user()?->isAdmin() ?? false;
        $marcadas = Checklist::casillasDe($this->bookingId);

        if (isset($marcadas[$casilla])) {
            abort_unless($esAdmin, 403, __('Solo un administrador puede desmarcar una tarea.'));
            Checklist::desmarcaCasilla($this->bookingId, $casilla, (int) auth()->id());

            return;
        }

        $anterior = Checklist::anterior($casilla);

        if (! $esAdmin && $anterior !== null && ! isset($marcadas[$anterior->casilla])) {
            $this->addError('hito', __('Primero hay que marcar «:hito».', ['hito' => $anterior->etiqueta]));

            return;
        }

        Checklist::marcaCasilla($this->bookingId, $casilla, (int) auth()->id());
    }

    /**
     * Las verificaciones de datos del booking, con el valor que se verifica y
     * su marca. Vacío donde no aplican (ver `Checklist::DATOS_DEL_BOOKING`).
     *
     * @return list<array{casilla: string, etiqueta: string, valor: ?string, cumplida: ?string, por: ?string}>
     */
    private function datosDelBooking(): array
    {
        if (! Checklist::conDatosDelBooking()) {
            return [];
        }

        $booking = $this->header();
        $extra = DB::table('booking as b')
            ->leftJoin('container_types as ct', 'ct.contType_id', '=', 'b.container_type')
            ->leftJoin('booking_continuity as bc', 'bc.booking', '=', 'b.booking_id')
            ->leftJoin('modality as m', 'm.modality_id', '=', 'bc.modality')
            ->where('b.booking_id', $this->bookingId)
            ->first(['ct.container_name', 'm.modality_name']);
        $fecha = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : null;

        $valores = [
            'booking_number' => $booking->booking_number,
            'client' => $booking->client_name,
            'vessel' => $booking->vessel_name,
            'loading_port' => $booking->port_name,
            'loading_EDT' => $fecha($booking->loading_EDT),
            'dicharge_port' => $booking->discharge_name,
            'dicharge_ETA' => $fecha($booking->dicharge_ETA),
            'container_type' => $extra?->container_name,
            'commodity' => $booking->commodity,
            'set_point' => $booking->set_point,
            'pick_up_place' => $booking->pickup_name,
            'modality' => $extra?->modality_name,
        ];

        $marcadas = Checklist::casillasDe($this->bookingId);
        $autores = $this->nombresDeUsuario(array_filter(array_column($marcadas, 'por')));
        $salida = [];

        foreach (Checklist::DATOS_DEL_BOOKING as $casilla => $etiqueta) {
            $salida[] = [
                'casilla' => $casilla,
                'etiqueta' => __($etiqueta),
                'valor' => $valores[$casilla] === null ? null : trim((string) $valores[$casilla]),
                'cumplida' => $marcadas[$casilla]['fecha'] ?? null,
                'por' => $autores[$marcadas[$casilla]['por'] ?? 0] ?? null,
            ];
        }

        return $salida;
    }

    // ------------------------------------------------------- Modalidad

    /**
     * La modalidad (CY/CY, SD/SD…) vive en `booking_continuity`, como en el
     * original, y se guarda en cuanto se elige. Cualquier usuario interno.
     */
    public function updatedModality(): void
    {
        $this->assertAbierto();

        $this->validate(['modality' => ['nullable', Rule::exists('modality', 'modality_id')]], attributes: ['modality' => __('modalidad')]);

        $valores = ['modality' => $this->modality === '' ? null : (int) $this->modality, 'modified_by' => auth()->id(), 'modified_at' => now()];
        $existente = DB::table('booking_continuity')->where('booking', $this->bookingId)->first();

        $existente === null
            ? DB::table('booking_continuity')->insert($valores + ['booking' => $this->bookingId])
            : DB::table('booking_continuity')->where('cont_id', $existente->cont_id)->update($valores);
    }

    // ------------------------------- Lista de verificación del booking

    /**
     * Las cinco fechas de la tabla `booking` (arribo, liberación de la
     * naviera, despacho, solicitud de transporte y entrega al consignatario):
     * el `_checklist.php` del formulario original. Son de administradores,
     * como el formulario donde vivían, y llevan fecha y hora.
     *
     * @return list<array{campo: string, etiqueta: string, fecha: ?string}>
     */
    private function listaDelBooking(): array
    {
        $fila = DB::table('booking')->where('booking_id', $this->bookingId)->first(Booking::LISTA_DE_VERIFICACION);

        return array_map(fn (string $campo) => [
            'campo' => $campo,
            'etiqueta' => __(BookingTimeline::etiqueta($campo)),
            'fecha' => $fila?->{$campo},
        ], Booking::LISTA_DE_VERIFICACION);
    }

    /** Un clic marca con la fecha y hora de ahora; otro clic la quita. */
    public function marcaDelBooking(string $campo): void
    {
        $this->assertEditable();
        abort_unless(in_array($campo, Booking::LISTA_DE_VERIFICACION, true), 404);

        $tenia = DB::table('booking')->where('booking_id', $this->bookingId)->value($campo);

        $this->escribeFechaDelBooking($campo, $tenia === null ? now()->format('Y-m-d H:i:s') : null);
    }

    public function editaFechaDelBooking(string $campo): void
    {
        $this->assertEditable();
        abort_unless(in_array($campo, Booking::LISTA_DE_VERIFICACION, true), 404);

        $this->bookingCheckEditando = $campo;
        $this->bookingCheckFecha = BookingMilestones::paraCaptura(DB::table('booking')->where('booking_id', $this->bookingId)->value($campo));
        $this->resetErrorBag();
    }

    public function guardaFechaDelBooking(): void
    {
        $this->assertEditable();

        $campo = (string) $this->bookingCheckEditando;
        abort_unless(in_array($campo, Booking::LISTA_DE_VERIFICACION, true), 404);

        $this->validate(
            ['bookingCheckFecha' => ['nullable', 'date']],
            attributes: ['bookingCheckFecha' => mb_strtolower(__(BookingTimeline::etiqueta($campo)))],
        );

        $this->escribeFechaDelBooking($campo, blank($this->bookingCheckFecha) ? null : Carbon::parse($this->bookingCheckFecha)->format('Y-m-d H:i:s'));
        $this->bookingCheckEditando = null;
    }

    public function cancelaFechaDelBooking(): void
    {
        $this->bookingCheckEditando = null;
    }

    private function escribeFechaDelBooking(string $campo, ?string $fecha): void
    {
        Booking::whereKey($this->bookingId)->update([$campo => $fecha, 'modified_by' => auth()->id()]);
    }

    /**
     * La fecha planeada la captura cualquier usuario interno, como el `setdate`
     * del original; con hora, como allá, y 00:00 si no se sabe.
     */
    public function editaHito(string $clave): void
    {
        $this->assertAbierto();
        abort_unless(MilestoneCatalog::porClave($clave)?->activo ?? false, 404);

        $this->hitoEditando = $clave;
        $this->hitoFecha = BookingMilestones::paraCaptura(BookingMilestones::de($this->bookingId)[$clave] ?? null);
        $this->resetErrorBag();
    }

    public function guardaHito(): void
    {
        $this->assertAbierto();

        $clave = (string) $this->hitoEditando;
        abort_unless(MilestoneCatalog::porClave($clave)?->activo ?? false, 404);

        $this->validate(
            ['hitoFecha' => ['nullable', 'date']],
            attributes: ['hitoFecha' => mb_strtolower(MilestoneCatalog::porClave($clave)->etiqueta)],
        );

        BookingMilestones::guarda($this->bookingId, $clave, $this->hitoFecha ?: null, auth()->id());

        $this->hitoEditando = null;
    }

    public function cancelaHito(): void
    {
        $this->hitoEditando = null;
    }

    // ---------------------------------------------------- Contenedores

    /** Un booking cerrado ya no recibe movimientos de carga. */
    private function assertEditable(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        abort_if((bool) $this->header()->locked, 403, __('Este booking está cerrado.'));
    }

    public function addContainer(): void
    {
        $this->assertEditable();
        $this->resetContainerForm();
        $this->editingContainer = true;
    }

    public function editContainer(int $container): void
    {
        $this->assertEditable();

        $fila = DB::table('containers')
            ->where('booking', $this->bookingId)
            ->where('container_ID', $container)
            ->first();

        abort_if($fila === null, 404);

        $this->containerId = (int) $fila->container_ID;
        $this->containerNumber = (string) $fila->number;
        $this->containerSeal = (string) $fila->seal;
        $this->containerType = $this->asOption($fila->container_type);
        $this->containerQuantity = (string) ($fila->quantity ?? 1);
        $this->containerCommodity = (string) $fila->comodity;
        $this->containerPickup = $fila->pick_up_date
            ? Carbon::parse($fila->pick_up_date)->toDateString()
            : '';
        $this->editingContainer = true;
        $this->resetErrorBag();
    }

    public function saveContainer(): void
    {
        $this->assertEditable();

        // Tipo y mercancía son obligatorios porque en la tabla `containers` son
        // NOT NULL sin default: validarlos como opcionales hacía fallar el
        // insert en MySQL con un error que el usuario no entendía.
        $datos = $this->validate([
            'containerNumber' => ['nullable', 'string', 'max:50'],
            'containerSeal' => ['nullable', 'string', 'max:50'],
            'containerType' => ['required', Rule::exists('container_types', 'contType_id')],
            'containerQuantity' => ['required', 'integer', 'min:1'],
            'containerCommodity' => ['required', 'string', 'max:25'],
            'containerPickup' => ['nullable', 'date'],
        ], attributes: [
            'containerNumber' => __('número'),
            'containerSeal' => 'sello',
            'containerType' => 'tipo',
            'containerQuantity' => 'cantidad',
            'containerCommodity' => __('mercancía'),
            'containerPickup' => __('fecha de recolección'),
        ]);

        // `created_at` y `modified_at` son de tipo DATE en la tabla, no datetime.
        $valores = [
            'booking' => $this->bookingId,
            'number' => $datos['containerNumber'] ?: null,
            'seal' => $datos['containerSeal'] ?: null,
            'container_type' => (int) $datos['containerType'],
            'quantity' => (int) $datos['containerQuantity'],
            'comodity' => $datos['containerCommodity'],
            'pick_up_date' => $datos['containerPickup'] ?: null,
            'modified_by' => auth()->id(),
            'modified_at' => now()->toDateString(),
        ];

        if ($this->containerId === null) {
            DB::table('containers')->insert($valores + ['created_by' => auth()->id(), 'created_at' => now()->toDateString()]);
        } else {
            DB::table('containers')->where('container_ID', $this->containerId)->update($valores);
        }

        $this->resetContainerForm();
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    /** Un viaje cerrado no recibe gastos: su facturación ya quedó fija. */
    private function assertAbierto(): void
    {
        abort_if((bool) $this->header()->locked, 403, __('Este booking está cerrado.'));
    }

    /**
     * Gastos de carretera del viaje: combustible, casetas y lo demás.
     *
     * Solo con flota propia: quien subcontrata el transporte no paga diésel, lo
     * paga su proveedor y le llega en la factura.
     */
    public function gastos(): Collection
    {
        return TripExpenses::delViaje($this->bookingId);
    }

    /** @return array{combustible: float, caseta: float, otro: float, total: float} */
    public function totalesGastos(): array
    {
        return TripExpenses::totales($this->bookingId);
    }

    public function editGasto(?int $gasto): void
    {
        $this->assertAdmin();
        $this->resetGasto();

        if ($gasto === null) {
            $this->gastoFecha = now()->toDateString();

            return;
        }

        $fila = DB::table('gasto_viaje')->where('gasto_id', $gasto)->where('booking', $this->bookingId)->first();
        abort_if($fila === null, 404);

        $this->gastoId = (int) $fila->gasto_id;
        $this->gastoTipo = (string) $fila->tipo;
        $this->gastoFecha = (string) $fila->fecha;
        $this->gastoDescripcion = (string) ($fila->descripcion ?? '');
        $this->gastoImporte = (string) $fila->importe;
        $this->gastoLitros = (string) ($fila->litros ?? '');
        $this->gastoOdometro = (string) ($fila->odometro ?? '');
        $this->gastoFolio = (string) ($fila->folio ?? '');
    }

    public function saveGasto(): void
    {
        $this->assertAdmin();
        $this->assertAbierto();

        $datos = $this->validate([
            'gastoTipo' => ['required', 'in:combustible,caseta,otro'],
            'gastoFecha' => ['required', 'date'],
            'gastoDescripcion' => ['nullable', 'string', 'max:120'],
            'gastoImporte' => ['required', 'numeric', 'min:0'],
            // Los litros y el odómetro solo tienen sentido en una carga: pedirlos
            // en una caseta sería ruido, y guardarlos, basura.
            'gastoLitros' => ['nullable', 'numeric', 'min:0', 'required_if:gastoTipo,combustible'],
            'gastoOdometro' => ['nullable', 'integer', 'min:0'],
            'gastoFolio' => ['nullable', 'string', 'max:40'],
        ], attributes: [
            'gastoTipo' => __('tipo de gasto'),
            'gastoFecha' => __('fecha'),
            'gastoImporte' => __('importe'),
            'gastoLitros' => __('litros'),
            'gastoOdometro' => __('odómetro'),
        ]);

        $esCombustible = $datos['gastoTipo'] === 'combustible';
        $litros = $esCombustible && $datos['gastoLitros'] !== '' ? (float) $datos['gastoLitros'] : null;
        $importe = (float) $datos['gastoImporte'];

        $valores = [
            'booking' => $this->bookingId,
            'tipo' => $datos['gastoTipo'],
            'fecha' => $datos['gastoFecha'],
            'descripcion' => $datos['gastoDescripcion'] ?: null,
            'importe' => $importe,
            'litros' => $litros,
            // El precio por litro se calcula y no se captura: pedirlo por
            // separado invita a que no cuadre con el importe.
            'precio_litro' => $litros > 0 ? round($importe / $litros, 4) : null,
            'odometro' => $esCombustible && $datos['gastoOdometro'] !== '' ? (int) $datos['gastoOdometro'] : null,
            'folio' => $datos['gastoFolio'] ?: null,
            // Se heredan del viaje: quien carga es quien lo trae asignado.
            'unidad_id' => $this->header()->unidad_id ?? null,
            'operador_id' => $this->header()->operador_id ?? null,
        ];

        if ($this->gastoId === null) {
            DB::table('gasto_viaje')->insert($valores + ['created_by' => auth()->id(), 'created_at' => now()]);
        } else {
            DB::table('gasto_viaje')->where('gasto_id', $this->gastoId)->update($valores);
        }

        $this->resetGasto();
        session()->flash('status', __('Gasto guardado.'));
    }

    public function deleteGasto(int $gasto): void
    {
        $this->assertAdmin();
        $this->assertAbierto();

        DB::table('gasto_viaje')->where('gasto_id', $gasto)->where('booking', $this->bookingId)->delete();
    }

    public function resetGasto(): void
    {
        $this->reset(['gastoId', 'gastoTipo', 'gastoDescripcion', 'gastoImporte',
            'gastoLitros', 'gastoOdometro', 'gastoFolio']);
        $this->gastoTipo = 'combustible';
        $this->resetErrorBag();
    }

    public function deleteContainer(int $container): void
    {
        $this->assertEditable();

        $fila = DB::table('containers')
            ->where('booking', $this->bookingId)
            ->where('container_ID', $container);

        // Antes de borrar se firma quién lo hace, como el original: la bitácora
        // `containers_history` la escribe un disparador de la base a partir del
        // renglón, y sin esto el renglón de baja quedaba sin autor.
        $fila->update(['modified_by' => auth()->id(), 'modified_at' => now()->toDateString()]);
        $fila->delete();

        $this->resetContainerForm();
    }

    public function cancelContainerEdit(): void
    {
        $this->resetContainerForm();
    }

    private function resetContainerForm(): void
    {
        $this->reset([
            'editingContainer', 'containerId', 'containerNumber', 'containerSeal',
            'containerType', 'containerCommodity', 'containerPickup',
        ]);
        $this->containerQuantity = '1';
        $this->resetErrorBag();
    }

    private function asOption(mixed $valor): string
    {
        return $valor === null ? '' : (string) $valor;
    }

    // ------------------------------------------------------------ Cierre

    /**
     * Cierra el booking: operación lo da por terminado y su facturación queda
     * fija. Se puede reabrir, pero es una decisión consciente.
     */
    /**
     * Vuelve a mandarle al cliente la confirmación en PDF.
     *
     * En Yii2 esto era `actionMail`, que mandaba el correo **a una dirección
     * escrita en el código** (la de Héctor) y de paso abría el PDF. Aquí va a
     * quien corresponde: los correos de notificación del cliente, los mismos que
     * reciben el aviso de alta.
     */
    /**
     * Confirma el booking: deja de ser borrador y, si no es cotización, le
     * manda al cliente la confirmación en PDF, ya con sus contenedores.
     *
     * Es el «Confirm & Save» del original, que allá vivía en `update` y por eso
     * era de administradores; aquí igual.
     */
    public function confirm(SendBookingConfirmation $enviar): void
    {
        $this->assertAdmin();
        $this->assertAbierto();

        $modelo = Booking::findOrFail($this->bookingId);

        if (! $modelo->is_draft) {
            return;
        }

        $modelo->forceFill(['is_draft' => 0, 'modified_by' => auth()->id()])->save();

        $avisados = $modelo->isQuotation() ? [] : $enviar->handle($modelo);

        session()->flash('status', match (true) {
            $modelo->isQuotation() => __('Cotización confirmada.'),
            $avisados === [] => __('Booking confirmado. No se mandó la confirmación: el cliente no tiene correos de notificación.'),
            default => __('Booking confirmado. Se mandó la confirmación a ').implode(', ', $avisados).'.',
        });
    }

    public function sendConfirmation(SendBookingConfirmation $enviar): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $avisados = $enviar->handle(Booking::findOrFail($this->bookingId));

        session()->flash('status', $avisados === []
            ? __('No se mandó: el cliente no tiene correos de notificación.')
            : __('Confirmación enviada a ').implode(', ', $avisados).'.');
    }

    /**
     * Manda la misma confirmación al correo de quien la pide, sin tocar al
     * cliente: para revisarla antes de mandarla de verdad.
     *
     * El `mail` del original hacía esto mandándola a una dirección fija escrita
     * en el código; aquí va a la de cada quien, y como allá, la pide cualquier
     * usuario interno.
     */
    public function sendConfirmationToMe(SendBookingConfirmation $enviar): void
    {
        $correo = trim((string) auth()->user()?->email);

        if ($correo === '') {
            session()->flash('error', __('Tu usuario no tiene correo: no hay a dónde mandarte la copia.'));

            return;
        }

        $avisados = $enviar->handle(Booking::findOrFail($this->bookingId), [$correo]);

        session()->flash('status', $avisados === []
            ? __('No se pudo mandar la copia. Revisa el registro del sistema.')
            : __('Copia enviada a ').$correo.'.');
    }

    /**
     * Borra el booking.
     *
     * El original lo borra sin preguntar nada; aquí se niega si tiene
     * facturación viva. Un booking con documentos financieros colgando no debe
     * desaparecer de debajo de ellos, y recuperarlo después no es posible: el
     * borrado es físico, como en el original.
     *
     * Lo que cuelga del booking —contenedores, lista de verificación,
     * continuidad— tampoco se borra en el original y aquí se conserva igual: la
     * bitácora de la base guarda el renglón de baja.
     */
    public function delete(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        // La vista ya esconde el botón, pero la acción se puede llamar directa.
        abort_if((bool) $this->header()->locked, 422, __('Este booking está cerrado.'));

        $facturacion = DB::table('transaction')
            ->where('booking', $this->bookingId)
            ->where('cancelled', 0)
            ->count();

        if ($facturacion > 0) {
            session()->flash('error', trans_choice(
                '{1}No se puede borrar: el booking tiene :count transacción sin cancelar.'
                .'|[2,*]No se puede borrar: el booking tiene :count transacciones sin cancelar.',
                $facturacion,
                ['count' => $facturacion],
            ));

            return;
        }

        Booking::findOrFail($this->bookingId)->delete();

        session()->flash('status', __('Booking borrado.'));
        $this->redirectRoute('operations.bookings', navigate: true);
    }

    /**
     * Cerrar y reabrir son del super administrador, como `lock` y `unlock` en
     * el original: el cierre fija la facturación y no es una decisión de
     * operación.
     */
    public function lock(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        Booking::whereKey($this->bookingId)->update(['locked' => 1, 'modified_by' => auth()->id()]);

        session()->flash('status', __('Booking cerrado.'));
    }

    public function unlock(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        Booking::whereKey($this->bookingId)->update(['locked' => 0, 'modified_by' => auth()->id()]);

        session()->flash('status', __('Booking reabierto.'));
    }

    // -------------------------------------------------------- Documentos

    public function chooseField(int $fieldId): void
    {
        $this->assertEditable();

        $this->uploadField = $fieldId;
        $this->upload = null;
        $this->resetErrorBag();
    }

    /** Livewire sube el archivo en cuanto se elige; aquí se guarda al vuelo. */
    public function updatedUpload(): void
    {
        $this->assertEditable();

        if ($this->uploadField === null) {
            return;
        }

        // Las extensiones del original: lo que operación adjunta son PDF,
        // fotos, comprimidos, XML de factura y hojas de Office. Se mira la
        // extensión y no el contenido, como allá, porque un XML o un ZIP se
        // adivinan mal por contenido y el rechazo sería inexplicable.
        $this->validate(
            ['upload' => ['required', 'file', 'max:20480', 'extensions:'.implode(',', BookingFiles::EXTENSIONES)]],
            attributes: ['upload' => 'archivo'],
        );

        app(BookingFiles::class)->store($this->bookingId, $this->uploadField, $this->upload);

        $this->reset(['upload', 'uploadField']);
        session()->flash('status', __('Documento adjuntado.'));
    }

    public function removeFile(int $fieldId, string $nombre): void
    {
        $this->assertEditable();

        app(BookingFiles::class)->remove($this->bookingId, $fieldId, $nombre);
    }

    public function render()
    {
        $this->headerCache = null;
        $fila = $this->header();

        return view('livewire.operations.booking-detail', [
            'booking' => $fila,
            'contenedores' => $this->containers(),
            'transacciones' => $this->transactions(),
            'hitos' => $this->hitos(),
            'datosDelBooking' => $this->datosDelBooking(),
            'listaDelBooking' => $this->listaDelBooking(),
            'modalidades' => Expediente::usa('maritimo') && Schema::hasTable('modality')
                ? DB::table('modality')->orderBy('modality_id')->pluck('modality_name', 'modality_id')->all()
                : [],
            'tiposContenedor' => DB::table('container_types')->orderBy('container_name')->pluck('container_name', 'contType_id')->all(),
            'documentos' => app(BookingFiles::class)->fieldsFor(
                $this->bookingId,
                DB::table('booking')->where('booking_id', $this->bookingId)->value('client'),
            ),
        ])->layout('components.app-layout', [
            'title' => trim((string) $fila->booking_number) ?: 'Booking '.$this->bookingId,
        ]);
    }
}
