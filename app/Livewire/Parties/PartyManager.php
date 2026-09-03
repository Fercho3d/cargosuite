<?php

namespace App\Livewire\Parties;

use App\Models\Core\Account;
use App\Models\Core\Provider;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Clientes y proveedores.
 *
 * No son catálogos planos —llevan datos fiscales, de contacto y de facturación—
 * pero sí son la misma pantalla con distintos campos, así que se resuelven en un
 * componente con dos modos.
 *
 * Lo que **no** se administra aquí es la contraseña del portal: eso vive en la
 * pantalla de usuarios, junto al resto de los accesos, para no tener dos lugares
 * donde se cambian credenciales.
 */
class PartyManager extends Component
{
    use WithPagination;

    /** client | provider */
    public string $mode = 'client';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(string $mode = 'client'): void
    {
        $this->mode = $mode;
    }

    public function isClient(): bool
    {
        return $this->mode === 'client';
    }

    public function table(): string
    {
        return $this->isClient() ? 'client' : 'provider';
    }

    public function key(): string
    {
        return $this->isClient() ? 'client_id' : 'provider_id';
    }

    public function title(): string
    {
        return $this->isClient() ? __('Clientes') : __('Proveedores');
    }

    /**
     * Campos capturables: etiqueta, tipo y reglas.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}>
     */
    public function fields(): array
    {
        $texto = ['nullable', 'string', 'max:255'];

        $comunes = [
            'fullName' => [__('Nombre o razón social'), 'text', ['required', 'string', 'max:255']],
            'rfc' => [__('RFC'), 'text', ['nullable', 'string', 'max:20']],
            'email' => [__('Correo'), 'text', ['nullable', 'email', 'max:255']],
            'phone' => [__('Teléfono'), 'text', ['nullable', 'string', 'max:50']],
            'address' => [__('Dirección'), 'text', $texto],
            'city' => [__('Ciudad'), 'text', ['nullable', 'string', 'max:100']],
            'state' => [__('Estado o provincia'), 'text', ['nullable', 'string', 'max:100']],
            'postal_code' => [__('Código postal'), 'text', ['nullable', 'string', 'max:20']],
            'account_id' => [__('Divisa habitual'), 'select', ['nullable', 'integer']],
        ];

        if (! $this->isClient()) {
            return $comunes + [
                'type_id' => [__('Tipo de proveedor'), 'select', ['nullable', 'integer']],
            ];
        }

        // Los campos del CFDI solo salen donde se factura al SAT: en una
        // instalación sin timbrado son cuatro casillas que nadie sabe llenar.
        $fiscales = config('timbrado.habilitado') ? [
            'regimen_fiscal_id' => [__('Régimen fiscal (SAT)'), 'text', ['nullable', 'string', 'max:10']],
            'invoice_use' => [__('Uso del CFDI'), 'text', ['nullable', 'string', 'max:10']],
            'pay_method' => [__('Método de pago'), 'text', ['nullable', 'string', 'max:10']],
            'pay_form' => [__('Forma de pago'), 'text', ['nullable', 'string', 'max:10']],
        ] : [];

        // Datos que solo tienen sentido en un cliente.
        return $comunes + $fiscales + [
            'email_notification' => [__('Correos para facturas'), 'text', $texto],
        ];
    }

    /** @return array<int, string> */
    public function optionsFor(string $campo): array
    {
        return match ($campo) {
            'account_id' => Account::options(),
            'type_id' => [
                Provider::TYPE_CARRIER => __('Naviera'),
                Provider::TYPE_TRANSPORT => __('Transportista'),
                Provider::TYPE_BROKER => 'Agente aduanal',
            ],
            default => [],
        };
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

    /**
     * Documentos que se le piden a este cliente (`fields_by_client`).
     *
     * Sin esto, un cliente nuevo no ofrece ni un solo campo donde subir papeles:
     * la pantalla del booking saca los campos de aquí. En Yii2 era un CRUD suelto
     * («Fields by client») que había que ir a buscar por su cuenta.
     *
     * @var array<int, string>
     */
    public array $documentFields = [];

    public function create(): void
    {
        $this->assertAdmin();

        $this->editing = 0;
        $this->form = collect($this->fields())->map(fn () => '')->all();
        $this->documentFields = [];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->assertAdmin();

        $fila = DB::table($this->table())->where($this->key(), $id)->first();

        abort_if($fila === null, 404);

        $this->editing = $id;
        $this->form = collect($this->fields())
            ->mapWithKeys(fn ($definicion, $campo) => [$campo => (string) ($fila->{$campo} ?? '')])
            ->all();
        $this->documentFields = $this->isClient()
            ? DB::table('fields_by_client')->where('client_id', $id)->pluck('field_id')
                ->map(fn ($valor) => (string) $valor)->all()
            : [];
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'form', 'documentFields']);
        $this->resetErrorBag();
    }

    /** El catálogo de documentos, para las casillas. @return array<int, string> */
    public function documentCatalog(): array
    {
        return DB::table('file_fields')->orderBy('label')
            ->pluck('label', 'field_id')
            ->map(fn ($etiqueta, $id) => (string) ($etiqueta ?: $id))
            ->all();
    }

    public function save(): void
    {
        $this->assertAdmin();

        $this->validate(
            collect($this->fields())->mapWithKeys(fn ($d, $campo) => ["form.{$campo}" => $d[2]])->all(),
            attributes: collect($this->fields())
                ->mapWithKeys(fn ($d, $campo) => ["form.{$campo}" => mb_strtolower($d[0])])
                ->all(),
        );

        $valores = collect($this->fields())
            ->mapWithKeys(fn ($d, $campo) => [$campo => ($this->form[$campo] ?? '') === '' ? null : $this->form[$campo]])
            ->all();

        if ($this->editing === 0) {
            $id = DB::table($this->table())->insertGetId(
                array_merge($valores, ['created_by' => auth()->id(), 'created_at' => now()]),
                $this->key(),
            );
        } else {
            $id = $this->editing;
            DB::table($this->table())
                ->where($this->key(), $id)
                ->update(array_merge($valores, ['modified_by' => auth()->id(), 'modified_at' => now()]));
        }

        $this->syncDocumentFields((int) $id);

        session()->flash('status', $this->editing === 0 ? 'Registro creado.' : 'Registro actualizado.');
        $this->cancel();
    }

    /** Deja `fields_by_client` con exactamente los documentos marcados. */
    private function syncDocumentFields(int $clientId): void
    {
        if (! $this->isClient()) {
            return;
        }

        $elegidos = collect($this->documentFields)->map(fn ($id) => (int) $id)->filter()->unique();
        $actuales = DB::table('fields_by_client')->where('client_id', $clientId)->pluck('field_id')
            ->map(fn ($id) => (int) $id);

        DB::table('fields_by_client')
            ->where('client_id', $clientId)
            ->whereIn('field_id', $actuales->diff($elegidos)->all())
            ->delete();

        foreach ($elegidos->diff($actuales) as $fieldId) {
            DB::table('fields_by_client')->insert(['client_id' => $clientId, 'field_id' => $fieldId]);
        }
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    public function render()
    {
        $filas = DB::table($this->table())
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    foreach (['fullName', 'rfc', 'email', 'city'] as $columna) {
                        $w->orWhere($columna, 'like', '%'.$this->search.'%');
                    }
                });
            })
            ->orderBy('fullName')
            ->paginate(25, ['*'], 'page', $this->getPage());

        return view('livewire.parties.party-manager', [
            'filas' => $filas,
        ])->layout('components.app-layout', ['title' => $this->title()]);
    }
}
