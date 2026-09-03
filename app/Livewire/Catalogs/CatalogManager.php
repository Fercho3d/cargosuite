<?php

namespace App\Livewire\Catalogs;

use App\Support\Catalogs\CatalogDefinition;
use App\Support\Catalogs\CatalogField;
use App\Support\Catalogs\CatalogRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Alta, baja y edición de cualquier catálogo maestro.
 *
 * Es una sola pantalla para las dieciséis opciones del menú «Options» del
 * sistema original: lo que cambia entre una y otra son los campos, y esos vienen
 * de `CatalogRegistry`. Trabaja con el constructor de consultas y no con modelos
 * porque cada catálogo es una tabla distinta y no aporta nada tener dieciséis
 * clases que solo declaran su nombre.
 */
class CatalogManager extends Component
{
    use WithPagination;

    public string $slug;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Id del renglón en edición, 0 para uno nuevo, null si no hay formulario. */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(string $catalog): void
    {
        $this->slug = $catalog;
        CatalogRegistry::find($catalog);
    }

    public function definition(): CatalogDefinition
    {
        return CatalogRegistry::find($this->slug);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    // ------------------------------------------------------------ Edición

    public function create(): void
    {
        $this->assertAdmin();

        $this->editing = 0;
        $this->form = collect($this->definition()->fields)
            ->mapWithKeys(fn (CatalogField $c) => [$c->name => $c->isBoolean() ? false : ''])
            ->all();
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->assertAdmin();

        $definicion = $this->definition();

        $fila = DB::table($definicion->table)->where($definicion->key, $id)->first();

        abort_if($fila === null, 404);

        $this->editing = $id;
        $this->form = collect($definicion->fields)
            ->mapWithKeys(fn (CatalogField $c) => [
                $c->name => $c->isBoolean() ? (bool) ($fila->{$c->name} ?? false) : ($fila->{$c->name} ?? ''),
            ])
            ->all();
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->form = [];
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->assertAdmin();

        $definicion = $this->definition();

        $this->validate(
            collect($definicion->fields)->mapWithKeys(fn (CatalogField $c) => ["form.{$c->name}" => $c->rules])->all(),
            attributes: collect($definicion->fields)
                ->mapWithKeys(fn (CatalogField $c) => ["form.{$c->name}" => mb_strtolower($c->label)])
                ->all(),
        );

        $valores = collect($definicion->fields)
            ->mapWithKeys(fn (CatalogField $c) => [$c->name => $c->cast($this->form[$c->name] ?? null)])
            ->all();

        $this->editing === 0
            ? $this->insert($definicion, $valores)
            : $this->update($definicion, $valores);

        session()->flash('status', $definicion->singular.($this->editing === 0 ? ' agregado.' : ' actualizado.'));
        $this->cancel();
    }

    /** @param  array<string, mixed>  $valores */
    private function insert(CatalogDefinition $definicion, array $valores): void
    {
        if ($definicion->softDelete !== null) {
            $valores[$definicion->softDelete] = 0;
        }

        if ($definicion->audited) {
            $valores['created_by'] = auth()->id();
            $valores['created_at'] = now();
        }

        DB::table($definicion->table)->insert($valores);
    }

    /** @param  array<string, mixed>  $valores */
    private function update(CatalogDefinition $definicion, array $valores): void
    {
        if ($definicion->audited) {
            $valores['modified_by'] = auth()->id();
            $valores['modified_at'] = now();
        }

        DB::table($definicion->table)->where($definicion->key, $this->editing)->update($valores);
    }

    /**
     * Baja lógica donde el catálogo la tiene; donde no, borrado real.
     *
     * Un catálogo en uso no se puede borrar de verdad: la llave foránea lo
     * impide. En vez de dejar que reviente, se avisa — por eso los catálogos que
     * se referencian desde la operación llevan columna de baja.
     */
    public function delete(int $id): void
    {
        $this->assertAdmin();

        $definicion = $this->definition();

        try {
            $definicion->softDelete !== null
                ? DB::table($definicion->table)->where($definicion->key, $id)->update([$definicion->softDelete => 1])
                : DB::table($definicion->table)->where($definicion->key, $id)->delete();

            session()->flash('status', $definicion->singular.__(' dado de baja.'));
        } catch (QueryException $e) {
            $this->addError('delete', __('No se puede borrar: hay registros que lo usan.'));
        }
    }

    /** No se llama `authorize`: ese nombre ya lo usa Livewire y es público. */
    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    // --------------------------------------------------------- Pintado

    public function render()
    {
        $definicion = $this->definition();

        $consulta = DB::table($definicion->table)
            ->when(
                $definicion->softDelete !== null,
                fn ($q) => $q->where(fn ($w) => $w->where($definicion->softDelete, 0)->orWhereNull($definicion->softDelete))
            )
            ->when($this->search !== '', function ($q) use ($definicion) {
                $q->where(function ($w) use ($definicion) {
                    foreach ($definicion->searchColumns() as $columna) {
                        $w->orWhere($columna, 'like', '%'.$this->search.'%');
                    }
                });
            })
            ->orderBy($definicion->defaultOrder());

        return view('livewire.catalogs.catalog-manager', [
            'definicion' => $definicion,
            'filas' => $consulta->paginate(25, ['*'], 'page', $this->getPage()),
            'catalogos' => CatalogRegistry::visibles(),
        ])->layout('components.app-layout', ['title' => $definicion->plural]);
    }
}
