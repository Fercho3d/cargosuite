<?php

namespace App\Livewire\Parties;

use App\Livewire\Parties\Concerns\PartyFields;
use App\Support\Export\PartiesExport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Clientes y proveedores.
 *
 * No son catálogos planos —llevan datos fiscales, de contacto y de facturación—
 * pero sí son la misma pantalla con distintos campos, así que se resuelven en un
 * componente con dos modos.
 *
 * Aquí solo va la lista; el alta y la edición abren su propia ficha
 * (`PartyForm`), que regresa a esta lista tal como se dejó.
 */
class PartyManager extends Component
{
    use PartyFields;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(string $mode = 'client'): void
    {
        $this->mode = $mode;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    /** Esta lista con su búsqueda y su página: a dónde regresa la ficha. */
    public function currentUrl(): string
    {
        return route($this->listRoute(), array_filter([
            'q' => $this->search,
            'page' => $this->getPage() > 1 ? $this->getPage() : null,
        ]), absolute: false);
    }

    /**
     * Columnas que pinta el listado. El tipo del proveedor decide en qué
     * selector del booking aparece, así que se ve.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}>
     */
    public function listFields(): array
    {
        return array_intersect_key($this->fields(), array_flip(['fullName', 'rfc', 'email', 'city', 'phone', 'type_id']));
    }

    /**
     * La lista con su búsqueda, tal como se ve, con solo las columnas pedidas y
     * la llave: la tabla guarda también la contraseña y las llaves del portal,
     * que no tienen por qué salir de la base.
     *
     * @param  array<int, string>  $columnas
     */
    private function query(array $columnas): Builder
    {
        return DB::table($this->table())
            ->select(array_values(array_unique([$this->key(), ...$columnas])))
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    foreach (['fullName', 'rfc', 'email', 'city'] as $columna) {
                        $w->orWhere($columna, 'like', '%'.$this->search.'%');
                    }
                });
            })
            ->orderBy('fullName');
    }

    /** Descarga en CSV de lo que se está viendo: todo el filtro, no la página. */
    public function export(PartiesExport $exportacion): StreamedResponse
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $campos = $this->fields();

        return $exportacion->stream(
            $this->query(array_keys($campos)),
            ['ID' => $this->key()] + collect($campos)->mapWithKeys(fn ($d, $campo) => [$d[0] => $campo])->all(),
            collect($campos)->filter(fn ($d) => $d[1] === 'select')->mapWithKeys(fn ($d, $campo) => [$campo => $this->optionsFor($campo)])->all(),
            ($this->isClient() ? 'clientes' : 'proveedores').'-'.now()->format('Ymd-His').'.csv',
        );
    }

    public function render()
    {
        return view('livewire.parties.party-manager', [
            'filas' => $this->query(array_keys($this->listFields()))->paginate(25, ['*'], 'page', $this->getPage()),
        ])->layout('components.app-layout', ['title' => $this->title()]);
    }
}
