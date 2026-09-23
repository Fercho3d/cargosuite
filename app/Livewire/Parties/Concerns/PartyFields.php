<?php

namespace App\Livewire\Parties\Concerns;

use App\Models\Core\Account;
use App\Models\Core\Provider;
use App\Support\Cfdi\RegimenesFiscales;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Lo que comparten la lista y la ficha de clientes y proveedores: el modo, su
 * tabla y los campos capturables.
 */
trait PartyFields
{
    /** `client.phone` y `provider.phone` son `int(11)`: más que esto no cabe. */
    public const PHONE_MAX = 2147483647;

    /** client | provider */
    public string $mode = 'client';

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

    /** ¿Se piden los datos del CFDI? Solo donde se factura al SAT. */
    public function requiresCfdi(): bool
    {
        return $this->isClient() && (bool) config('timbrado.habilitado');
    }

    /**
     * Campos capturables: etiqueta, tipo (`text`, `select`, `checkbox`,
     * `textarea`) y reglas. Las longitudes son las de las columnas reales: la
     * conexión corre en modo estricto y lo que se pase de la columna revienta
     * al guardar.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}>
     */
    public function fields(): array
    {
        $cfdi = $this->requiresCfdi();
        $obligatorioConCfdi = $cfdi ? 'required' : 'nullable';

        $comunes = [
            'fullName' => [__('Nombre o razón social'), 'text', ['required', 'string', 'max:100']],
            'rfc' => [__('RFC'), 'text', [$obligatorioConCfdi, 'string', 'max:25', ...($cfdi ? ['regex:'.RegimenesFiscales::RFC_REGEX] : [])]],
            'email' => [__('Correo'), 'text', ['nullable', 'email', 'max:50']],
            'phone' => [__('Teléfono'), 'text', ['nullable', 'string', 'max:20', $this->phoneFits()]],
            'address' => [__('Dirección'), 'text', [$obligatorioConCfdi, 'string', 'max:255']],
        ];

        if (! $this->isClient()) {
            return $comunes + [
                'city' => [__('Ciudad'), 'text', ['nullable', 'string', 'max:25']],
                'state' => [__('Estado o provincia'), 'text', ['nullable', 'string', 'max:25']],
                'postal_code' => [__('Código postal'), 'text', ['nullable', 'string', 'max:25']],
                'account_id' => [__('Divisa habitual'), 'select', ['nullable', 'integer']],
                // Sin tipo, el proveedor no aparece en ningún selector del booking.
                'type_id' => [__('Tipo de proveedor'), 'select', ['required', Rule::in([Provider::TYPE_CARRIER, Provider::TYPE_TRANSPORT, Provider::TYPE_BROKER])]],
            ];
        }

        $domicilio = [
            'address2' => [__('Dirección 2'), 'text', ['nullable', 'string', 'max:255']],
            'city' => [__('Ciudad'), 'text', ['nullable', 'string', 'max:25']],
            'state' => [__('Estado o provincia'), 'text', ['nullable', 'string', 'max:25']],
            // CFDI 4.0 exige el domicilio fiscal del receptor: un CP de 5 dígitos.
            'postal_code' => [__('Código postal'), 'text', [$obligatorioConCfdi, 'string', 'max:25', ...($cfdi ? ['regex:/^\d{5}$/'] : [])]],
            'country' => [__('País'), 'text', ['nullable', 'string', 'max:25']],
            'account_id' => [__('Divisa habitual'), 'select', ['nullable', 'integer']],
        ];

        // Los campos del CFDI solo salen donde se factura al SAT: en una
        // instalación sin timbrado son cuatro casillas que nadie sabe llenar.
        $fiscales = $cfdi ? [
            'regimen_fiscal_id' => [__('Régimen fiscal (SAT)'), 'select', ['required', 'string', 'max:3', Rule::in(array_keys(RegimenesFiscales::all()))]],
            'invoice_use' => [__('Uso del CFDI'), 'select', ['nullable', 'string', 'max:5', Rule::in(array_keys($this->optionsFor('invoice_use')))]],
            'pay_method' => [__('Método de pago'), 'select', ['nullable', 'string', 'max:5', Rule::in(array_keys($this->optionsFor('pay_method')))]],
            'pay_form' => [__('Forma de pago'), 'select', ['nullable', 'string', 'max:5', Rule::in(array_keys($this->optionsFor('pay_form')))]],
        ] : [];

        // Datos que solo tienen sentido en un cliente.
        return $comunes + $domicilio + $fiscales + [
            'match_pickup_place' => [__('Empatar el lugar de recolección al proponer sus servicios'), 'checkbox', ['boolean']],
            'email_notification' => [__('Correos para facturas y avisos (separados por coma)'), 'textarea', ['nullable', 'string', 'max:1000', $this->everyEmailIsValid()]],
            'notification_notes' => [__('Nota que va en la confirmación del booking'), 'textarea', ['nullable', 'string', 'max:1000']],
        ];
    }

    /** El teléfono ya viene limpio de todo lo que no sea dígito; tiene que caber en la columna. */
    private function phoneFits(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === '' || $value === null) {
                return;
            }

            if (strlen($value) > 10 || (int) $value > self::PHONE_MAX) {
                $fail(__('El teléfono no cabe en el campo: a lo más 10 dígitos y no mayor que :max. Captúralo sin lada internacional.', ['max' => self::PHONE_MAX]));
            }
        };
    }

    /** Cada correo de la lista, separado por coma o punto y coma, tiene que ser válido. */
    private function everyEmailIsValid(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $correos = array_filter(array_map('trim', preg_split('/[,;]+/', (string) $value) ?: []));

            foreach ($correos as $correo) {
                if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
                    $fail(__('«:correo» no es un correo válido.', ['correo' => $correo]));
                }
            }
        };
    }

    /**
     * Opciones de cada selector. Los tres catálogos del SAT viven en tablas sin
     * llave (`invoice_use`, `pay_method`, `pay_form`) y se muestran como
     * «clave - nombre», igual que en el original.
     *
     * @return array<int|string, string>
     */
    public function optionsFor(string $campo): array
    {
        return match ($campo) {
            'account_id' => $this->accountOptions,
            'type_id' => [
                Provider::TYPE_CARRIER => __('Naviera'),
                Provider::TYPE_TRANSPORT => __('Transportista'),
                Provider::TYPE_BROKER => __('Agente aduanal'),
            ],
            'regimen_fiscal_id' => RegimenesFiscales::options(),
            'invoice_use', 'pay_method', 'pay_form' => $this->satOptions($campo),
            default => [],
        };
    }

    /** Divisas del selector, leídas una vez por petición. @return array<int, string> */
    #[Computed]
    public function accountOptions(): array
    {
        return Account::options();
    }

    /** @var array<string, array<string, string>> Se leen una vez por petición. */
    private array $satOptions = [];

    /** @return array<string, string> */
    private function satOptions(string $tabla): array
    {
        return $this->satOptions[$tabla] ??= DB::table($tabla)->orderBy('code')->get(['code', 'name'])
            ->mapWithKeys(fn ($fila) => [trim((string) $fila->code) => trim((string) $fila->code).' - '.trim((string) $fila->name)])
            ->all();
    }

    /** La lista de este modo: a dónde regresa la ficha si no trae otra dirección. */
    public function listRoute(): string
    {
        return $this->isClient() ? 'parties.clients' : 'parties.providers';
    }
}
