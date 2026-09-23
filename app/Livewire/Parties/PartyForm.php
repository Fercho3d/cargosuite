<?php

namespace App\Livewire\Parties;

use App\Livewire\Parties\Concerns\PartyFields;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Ficha de un cliente o proveedor: alta y edición en su propia pantalla, con los
 * servicios que tiene pactados y regreso a la lista tal como se dejó.
 *
 * Lo que **no** se administra aquí es la contraseña del portal: eso vive en la
 * pantalla de usuarios, junto al resto de los accesos, para no tener dos lugares
 * donde se cambian credenciales.
 */
class PartyForm extends Component
{
    use PartyFields;

    /** Id del tercero, o null si es alta. */
    public ?int $partyId = null;

    /** @var array<string, mixed> */
    public array $form = [];

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

    /** A dónde regresa: la lista con su búsqueda y su página. */
    public string $volver = '';

    /**
     * Los campos del modo, calculados una vez por petición: `fields()` arma
     * reglas y consulta los catálogos del SAT, y la ficha lo pide al montar,
     * al guardar y al pintar.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}>
     */
    #[Computed]
    public function campos(): array
    {
        return $this->fields();
    }

    public function mount(string $mode = 'client', ?int $party = null): void
    {
        $this->assertAdmin();

        $this->mode = $mode;
        $this->partyId = $party;

        $url = (string) request()->query('volver', '');
        $this->volver = str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? $url
            : route($this->listRoute(), absolute: false);

        if ($party === null) {
            $this->form = collect($this->campos)->map(fn ($d) => $d[1] === 'checkbox' ? false : '')->all();
            // Un cliente nuevo pide de entrada los documentos marcados como «por
            // omisión» en el catálogo, como `Client::generateFields()` en Yii2.
            $this->documentFields = $this->isClient()
                ? DB::table('file_fields')->where('default', 1)->pluck('field_id')->map(fn ($valor) => (string) $valor)->all()
                : [];

            return;
        }

        $fila = DB::table($this->table())->where($this->key(), $party)->first();

        abort_if($fila === null, 404);

        $this->form = collect($this->campos)
            ->mapWithKeys(fn ($d, $campo) => [
                $campo => $d[1] === 'checkbox' ? (bool) ($fila->{$campo} ?? false) : trim((string) ($fila->{$campo} ?? '')),
            ])
            ->all();
        $this->documentFields = $this->isClient()
            ? DB::table('fields_by_client')->where('client_id', $party)->pluck('field_id')
                ->map(fn ($valor) => (string) $valor)->all()
            : [];
    }

    /** El catálogo de documentos, para las casillas. @return array<int, string> */
    #[Computed]
    public function documentCatalog(): array
    {
        return DB::table('file_fields')->orderBy('label')
            ->pluck('label', 'field_id')
            ->map(fn ($etiqueta, $id) => (string) ($etiqueta ?: $id))
            ->all();
    }

    /** Servicios pactados con este tercero, con su precio. */
    public function services(): Collection
    {
        if ($this->partyId === null) {
            return collect();
        }

        return DB::table('service as s')
            ->leftJoin('charge_type as ct', 'ct.charge_type_id', '=', 's.charge_type_id')
            ->leftJoin('account as a', 'a.account_id', '=', 's.account_id')
            ->where($this->isClient() ? 's.client_id' : 's.provider_id', $this->partyId)
            ->orderByDesc('s.active')
            ->orderBy('s.description')
            ->get(['s.service_id', 's.description', 's.price', 's.active', 'a.prefix as currency', 'ct.charge_type_name']);
    }

    /** Servicios y precios filtrado a este tercero, con regreso a esta ficha. */
    public function servicesUrl(): string
    {
        $ficha = route($this->listRoute().'.edit', $this->partyId, absolute: false)
            .'?volver='.urlencode($this->volver);

        return route('parties.services', [
            'tipo' => $this->isClient() ? 1 : 2,
            'tercero' => $this->partyId,
            'volver' => $ficha,
        ], absolute: false);
    }

    public function save(): void
    {
        $this->assertAdmin();

        $campos = $this->campos;

        // Se normaliza antes de validar: el RFC va en mayúsculas y el teléfono
        // se captura como se lee («(55) 1234-5678») pero la columna es un entero.
        $this->form['rfc'] = mb_strtoupper(trim((string) ($this->form['rfc'] ?? '')));
        $this->form['phone'] = preg_replace('/\D+/', '', (string) ($this->form['phone'] ?? ''));

        foreach ($campos as $campo => $d) {
            if ($d[1] !== 'checkbox') {
                $this->form[$campo] = trim((string) ($this->form[$campo] ?? ''));
            }
        }

        // `fields_by_client` no tiene llave foránea: sin esta regla, un id
        // manipulado dejaría pedido un documento que no existe.
        $documentos = $this->isClient()
            ? ['documentFields' => ['array'], 'documentFields.*' => ['integer', Rule::exists('file_fields', 'field_id')]]
            : [];

        $this->validate(
            collect($campos)->mapWithKeys(fn ($d, $campo) => ["form.{$campo}" => $d[2]])->all() + $documentos,
            attributes: collect($campos)
                ->mapWithKeys(fn ($d, $campo) => ["form.{$campo}" => mb_strtolower($d[0])])
                ->all() + ['documentFields.*' => __('documento')],
        );

        $valores = collect($campos)
            ->mapWithKeys(fn ($d, $campo) => [$campo => $this->valueToStore($campo, $d[1], $this->form[$campo] ?? '')])
            ->all();

        try {
            $id = $this->partyId ?? $this->insert($valores);

            if ($this->partyId !== null) {
                DB::table($this->table())
                    ->where($this->key(), $id)
                    ->update(array_merge($valores, ['modified_by' => auth()->id(), 'modified_at' => now()]));
            }
        } catch (QueryException $e) {
            report($e);
            $this->addError('form', __('No se pudo guardar: la base de datos rechazó el registro. Revisa los datos e inténtalo de nuevo.'));

            return;
        }

        $this->syncDocumentFields((int) $id);

        session()->flash('status', $this->partyId === null ? __('Registro creado.') : __('Registro actualizado.'));
        $this->redirect($this->volver, navigate: true);
    }

    /**
     * Valor listo para la columna. `email` es NOT NULL en la base: sin correo va
     * vacío, como lo deja el original; el teléfono es un `int(11)`.
     */
    private function valueToStore(string $campo, string $tipo, mixed $valor): mixed
    {
        return match (true) {
            $tipo === 'checkbox' => $valor ? 1 : 0,
            $valor === '' => $campo === 'email' ? '' : null,
            $campo === 'phone' => (int) $valor,
            default => $valor,
        };
    }

    /**
     * El original escribía también `modified_by`/`modified_at` al dar de alta,
     * así que un registro recién creado nunca tiene la modificación vacía.
     *
     * @param  array<string, mixed>  $valores
     */
    private function insert(array $valores): int
    {
        $auditoria = ['created_by' => auth()->id(), 'created_at' => now(), 'modified_by' => auth()->id(), 'modified_at' => now()];

        return (int) DB::table($this->table())->insertGetId(array_merge($valores, $auditoria), $this->key());
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
        $titulo = match (true) {
            $this->partyId !== null => $this->form['fullName'] ?: __('Editar registro'),
            $this->isClient() => __('Nuevo cliente'),
            default => __('Nuevo proveedor'),
        };

        return view('livewire.parties.party-form', ['titulo' => $titulo])
            ->layout('components.app-layout', ['title' => $titulo]);
    }
}
