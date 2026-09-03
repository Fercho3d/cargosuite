<?php

namespace App\Livewire\Operations;

use App\Actions\Bookings\SendBookingConfirmation;
use App\Models\Core\Booking;
use App\Queries\BookingFilters;
use App\Queries\BookingQuery;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\BookingFiles;
use App\Support\Fleet\TripExpenses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    public ?int $gastoId = null;

    public string $gastoTipo = 'combustible';

    public string $gastoFecha = '';

    public string $gastoDescripcion = '';

    public string $gastoImporte = '';

    public string $gastoLitros = '';

    public string $gastoOdometro = '';

    public string $gastoFolio = '';

    public string $containerPickup = '';

    // --- Instrucciones de embarque ---
    /** @var array<string, string|null> */
    public array $instructions = [];

    public bool $editingInstructions = false;

    // --- Documentos del booking ---
    /** Campo al que se está subiendo, para no mezclar los archivos. */
    public ?int $uploadField = null;

    public $upload = null;

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
        foreach ([10, 9] as $modo) {
            $filtros = BookingFilters::make([]);
            $filtros->mode = $modo;

            $fila = BookingQuery::make($filtros)->query()
                ->where('b.booking_id', $this->bookingId)
                ->first();

            if ($fila !== null) {
                return $fila;
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
     * Las casillas de la lista de verificación con la fecha en que se marcaron.
     *
     * @return array<string, string|null>
     */
    private function checklist(): array
    {
        $fila = DB::table('check_list')->where('booking', $this->bookingId)->first();

        if ($fila === null) {
            return [];
        }

        $etiquetas = [
            'booking_number' => __('Número de booking'), 'pickup_date' => __('Fecha de recolección'),
            'modality' => 'Modalidad', 'doc_cut_of' => 'Corte documental', 'SI_date' => __('Instrucciones de embarque'),
            'cleared' => 'Despacho aduanal', 'departure' => 'Zarpe', 'bl_payment' => __('Pago del BL'),
            'swb' => 'SWB', 'vessel' => __('Buque'), 'number' => __('Número'), 'client' => __('Cliente'),
            'loading_port' => __('Puerto de carga'), 'loading_EDT' => __('Fecha de carga'),
            'dicharge_port' => __('Puerto de descarga'), 'container_type' => __('Tipo de contenedor'),
            'commodity' => __('Mercancía'), 'set_point' => 'Temperatura', 'dicharge_ETA' => 'Arribo estimado',
            'vacuum_maneuver' => __('Maniobra de vacío'), 'draf_client' => __('Draft del cliente'),
            'gated_IN' => 'Gate in', 'gated_out' => 'Gate out', 'delivered' => 'Entregado',
            'insurance' => 'Seguro', 'corrected_draft' => 'Draft corregido', 'vgm' => 'VGM',
        ];

        return collect($etiquetas)
            ->mapWithKeys(fn (string $etiqueta, string $campo) => [
                $etiqueta => $fila->{$campo.'_chk_date'} ?? null,
            ])
            ->all();
    }

    // ------------------------------------------------------- Contenedores

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

        $datos = $this->validate([
            'containerNumber' => ['nullable', 'string', 'max:50'],
            'containerSeal' => ['nullable', 'string', 'max:50'],
            'containerType' => ['nullable', Rule::exists('container_types', 'contType_id')],
            'containerQuantity' => ['required', 'integer', 'min:1'],
            'containerCommodity' => ['nullable', 'string', 'max:25'],
            'containerPickup' => ['nullable', 'date'],
        ], attributes: [
            'containerNumber' => __('número'),
            'containerSeal' => 'sello',
            'containerType' => 'tipo',
            'containerQuantity' => 'cantidad',
            'containerCommodity' => __('mercancía'),
            'containerPickup' => __('fecha de recolección'),
        ]);

        $valores = [
            'booking' => $this->bookingId,
            'number' => $datos['containerNumber'] ?: null,
            'seal' => $datos['containerSeal'] ?: null,
            'container_type' => $datos['containerType'] === '' ? null : (int) $datos['containerType'],
            'quantity' => (int) $datos['containerQuantity'],
            'comodity' => $datos['containerCommodity'] ?: null,
            'pick_up_date' => $datos['containerPickup'] ?: null,
            'modified_by' => auth()->id(),
        ];

        if ($this->containerId === null) {
            DB::table('containers')->insert($valores + ['created_by' => auth()->id(), 'created_at' => now()]);
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

        DB::table('containers')
            ->where('booking', $this->bookingId)
            ->where('container_ID', $container)
            ->delete();

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
    public function sendConfirmation(SendBookingConfirmation $enviar): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $avisados = $enviar->handle(Booking::findOrFail($this->bookingId));

        session()->flash('status', $avisados === []
            ? __('No se mandó: el cliente no tiene correos de notificación.')
            : __('Confirmación enviada a ').implode(', ', $avisados).'.');
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

    public function lock(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        Booking::whereKey($this->bookingId)->update(['locked' => 1, 'modified_by' => auth()->id()]);

        $this->headerCache = null;
        session()->flash('status', __('Booking cerrado.'));
    }

    public function unlock(): void
    {
        // Reabrir permite volver a tocar importes ya conciliados, así que es
        // exclusivo del super administrador.
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        Booking::whereKey($this->bookingId)->update(['locked' => 0, 'modified_by' => auth()->id()]);

        $this->headerCache = null;
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

        $this->validate(
            ['upload' => ['required', 'file', 'max:20480']],
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
        $fila = $this->header();

        return view('livewire.operations.booking-detail', [
            'booking' => $fila,
            'contenedores' => $this->containers(),
            'transacciones' => $this->transactions(),
            'checklist' => $this->checklist(),
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
