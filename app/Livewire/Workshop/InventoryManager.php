<?php

namespace App\Livewire\Workshop;

use App\Support\Workshop\Inventory;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Almacén del taller: qué refacciones hay, qué falta y a dónde se fue cada una.
 *
 * Las refacciones se dan de alta en Catálogos; aquí se mueve la existencia
 * —compras, salidas sueltas y el ajuste del conteo físico— y se consulta el
 * kárdex, que es lo que se pide cuando el inventario no cuadra.
 */
class InventoryManager extends Component
{
    use WithPagination;

    public string $buscar = '';

    public bool $soloFaltantes = false;

    public ?int $verKardex = null;

    // --- Movimiento ---
    public ?int $moviendo = null;

    public string $tipo = 'entrada';

    public string $cantidad = '';

    public string $costo = '';

    public string $fecha = '';

    public string $proveedor = '';

    public string $folio = '';

    public function mount(): void
    {
        $this->assertAdmin();
        $this->fecha = now()->toDateString();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'soloFaltantes'], true)) {
            $this->resetPage();
        }
    }

    public function mover(int $refaccion, string $tipo = 'entrada'): void
    {
        $this->assertAdmin();
        abort_unless(DB::table('refaccion')->where('refaccion_id', $refaccion)->exists(), 404);

        $this->moviendo = $refaccion;
        $this->tipo = $tipo;
        $this->cantidad = '';
        $this->costo = (string) (DB::table('refaccion')->where('refaccion_id', $refaccion)->value('costo') ?: '');
        $this->fecha = now()->toDateString();
        $this->resetErrorBag();
    }

    public function guardarMovimiento(): void
    {
        $this->assertAdmin();
        abort_if($this->moviendo === null, 404);

        $this->validate([
            'tipo' => ['required', 'in:entrada,salida,ajuste'],
            // El ajuste deja la existencia en lo contado, y un conteo puede dar
            // cero: por eso admite el cero y los otros dos no.
            'cantidad' => ['required', 'numeric', $this->tipo === 'ajuste' ? 'min:0' : 'gt:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'fecha' => ['required', 'date'],
            'proveedor' => ['nullable', 'exists:provider,provider_id'],
        ], attributes: [
            'cantidad' => mb_strtolower(__('Cantidad')),
            'costo' => mb_strtolower(__('Costo')),
        ]);

        Inventory::mueve(
            $this->moviendo,
            $this->tipo,
            (float) $this->cantidad,
            $this->costo === '' ? null : (float) $this->costo,
            $this->fecha,
            [
                'provider_id' => $this->proveedor === '' ? null : (int) $this->proveedor,
                'folio' => $this->folio ?: null,
            ],
            auth()->id(),
        );

        $this->reset(['moviendo', 'cantidad', 'costo', 'proveedor', 'folio']);
        session()->flash('status', __('Movimiento registrado.'));
    }

    public function cancelar(): void
    {
        $this->reset(['moviendo', 'cantidad', 'costo', 'proveedor', 'folio']);
    }

    public function kardex(?int $refaccion): void
    {
        $this->verKardex = $this->verKardex === $refaccion ? null : $refaccion;
    }

    /** Para el conteo físico: se imprime, se cuenta y se ajusta. */
    public function exportar(): StreamedResponse
    {
        $this->assertAdmin();

        $filas = DB::table('refaccion')->where('activo', 1)->orderBy('nombre')->get();

        return response()->streamDownload(function () use ($filas) {
            $salida = fopen('php://output', 'wb');

            // BOM: sin él Excel se come los acentos.
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Codigo', 'Refaccion', 'Categoria', 'Ubicacion', 'Medida', 'Existencia', 'Minimo', 'Costo', 'Valor']);

            foreach ($filas as $f) {
                fputcsv($salida, [$f->codigo, $f->nombre, $f->categoria, $f->ubicacion, $f->medida,
                    number_format((float) $f->existencia, 2, '.', ''),
                    number_format((float) $f->minimo, 2, '.', ''),
                    number_format((float) $f->costo, 2, '.', ''),
                    number_format((float) $f->existencia * (float) $f->costo, 2, '.', '')]);
            }

            fclose($salida);
        }, 'almacen-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $refacciones = DB::table('refaccion')
            ->where('activo', 1)
            ->when($this->buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'like', '%'.$this->buscar.'%')
                ->orWhere('codigo', 'like', '%'.$this->buscar.'%')
                ->orWhere('categoria', 'like', '%'.$this->buscar.'%')))
            ->when($this->soloFaltantes, fn ($q) => $q->where('minimo', '>', 0)->whereColumn('existencia', '<=', 'minimo'))
            ->orderBy('nombre')
            ->paginate(20);

        return view('livewire.workshop.inventory-manager', [
            'refacciones' => $refacciones,
            'faltantes' => Inventory::bajoMinimo()->count(),
            'valor' => Inventory::valor(),
            'movimientos' => $this->verKardex === null ? collect() : Inventory::kardex($this->verKardex),
            'proveedores' => DB::table('provider')->orderBy('fullName')->limit(200)->pluck('fullName', 'provider_id')->all(),
        ])->layout('components.app-layout', ['title' => __('Almacén')]);
    }
}
