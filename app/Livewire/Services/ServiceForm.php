<?php

namespace App\Livewire\Services;

use App\Models\Core\Account;
use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Models\Core\Service;
use App\Support\ServiceFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Alta y edición de un servicio en su propia pantalla, con regreso a la lista
 * de servicios tal como se dejó (y, desde ahí, a la ficha del tercero). Solo
 * el super administrador, como el `ServiceController` de Yii2.
 *
 * Un servicio con **precio 0 es un precio abierto**: al capturar el concepto se
 * escribe a mano. Los **auto-incluibles** son los que el booking usa para
 * proponer solo su factura y sus costos; por eso se pide su ruta, cómo se cobra
 * el precio y su vigencia.
 */
class ServiceForm extends Component
{
    use WithFileUploads;

    /** Id del servicio, o null si es alta. */
    public ?int $serviceId = null;

    /** @var UploadedFile|null Contrato en PDF recién elegido, pendiente de guardar. */
    public $contract = null;

    /** Nombre del contrato ya guardado (`service.contract`). */
    public string $contractName = '';

    /** 1 = de venta (cliente), 2 = de compra (proveedor). */
    public string $type = '1';

    /** @var array<string, mixed> */
    public array $form = [];

    /** A dónde regresa: la lista de servicios con su filtro. */
    public string $volver = '';

    /** Tercero con el que se abrió el alta, para dejarlo puesto. */
    public string $partyId = '';

    public function mount(?int $service = null): void
    {
        $this->assertAdmin();

        $url = (string) request()->query('volver', '');
        $this->volver = str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? $url
            : route('parties.services', absolute: false);

        if ($service === null) {
            $this->type = request()->query('tipo') === '2' ? '2' : '1';
            $this->partyId = (string) request()->integer('tercero') ?: '';
            $this->form = $this->blankForm();

            return;
        }

        $servicio = Service::findOrFail($service);

        $this->serviceId = $service;
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
        $this->contractName = (string) $servicio->contract;
    }

    /** Enlace para abrir el contrato guardado, o null si no hay. */
    public function contractUrl(): ?string
    {
        return $this->serviceId !== null && $this->contractName !== ''
            ? route('parties.services.contract', $this->serviceId, absolute: false)
            : null;
    }

    /** Al cambiar de venta a compra en un alta, el tercero elegido ya no aplica. */
    public function updatedType(): void
    {
        $this->form['party_id'] = '';
        $this->form['price_type'] = '';
    }

    /** Tipo del proveedor elegido (naviera, transportista, agente aduanal); null en venta o sin proveedor. */
    public function providerType(): ?int
    {
        if ($this->isSale() || ($this->form['party_id'] ?? '') === '') {
            return null;
        }

        $tipo = Provider::whereKey((int) $this->form['party_id'])->value('type_id');

        return $tipo === null ? null : (int) $tipo;
    }

    /**
     * Qué campos de ruta aplican, como `_form.php` en Yii2: al cliente y a la
     * naviera se les pide todo; al transportista solo puerto de carga y lugar
     * de recolección; al agente aduanal nada.
     *
     * @return string[]
     */
    public function routeFields(): array
    {
        if ($this->isSale()) {
            return Service::ROUTE_FIELDS;
        }

        return match ($this->providerType()) {
            Provider::TYPE_CARRIER => Service::ROUTE_FIELDS,
            Provider::TYPE_TRANSPORT => ['loading_port_id', 'pickup_place_id'],
            default => [],
        };
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
        $tipos = Service::priceTypeLabels();

        return $this->isSale()
            ? $tipos
            : array_intersect_key($tipos, array_flip([Service::PRICE_BY_CONTAINER, Service::PRICE_BY_BL]));
    }

    public function save(): void
    {
        $this->assertAdmin();

        // Los importes se capturan como se leen, con separador de miles.
        foreach (['price', 'min', 'max'] as $campo) {
            $this->form[$campo] = str_replace(',', '', (string) ($this->form[$campo] ?? ''));
        }

        $datos = $this->validate($this->rules(), attributes: [
            'form.description' => __('descripción'),
            'form.price' => __('precio'),
            'form.charge_type_id' => __('tipo de cargo'),
            'form.account_id' => __('divisa'),
            'form.price_type' => __('tipo de precio'),
            'form.start_date' => __('inicio de vigencia'),
            'form.end_date' => __('fin de vigencia'),
            'form.min' => __('precio mínimo'),
            'form.max' => __('precio máximo'),
            'form.party_id' => $this->isSale() ? __('cliente') : __('proveedor'),
            'contract' => __('contrato'),
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
            'modified_at' => now(),
        ];

        // La ruta que no aplica a este tipo de proveedor no se guarda, aunque
        // quedara puesta de cuando el servicio era de otro.
        foreach (array_diff(Service::ROUTE_FIELDS, $this->routeFields()) as $campo) {
            $valores[$campo] = null;
        }

        if ($this->serviceId === null) {
            $id = (int) DB::table('service')->insertGetId($valores + ['created_by' => auth()->id(), 'created_at' => now()], 'service_id');
        } else {
            $id = $this->serviceId;
            DB::table('service')->where('service_id', $id)->update($valores);
        }

        // El contrato se guarda después: en un alta la carpeta lleva el id nuevo.
        if ($this->contract !== null) {
            $nombre = app(ServiceFiles::class)->store($id, $this->contract);
            DB::table('service')->where('service_id', $id)->update(['contract' => $nombre]);
        }

        session()->flash('status', $this->serviceId === null ? __('Servicio creado.') : __('Servicio actualizado.'));
        $this->redirect($this->volver, navigate: true);
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
        // Donde el catálogo tiene baja lógica, lo dado de baja ya no sale en el
        // combo y tampoco se acepta aunque se mande a mano.
        $vigente = fn (string $tabla, string $llave) => Rule::exists($tabla, $llave)->where('deleted', 0);
        $catalogo = fn (string $tabla, string $llave, bool $conBaja = false) => ['nullable', $conBaja ? $vigente($tabla, $llave) : Rule::exists($tabla, $llave)];

        return [
            // Solo PDF y hasta 5 MB, como los 156 contratos que ya hay.
            'contract' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            'form.description' => ['required', 'string', 'max:255'],
            'form.price' => ['required', 'numeric', 'min:0'],
            'form.charge_type_id' => ['required', $vigente('charge_type', 'charge_type_id')],
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
            'form.loading_port_id' => $catalogo('loading_ports', 'port_id', true),
            'form.dicharge_port_id' => $catalogo('dicharge_port', 'dicharge_port_id', true),
            'form.pickup_place_id' => $catalogo('pickup_place', 'pick_id'),
            'form.final_destination_id' => $catalogo('final_destination', 'final_destination_id', true),
            'form.container_type_id' => $catalogo('container_types', 'contType_id'),
            'form.start_date' => ['nullable', 'date'],
            'form.end_date' => ['nullable', 'date', 'after_or_equal:form.start_date'],
            'form.min' => ['nullable', 'numeric', 'min:0'],
            'form.max' => ['nullable', 'numeric', 'min:0', 'gte:form.min'],
        ];
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);
    }

    public function render()
    {
        $titulo = $this->serviceId === null ? __('Nuevo servicio') : __('Editar servicio');

        return view('livewire.services.service-form', [
            'titulo' => $titulo,
            'terceros' => $this->parties(),
            'tiposDeCargo' => DB::table('charge_type')->where('deleted', 0)
                ->orderBy('charge_type_name')->pluck('charge_type_name', 'charge_type_id')->all(),
            'divisas' => Account::options(),
            'puertosCarga' => DB::table('loading_ports')->where('deleted', 0)->orderBy('port_name')->pluck('port_name', 'port_id')->all(),
            'puertosDescarga' => DB::table('dicharge_port')->where('deleted', 0)->orderBy('name')->pluck('name', 'dicharge_port_id')->all(),
            'lugares' => DB::table('pickup_place')->orderBy('name')->pluck('name', 'pick_id')->all(),
            'destinos' => DB::table('final_destination')->where('deleted', 0)->orderBy('name')->pluck('name', 'final_destination_id')->all(),
            'tiposContenedor' => DB::table('container_types')->orderBy('container_name')->pluck('container_name', 'contType_id')->all(),
        ])->layout('components.app-layout', ['title' => $titulo]);
    }
}
