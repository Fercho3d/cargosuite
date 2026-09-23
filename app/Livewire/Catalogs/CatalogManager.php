<?php

namespace App\Livewire\Catalogs;

use App\Support\Catalogs\CatalogDefinition;
use App\Support\Catalogs\CatalogField;
use App\Support\Catalogs\CatalogRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
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
        $this->assertCanRead(CatalogRegistry::find($catalog));
    }

    /** Un catálogo de super administrador ni se ve, como en Yii2. */
    private function assertCanRead(CatalogDefinition $definicion): void
    {
        abort_if($definicion->superAdmin && ! (auth()->user()?->isSuperAdmin() ?? false), 403);
    }

    public function definition(): CatalogDefinition
    {
        return CatalogRegistry::find($this->slug);
    }

    /**
     * Los renglones vivos del catálogo: sin los dados de baja donde hay baja
     * lógica. `NULL` cuenta como vivo, como en el listado de siempre.
     */
    private function vivos(CatalogDefinition $definicion): Builder
    {
        return DB::table($definicion->table)->when(
            $definicion->softDelete !== null,
            fn ($q) => $q->where(fn ($w) => $w->where($definicion->softDelete, 0)->orWhereNull($definicion->softDelete))
        );
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
            ->mapWithKeys(fn (CatalogField $c) => [$c->name => $c->initial()])
            ->all();
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->assertAdmin();

        $definicion = $this->definition();

        // Un id manipulado no abre un renglón dado de baja.
        $fila = $this->vivos($definicion)->where($definicion->key, $id)->first();

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

        // Un campo oculto por otro valor del formulario no se valida ni se toma
        // de la pantalla: se guarda lo que su definición manda.
        $visible = fn (CatalogField $c) => $c->visible($this->form);

        $this->validate(
            collect($definicion->fields)
                ->mapWithKeys(fn (CatalogField $c) => ["form.{$c->name}" => $visible($c) ? $this->rulesFor($definicion, $c) : ['nullable']])
                ->all(),
            attributes: collect($definicion->fields)
                ->mapWithKeys(fn (CatalogField $c) => ["form.{$c->name}" => mb_strtolower($c->label)])
                ->all(),
        );

        $valores = collect($definicion->fields)
            ->mapWithKeys(fn (CatalogField $c) => [$c->name => $c->cast($visible($c) ? ($this->form[$c->name] ?? null) : $c->hiddenValue)])
            ->all();

        try {
            $this->editing === 0
                ? $this->insert($definicion, $valores)
                : $this->update($definicion, $valores);
        } catch (QueryException $e) {
            report($e);
            $this->addError('form', __('No se pudo guardar: la base de datos rechazó el registro. Revisa los datos e inténtalo de nuevo.'));

            return;
        }

        session()->flash('status', $this->editing === 0
            ? __(':cosa agregado.', ['cosa' => $definicion->singular])
            : __(':cosa actualizado.', ['cosa' => $definicion->singular]));
        $this->cancel();
    }

    /**
     * Las reglas del campo más la de unicidad, que depende de si es alta o
     * edición: al editar se ignora el propio renglón.
     *
     * @return array<int, mixed>
     */
    private function rulesFor(CatalogDefinition $definicion, CatalogField $campo): array
    {
        if (! $campo->unique) {
            return $campo->rules;
        }

        // Lo dado de baja no cuenta: su nombre se puede volver a usar.
        $unica = Rule::unique($definicion->table, $campo->name)->ignore($this->editing, $definicion->key);

        if ($definicion->softDelete !== null) {
            $unica->where(fn ($q) => $q->where($definicion->softDelete, 0)->orWhereNull($definicion->softDelete));
        }

        return [...$campo->rules, $unica];
    }

    /** @param  array<string, mixed>  $valores */
    private function insert(CatalogDefinition $definicion, array $valores): void
    {
        // Un campo con default que se dejó vacío no se escribe como NULL: se omite
        // para que la base aplique el suyo (`company.regimen_fiscal DEFAULT '601'`).
        foreach ($definicion->fields as $campo) {
            if ($campo->default !== null && ($valores[$campo->name] ?? null) === null) {
                unset($valores[$campo->name]);
            }
        }

        $valores += $definicion->insertDefaults();

        if ($definicion->softDelete !== null) {
            $valores[$definicion->softDelete] = 0;
        }

        if ($definicion->audited) {
            $valores['created_by'] = auth()->id();
            $valores['created_at'] = $this->stamp($definicion, 'created_at');
        }

        DB::table($definicion->table)->insert($valores);
    }

    /** @param  array<string, mixed>  $valores */
    private function update(CatalogDefinition $definicion, array $valores): void
    {
        if ($definicion->audited) {
            $valores['modified_by'] = auth()->id();
            $valores['modified_at'] = $this->stamp($definicion, 'modified_at');
        }

        // `editing` viaja en el navegador: tampoco se actualiza algo dado de baja.
        $this->vivos($definicion)->where($definicion->key, $this->editing)->update($valores);
    }

    /**
     * Fecha de auditoría según la columna: `pickup_place.created_at` y
     * `modified_at` son `date` y no `datetime`, y ahí va solo el día.
     */
    private function stamp(CatalogDefinition $definicion, string $columna): Carbon|string
    {
        return Schema::getColumnType($definicion->table, $columna) === 'date' ? now()->toDateString() : now();
    }

    /**
     * Baja lógica donde el catálogo la tiene; donde no, borrado real.
     *
     * Un catálogo en uso no se debe borrar de verdad. La llave foránea suele
     * impedirlo, pero no siempre: `booking_continuity.modality` y
     * `containers.container_type` son `ON DELETE CASCADE` y borrarían la operación
     * sin error. Por eso se cuenta el uso declarado en `usedBy` antes de borrar.
     */
    public function delete(int $id): void
    {
        $this->assertAdmin();

        $definicion = $this->definition();

        if ($definicion->softDelete === null && ($uso = $this->usage($definicion, $id)) !== null) {
            $this->addError('delete', $uso);

            return;
        }

        try {
            $definicion->softDelete !== null
                ? DB::table($definicion->table)->where($definicion->key, $id)->update([$definicion->softDelete => 1])
                : DB::table($definicion->table)->where($definicion->key, $id)->delete();

            session()->flash('status', __(':cosa dado de baja.', ['cosa' => $definicion->singular]));
        } catch (QueryException $e) {
            $this->addError('delete', __('No se puede borrar: hay registros que lo usan.'));
        }
    }

    /** Mensaje con cuántos registros usan el renglón, o null si nadie lo usa. */
    private function usage(CatalogDefinition $definicion, int $id): ?string
    {
        $fila = DB::table($definicion->table)->where($definicion->key, $id)->first();

        foreach ($definicion->usedBy as $referencia) {
            [$tabla, $columna] = $referencia;
            $propia = $referencia[2] ?? $definicion->key;

            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $usos = DB::table($tabla)->where($columna, $fila?->{$propia} ?? $id)->count();

            if ($usos > 0) {
                return __('No se puede borrar: lo usan :n registros de :tabla.', ['n' => $usos, 'tabla' => $tabla]);
            }
        }

        return null;
    }

    /** No se llama `authorize`: ese nombre ya lo usa Livewire y es público. */
    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        $this->assertCanRead($this->definition());
    }

    // --------------------------------------------------------- Pintado

    public function render()
    {
        $definicion = $this->definition();

        $consulta = $this->vivos($definicion)
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
