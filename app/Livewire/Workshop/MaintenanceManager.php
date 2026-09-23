<?php

namespace App\Livewire\Workshop;

use App\Support\Workshop\Inventory;
use App\Support\Workshop\Maintenance;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Órdenes de mantenimiento: qué se le hizo a cada unidad y qué costó.
 *
 * Poner una refacción en una orden **la saca del almacén** en el mismo acto, y
 * quitarla la devuelve: es lo que evita el inventario que nunca cuadra, que es
 * el motivo por el que casi nadie usa el almacén que ya tiene.
 */
class MaintenanceManager extends Component
{
    use WithPagination;

    public ?int $abierta = null;

    public bool $creando = false;

    // --- Alta de la orden ---
    public string $unidad = '';

    public string $tipo = 'preventivo';

    public string $entrada = '';

    public string $odometro = '';

    public string $taller = 'interno';

    public string $proveedor = '';

    public string $descripcion = '';

    public string $manoObra = '';

    // --- Refacción que se pone ---
    public string $refaccion = '';

    public string $cantidad = '1';

    // --- Filtros ---
    public string $estadoFiltro = '';

    public string $unidadFiltro = '';

    public function mount(): void
    {
        $this->assertAdmin();
        $this->entrada = now()->toDateString();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    /** Una orden cerrada no se toca: sus refacciones ya salieron del almacén. */
    private function assertAbierta(int $id): object
    {
        $orden = DB::table('mantenimiento')->where('mantenimiento_id', $id)->first();

        abort_if($orden === null, 404);
        abort_if($orden->estado === 'cerrado', 422, __('Esta orden ya está cerrada.'));

        return $orden;
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['estadoFiltro', 'unidadFiltro'], true)) {
            $this->resetPage();
        }
    }

    public function crear(): void
    {
        $this->assertAdmin();

        $this->validate([
            'unidad' => ['required', 'exists:unidad,unidad_id'],
            'tipo' => ['required', 'in:preventivo,correctivo'],
            'entrada' => ['required', 'date'],
            'odometro' => ['nullable', 'integer', 'min:0'],
            'taller' => ['required', 'in:interno,externo'],
            'proveedor' => ['nullable', 'exists:provider,provider_id'],
            'descripcion' => ['required', 'string', 'max:200'],
            'manoObra' => ['nullable', 'numeric', 'min:0'],
        ], attributes: [
            'unidad' => mb_strtolower(__('Unidad')),
            'descripcion' => mb_strtolower(__('Descripción')),
            'odometro' => mb_strtolower(__('Odómetro')),
        ]);

        $id = DB::table('mantenimiento')->insertGetId([
            'folio' => Maintenance::siguienteFolio(),
            'unidad_id' => (int) $this->unidad,
            'tipo' => $this->tipo,
            'estado' => 'abierto',
            'entrada' => $this->entrada,
            'odometro' => $this->odometro === '' ? null : (int) $this->odometro,
            'taller' => $this->taller,
            'provider_id' => $this->taller === 'externo' && $this->proveedor !== '' ? (int) $this->proveedor : null,
            'descripcion' => $this->descripcion,
            'mano_obra' => (float) ($this->manoObra ?: 0),
            'created_by' => auth()->id(),
            'created_at' => now(),
        ], 'mantenimiento_id');

        $this->reset(['descripcion', 'manoObra', 'odometro', 'proveedor']);
        $this->creando = false;
        $this->abierta = $id;
        session()->flash('status', __('Orden de mantenimiento abierta.'));
    }

    /**
     * Pone una refacción en la orden y la descuenta del almacén.
     *
     * Se permite dejar la existencia en negativo a propósito: en un taller la
     * pieza se pone y el papel llega después, y bloquear la captura solo
     * consigue que se deje de capturar. El descuadre se ve en el almacén.
     */
    public function agregarRefaccion(): void
    {
        $this->assertAdmin();
        abort_if($this->abierta === null, 404);
        $this->assertAbierta($this->abierta);

        $this->validate([
            'refaccion' => ['required', 'exists:refaccion,refaccion_id'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
        ], attributes: [
            'refaccion' => mb_strtolower(__('Refacción')),
            'cantidad' => mb_strtolower(__('Cantidad')),
        ]);

        $pieza = DB::table('refaccion')->where('refaccion_id', (int) $this->refaccion)->first();

        DB::table('mantenimiento_refaccion')->insert([
            'mantenimiento_id' => $this->abierta,
            'refaccion_id' => (int) $this->refaccion,
            'cantidad' => (float) $this->cantidad,
            // El costo se congela aquí: el del almacén cambia con la siguiente
            // compra y esta orden ya no debería moverse.
            'costo' => (float) $pieza->costo,
        ]);

        Inventory::mueve(
            (int) $this->refaccion,
            'salida',
            (float) $this->cantidad,
            (float) $pieza->costo,
            null,
            ['mantenimiento_id' => $this->abierta],
            auth()->id(),
        );

        $this->reset(['refaccion']);
        $this->cantidad = '1';
    }

    public function quitarRefaccion(int $renglon): void
    {
        $this->assertAdmin();

        $fila = DB::table('mantenimiento_refaccion')->where('renglon_id', $renglon)->first();
        abort_if($fila === null, 404);
        $this->assertAbierta((int) $fila->mantenimiento_id);

        DB::table('mantenimiento_refaccion')->where('renglon_id', $renglon)->delete();

        // Vuelve al almacén: si no, quitar un renglón mal capturado dejaría la
        // pieza descontada para siempre.
        Inventory::mueve(
            (int) $fila->refaccion_id,
            'entrada',
            (float) $fila->cantidad,
            null,
            null,
            ['mantenimiento_id' => (int) $fila->mantenimiento_id, 'notas' => __('Devolución de la orden')],
            auth()->id(),
        );
    }

    public function cerrar(int $id): void
    {
        $this->assertAdmin();
        $this->assertAbierta($id);

        Maintenance::cierra($id, now()->toDateString(), auth()->id());
        session()->flash('status', __('Orden cerrada. La unidad quedó con su servicio al día.'));
    }

    public function ver(?int $id): void
    {
        $this->abierta = $this->abierta === $id ? null : $id;
    }

    public function render()
    {
        $ordenes = DB::table('mantenimiento as t')
            ->leftJoin('unidad as u', 'u.unidad_id', '=', 't.unidad_id')
            ->leftJoin('provider as p', 'p.provider_id', '=', 't.provider_id')
            ->when($this->estadoFiltro !== '', fn ($q) => $q->where('t.estado', $this->estadoFiltro))
            ->when($this->unidadFiltro !== '', fn ($q) => $q->where('t.unidad_id', (int) $this->unidadFiltro))
            ->orderByDesc('t.mantenimiento_id')
            ->paginate(12, ['t.*', 'u.numero as unidad', 'p.fullName as proveedor']);

        return view('livewire.workshop.maintenance-manager', [
            'ordenes' => $ordenes,
            'renglones' => $this->abierta === null ? collect() : Maintenance::refacciones($this->abierta),
            'totales' => $this->abierta === null ? null : Maintenance::totales($this->abierta),
            'porServicio' => Maintenance::porServicio(),
            'unidades' => DB::table('unidad')->where('activo', 1)->orderBy('numero')->pluck('numero', 'unidad_id')->all(),
            'proveedores' => DB::table('provider')->orderBy('fullName')->limit(200)->pluck('fullName', 'provider_id')->all(),
            'refacciones' => DB::table('refaccion')->where('activo', 1)->orderBy('nombre')
                ->get(['refaccion_id', 'codigo', 'nombre', 'existencia', 'medida']),
        ])->layout('components.app-layout', ['title' => __('Mantenimiento')]);
    }
}
