<?php

namespace App\Livewire\Services;

use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Models\Core\Service;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Servicios contratados: el precio pactado con cada cliente y con cada proveedor.
 *
 * Es el catálogo del que salen los conceptos de una transacción, y por eso no
 * cabía entre los catálogos planos: cada servicio pertenece a un cliente **o** a
 * un proveedor, y a un tipo de cargo que decide su IVA.
 *
 * Un servicio con **precio 0 es un precio abierto**: al capturar el concepto se
 * escribe a mano. Cualquier otro precio queda fijo.
 *
 * Aquí solo va la lista; el alta y la edición abren su propio formulario
 * (`ServiceForm`), que regresa a esta lista tal como se dejó.
 */
class ServiceManager extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** 1 = de venta (cliente), 2 = de compra (proveedor); cualquier otro valor, sin filtro. */
    #[Url(as: 'tipo', except: '1')]
    public string $type = '1';

    #[Url(as: 'tercero', except: '')]
    public string $partyId = '';

    /** Ficha del cliente o proveedor de la que se llegó, para regresar a ella. */
    #[Url(as: 'volver', except: '')]
    public string $volver = '';

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function mount(): void
    {
        $this->assertAdmin();

        // Solo se regresa a direcciones propias del sistema.
        if (! str_starts_with($this->volver, '/') || str_starts_with($this->volver, '//')) {
            $this->volver = '';
        }
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }

        if ($property === 'type') {
            $this->partyId = '';
        }
    }

    /** ¿Se filtra por venta o compra? Un `tipo` desconocido en la URL no filtra. */
    public function filtersByType(): bool
    {
        return in_array($this->type, ['1', '2'], true);
    }

    public function isSale(): bool
    {
        return (int) $this->type === Service::TYPE_CLIENT;
    }

    /** @return array<int, string> */
    public function parties(): array
    {
        return $this->isSale() ? Client::options() : Provider::options();
    }

    /** Esta lista con su filtro: a dónde regresa el formulario de un servicio. */
    public function currentUrl(): string
    {
        return route('parties.services', array_filter([
            'tipo' => $this->type === '1' ? null : $this->type,
            'tercero' => $this->partyId,
            'q' => $this->search,
            'volver' => $this->volver,
            'page' => $this->getPage() > 1 ? $this->getPage() : null,
        ]), absolute: false);
    }

    /** Se desactiva, no se borra: hay conceptos históricos que lo referencian. */
    public function toggleActive(int $id): void
    {
        $this->assertAdmin();

        $servicio = Service::findOrFail($id);
        $servicio->forceFill(['active' => $servicio->active ? 0 : 1])->save();
    }

    /** Solo el super administrador, como el `ServiceController` de Yii2. */
    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);
    }

    public function render()
    {
        $servicios = DB::table('service as s')
            ->leftJoin('charge_type as ct', 'ct.charge_type_id', '=', 's.charge_type_id')
            ->leftJoin('client as c', 'c.client_id', '=', 's.client_id')
            ->leftJoin('provider as p', 'p.provider_id', '=', 's.provider_id')
            ->when($this->filtersByType(), fn ($q) => $q->where('s.type', (int) $this->type))
            ->when($this->filtersByType() && $this->partyId !== '', fn ($q) => $this->isSale()
                ? $q->where('s.client_id', (int) $this->partyId)
                : $q->where('s.provider_id', (int) $this->partyId))
            ->when($this->search !== '', fn ($q) => $q->where('s.description', 'like', '%'.$this->search.'%'))
            ->orderBy('s.description')
            ->leftJoin('account as a', 'a.account_id', '=', 's.account_id')
            // La ruta, el contenedor y quién lo tocó, como en el grid de Yii2.
            ->leftJoin('loading_ports as pol', 'pol.port_id', '=', 's.loading_port_id')
            ->leftJoin('dicharge_port as pod', 'pod.dicharge_port_id', '=', 's.dicharge_port_id')
            ->leftJoin('pickup_place as pp', 'pp.pick_id', '=', 's.pickup_place_id')
            ->leftJoin('final_destination as fd', 'fd.final_destination_id', '=', 's.final_destination_id')
            ->leftJoin('container_types as cty', 'cty.contType_id', '=', 's.container_type_id')
            ->leftJoin('users as u', 'u.usr_id', '=', 's.modified_by')
            ->paginate(25, [
                's.service_id', 's.description', 's.price', 's.active', 's.contract',
                's.auto_include', 's.start_date', 's.end_date', 'a.prefix as currency',
                'ct.charge_type_name', 'c.fullName as client_name', 'p.fullName as provider_name',
                's.price_type', 'pol.port_name as pol', 'pod.name as pod', 'pp.name as pickup',
                'fd.name as destination', 'cty.container_name as container',
                's.modified_at', 'u.username as modified_by_name',
            ], 'page', $this->getPage());

        return view('livewire.services.service-manager', [
            'servicios' => $servicios,
            'terceros' => $this->parties(),
            'tiposDePrecio' => Service::priceTypeLabels(),
        ])->layout('components.app-layout', ['title' => __('Servicios y precios')]);
    }
}
