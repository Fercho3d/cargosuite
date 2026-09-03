<?php

namespace App\Livewire\Services;

use App\Models\Core\Account;
use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Models\Core\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
 * Los servicios marcados como **auto-incluibles** son además los que el booking
 * usa para proponer solo su factura y sus costos: por eso el formulario pide la
 * ruta (puerto de carga, de descarga, lugar de recolección, destino final y tipo
 * de contenedor), cómo se cobra el precio y hasta cuándo está vigente. Sin esos
 * datos el servicio existe, pero la generación automática no lo puede encontrar.
 */
class ServiceManager extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** 1 = de venta (cliente), 2 = de compra (proveedor). */
    #[Url(as: 'tipo', except: '1')]
    public string $type = '1';

    #[Url(as: 'tercero', except: '')]
    public string $partyId = '';

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
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

    public function isSale(): bool
    {
        return (int) $this->type === Service::TYPE_CLIENT;
    }

    /** @return array<int, string> */
    public function parties(): array
    {
        return $this->isSale() ? Client::options() : Provider::options();
    }

    // ------------------------------------------------------------ Edición

    public function create(): void
    {
        $this->assertAdmin();

        $this->editing = 0;
        $this->form = $this->blankForm();
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->assertAdmin();

        $servicio = Service::findOrFail($id);

        $this->editing = $id;
        $this->form = array_merge($this->blankForm(), [
            'description' => (string) $servicio->description,
            'price' => (string) ($servicio->price ?? 0),
            'charge_type_id' => (string) $servicio->charge_type_id,
            'party_id' => (string) ($servicio->client_id ?: $servicio->provider_id),
            'active' => (bool) $servicio->active,
            'account_id' => (string) $servicio->account_id,
            'price_type' => (string) $servicio->price_type,
            'auto_include' => (bool) $servicio->auto_include,
            'loading_port_id' => (string) $servicio->loading_port_id,
            'dicharge_port_id' => (string) $servicio->dicharge_port_id,
            'pickup_place_id' => (string) $servicio->pickup_place_id,
            'final_destination_id' => (string) $servicio->final_destination_id,
            'container_type_id' => (string) $servicio->container_type_id,
            'start_date' => $this->asDate($servicio->start_date),
            'end_date' => $this->asDate($servicio->end_date),
            'min' => (string) $servicio->min,
            'max' => (string) $servicio->max,
        ]);
        $this->type = (string) $servicio->type;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'form']);
        $this->resetErrorBag();
    }

    /** @return array<string, mixed> */
    private function blankForm(): array
    {
        return [
            'description' => '',
            'price' => '0',
            'charge_type_id' => '',
            'party_id' => $this->partyId,
            'active' => true,
            'account_id' => '',
            'price_type' => '',
            'auto_include' => false,
            'loading_port_id' => '',
            'dicharge_port_id' => '',
            'pickup_place_id' => '',
            'final_destination_id' => '',
            'container_type_id' => '',
            'start_date' => '',
            'end_date' => '',
            'min' => '',
            'max' => '',
        ];
    }

    /** El esquema heredado guarda «sin fecha» como `0000-00-00` en algunas filas. */
    private function asDate(?string $valor): string
    {
        return $valor === null || str_starts_with($valor, '0000-00-00') ? '' : substr($valor, 0, 10);
    }

    /**
     * Cómo se cobra el precio. Los dos tipos de aduana solo existen del lado de
     * la venta: son lo que se le cobra al cliente por el despacho.
     *
     * @return array<int, string>
     */
    public function priceTypes(): array
    {
        $tipos = [
            Service::PRICE_BY_CONTAINER => __('Por contenedor'),
            Service::PRICE_BY_BL => __('Por BL'),
        ];

        return $this->isSale()
            ? $tipos + [
                Service::PRICE_BY_BROKER_CONTAINER => __('Aduana, por contenedor'),
                Service::PRICE_BY_BROKER_BL => __('Aduana, por BL'),
            ]
            : $tipos;
    }

    public function save(): void
    {
        $this->assertAdmin();

        $datos = $this->validate($this->rules(), attributes: [
            'form.description' => __('descripción'),
            'form.price' => 'precio',
            'form.charge_type_id' => __('tipo de cargo'),
            'form.account_id' => 'divisa',
            'form.price_type' => __('tipo de precio'),
            'form.start_date' => __('inicio de vigencia'),
            'form.end_date' => __('fin de vigencia'),
            'form.min' => __('precio mínimo'),
            'form.max' => __('precio máximo'),
            'form.party_id' => $this->isSale() ? 'cliente' : 'proveedor',
        ])['form'];

        $entero = fn (string $campo) => ($datos[$campo] ?? '') === '' ? null : (int) $datos[$campo];
        $numero = fn (string $campo) => ($datos[$campo] ?? '') === '' ? null : (float) $datos[$campo];

        $valores = [
            'description' => $datos['description'],
            'price' => (float) $datos['price'],
            'charge_type_id' => (int) $datos['charge_type_id'],
            'account_id' => (int) $datos['account_id'],
            'type' => (int) $this->type,
            'client_id' => $this->isSale() ? (int) $datos['party_id'] : null,
            'provider_id' => $this->isSale() ? null : (int) $datos['party_id'],
            'active' => ($this->form['active'] ?? false) ? 1 : 0,
            'auto_include' => ($this->form['auto_include'] ?? false) ? 1 : 0,
            'price_type' => $entero('price_type'),
            'loading_port_id' => $entero('loading_port_id'),
            'dicharge_port_id' => $entero('dicharge_port_id'),
            'pickup_place_id' => $entero('pickup_place_id'),
            'final_destination_id' => $entero('final_destination_id'),
            'container_type_id' => $entero('container_type_id'),
            'start_date' => ($datos['start_date'] ?? '') ?: null,
            'end_date' => ($datos['end_date'] ?? '') ?: null,
            'min' => $numero('min'),
            'max' => $numero('max'),
            'modified_by' => auth()->id(),
        ];

        $this->editing === 0
            ? DB::table('service')->insert($valores + ['created_by' => auth()->id(), 'created_at' => now()])
            : DB::table('service')->where('service_id', $this->editing)->update($valores);

        session()->flash('status', $this->editing === 0 ? 'Servicio creado.' : 'Servicio actualizado.');
        $this->cancel();
    }

    /**
     * El tipo de precio es obligatorio en cuanto el servicio es auto-incluible:
     * sin él la generación automática no sabe por cuánto multiplicar y escribiría
     * el concepto con cantidad 0. El original lo dejaba pasar y en la base hay 21
     * servicios así.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        $catalogo = fn (string $tabla, string $llave) => ['nullable', Rule::exists($tabla, $llave)];

        return [
            'form.description' => ['required', 'string', 'max:255'],
            'form.price' => ['required', 'numeric', 'min:0'],
            'form.charge_type_id' => ['required', Rule::exists('charge_type', 'charge_type_id')],
            'form.account_id' => ['required', Rule::exists('account', 'account_id')],
            'form.party_id' => [
                'required',
                $this->isSale()
                    ? Rule::exists('client', 'client_id')
                    : Rule::exists('provider', 'provider_id'),
            ],
            'form.price_type' => [
                ($this->form['auto_include'] ?? false) ? 'required' : 'nullable',
                Rule::in(array_keys($this->priceTypes())),
            ],
            'form.loading_port_id' => $catalogo('loading_ports', 'port_id'),
            'form.dicharge_port_id' => $catalogo('dicharge_port', 'dicharge_port_id'),
            'form.pickup_place_id' => $catalogo('pickup_place', 'pick_id'),
            'form.final_destination_id' => $catalogo('final_destination', 'final_destination_id'),
            'form.container_type_id' => $catalogo('container_types', 'contType_id'),
            'form.start_date' => ['nullable', 'date'],
            'form.end_date' => ['nullable', 'date', 'after_or_equal:form.start_date'],
            'form.min' => ['nullable', 'numeric', 'min:0'],
            'form.max' => ['nullable', 'numeric', 'min:0', 'gte:form.min'],
        ];
    }

    /** Se desactiva, no se borra: hay conceptos históricos que lo referencian. */
    public function toggleActive(int $id): void
    {
        $this->assertAdmin();

        $servicio = Service::findOrFail($id);
        $servicio->forceFill(['active' => $servicio->active ? 0 : 1])->save();
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    public function render()
    {
        $servicios = DB::table('service as s')
            ->leftJoin('charge_type as ct', 'ct.charge_type_id', '=', 's.charge_type_id')
            ->leftJoin('client as c', 'c.client_id', '=', 's.client_id')
            ->leftJoin('provider as p', 'p.provider_id', '=', 's.provider_id')
            ->where('s.type', (int) $this->type)
            ->when($this->partyId !== '', fn ($q) => $this->isSale()
                ? $q->where('s.client_id', (int) $this->partyId)
                : $q->where('s.provider_id', (int) $this->partyId))
            ->when($this->search !== '', fn ($q) => $q->where('s.description', 'like', '%'.$this->search.'%'))
            ->orderBy('s.description')
            ->leftJoin('account as a', 'a.account_id', '=', 's.account_id')
            ->paginate(25, [
                's.service_id', 's.description', 's.price', 's.active',
                's.auto_include', 's.start_date', 's.end_date', 'a.prefix as currency',
                'ct.charge_type_name', 'c.fullName as client_name', 'p.fullName as provider_name',
            ], 'page', $this->getPage());

        return view('livewire.services.service-manager', [
            'servicios' => $servicios,
            'terceros' => $this->parties(),
            'tiposDeCargo' => DB::table('charge_type')->where('deleted', 0)
                ->orderBy('charge_type_name')->pluck('charge_type_name', 'charge_type_id')->all(),
            'divisas' => Account::options(),
            'puertosCarga' => DB::table('loading_ports')->where('deleted', 0)->orderBy('port_name')->pluck('port_name', 'port_id')->all(),
            'puertosDescarga' => DB::table('dicharge_port')->where('deleted', 0)->orderBy('name')->pluck('name', 'dicharge_port_id')->all(),
            'lugares' => DB::table('pickup_place')->orderBy('name')->pluck('name', 'pick_id')->all(),
            'destinos' => DB::table('final_destination')->where('deleted', 0)->orderBy('name')->pluck('name', 'final_destination_id')->all(),
            'tiposContenedor' => DB::table('container_types')->orderBy('container_name')->pluck('container_name', 'contType_id')->all(),
        ])->layout('components.app-layout', ['title' => __('Servicios y precios')]);
    }
}
