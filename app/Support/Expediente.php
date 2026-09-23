<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Qué campos del expediente pide esta instalación.
 *
 * `booking` tiene 53 columnas con vocabulario de carga marítima. A un taller le
 * sobran la naviera, el agente aduanal, el tipo de contenedor y la temperatura,
 * y dejarlos ahí no es un detalle estético: son casillas que el operador no sabe
 * llenar y que ensucian todas las pantallas.
 *
 * Aquí se apagan por `.env`, sin tocar el esquema. El campo apagado desaparece
 * del formulario y del detalle, **no se valida y no se escribe** — lo que ya
 * estuviera guardado se queda como está, que es lo que hay que hacer si alguien
 * apaga un campo en una instalación con historial.
 *
 * ⚠️ Solo se pueden apagar los campos OPCIONALES. Los obligatorios —folio,
 * cliente, ruta y fechas— sostienen los listados, los reportes y la generación
 * de facturación; apagarlos rompería el sistema en sitios que no se ven.
 */
class Expediente
{
    /** Llave del recuerdo de los campos propios dentro del contenedor. */
    private const MEMORIA = 'expediente.campos-propios';

    /**
     * Los campos que se pueden apagar, por nombre de propiedad del formulario.
     * La clave es la propiedad y el valor, la columna de `booking`.
     */
    public const OPCIONALES = [
        'hb' => 'HB',
        'customerReference' => 'customer_reference',
        'bookingType' => 'booking_type',
        'carrierId' => 'carrier_id',
        'transportId' => 'transport_id',
        'brokerId' => 'custom_brocker_id',
        'finalDestination' => 'final_destination_id',
        'containerType' => 'container_type',
        'commodity' => 'commodity',
        'setPoint' => 'set_point',
        'remarks' => 'remarks',

        /*
         * El buque. Es apagable porque en una empresa de camiones **no existe**:
         * el medio es el tractor, y ese vive en `unidad` con sus placas, su
         * seguro y su verificación. Reciclar el buque como «camión» obligaría a
         * mantener la flota en dos lugares, y el campo está atado al listado, al
         * filtro y al PDF de confirmación.
         *
         * Apagado, el expediente se guarda sin buque; encendido, sigue siendo
         * obligatorio como siempre.
         */
        'vesselId' => 'vessel',

        // Flota propia. Opcionales a propósito: quien subcontrata el transporte
        // los apaga y no ve tres selectores que nunca va a llenar.
        'operadorId' => 'operador_id',
        'unidadId' => 'unidad_id',
        'cajaId' => 'caja_id',
    ];

    /** @return list<string> */
    public static function ocultos(): array
    {
        $configurados = array_filter(array_map('trim', explode(',', (string) config('marca.expediente_ocultos'))));

        // Un campo obligatorio no se apaga aunque lo pidan: se ignora en vez de
        // dejar el sistema a medias. `ExpedienteTest` avisa de la errata.
        return array_values(array_intersect($configurados, array_keys(self::OPCIONALES)));
    }

    /**
     * Propiedad del formulario → columna de `booking`, para TODOS los campos.
     * Los obligatorios están aquí también porque el guardado los necesita; lo
     * que no se puede apagar son los que no aparecen en `OPCIONALES`.
     */
    public const COLUMNAS = [
        'bookingNumber' => 'booking_number',
        'clientId' => 'client',
        'loadingPort' => 'loading_port',
        'loadingDate' => 'loading_EDT',
        'dischargePort' => 'dicharge_port_id',
        'arrivalDate' => 'dicharge_ETA',
        'pickupPlace' => 'pick_up_place_id',
    ] + self::OPCIONALES;

    /**
     * Qué campos trae cada modalidad de transporte.
     *
     * Las dos capacidades viven siempre en el código; lo que cambia es cuál se
     * enseña. Una empresa mixta enciende las dos y ve todo.
     */
    public const POR_MODALIDAD = [
        'maritimo' => ['vesselId'],
        'terrestre' => ['operadorId', 'unidadId', 'cajaId'],
    ];

    /** @return list<string> */
    public static function modalidades(): array
    {
        $configuradas = array_filter(array_map('trim', explode(',', (string) config('marca.modalidades'))));

        // Una modalidad inventada se ignora; quedarse sin ninguna dejaría el
        // expediente sin medio de transporte, así que se cae a la de origen.
        $validas = array_values(array_intersect($configuradas, array_keys(self::POR_MODALIDAD)));

        return $validas === [] ? ['maritimo'] : $validas;
    }

    /** ¿Esta instalación mueve de esta forma? */
    public static function usa(string $modalidad): bool
    {
        return in_array($modalidad, self::modalidades(), true);
    }

    public static function visible(string $campo): bool
    {
        if (in_array($campo, self::ocultos(), true)) {
            return false;
        }

        // Si el campo pertenece a una modalidad, solo se ve con esa encendida.
        foreach (self::POR_MODALIDAD as $modalidad => $campos) {
            if (in_array($campo, $campos, true)) {
                return in_array($modalidad, self::modalidades(), true);
            }
        }

        return true;
    }

    /**
     * Quita de un arreglo indexado por propiedad lo que esta instalación no usa.
     * Sirve para las reglas de validación, que van por propiedad.
     *
     * @param  array<string, mixed>  $porPropiedad
     * @return array<string, mixed>
     */
    public static function soloVisibles(array $porPropiedad): array
    {
        return array_filter(
            $porPropiedad,
            fn (string $propiedad) => self::visible($propiedad),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Los campos propios de esta instalación, activos y en orden.
     *
     * No se cachea: son un puñado de filas, y cachear catálogos en este sistema
     * ya provocó dos caídas. Se recuerda por petición en el contenedor, que se
     * vacía solo entre peticiones y entre pruebas.
     *
     * @return Collection<int, object>
     */
    public static function propios(): Collection
    {
        if (app()->bound(self::MEMORIA)) {
            return app(self::MEMORIA);
        }

        if (! Schema::hasTable('campo_expediente')) {
            return collect();
        }

        $campos = DB::table('campo_expediente')
            ->where('activo', 1)
            ->orderBy('orden')
            ->orderBy('campo_id')
            ->get()
            ->map(fn (object $campo) => (object) [
                'campo_id' => (int) $campo->campo_id,
                'clave' => (string) $campo->clave,
                'etiqueta' => __((string) $campo->etiqueta),
                'tipo' => (string) $campo->tipo,
                'grupo' => $campo->grupo === null || $campo->grupo === '' ? __('Otros datos') : __((string) $campo->grupo),
                'obligatorio' => (bool) $campo->obligatorio,
                'opciones' => array_values(array_filter(array_map(
                    'trim',
                    explode('|', (string) ($campo->opciones ?? '')),
                ))),
            ]);

        app()->instance(self::MEMORIA, $campos);

        return $campos;
    }

    /** Se olvida lo recordado. Hay que llamarlo al cambiar el catálogo. */
    public static function olvida(): void
    {
        app()->forgetInstance(self::MEMORIA);
    }

    /**
     * Lo capturado en los campos propios de un expediente, por clave.
     *
     * @return array<string, string|null>
     */
    public static function valores(?int $booking): array
    {
        $campos = self::propios();
        $vacios = $campos->pluck('clave')->mapWithKeys(fn (string $c) => [$c => null])->all();

        if ($booking === null || $campos->isEmpty()) {
            return $vacios;
        }

        $porId = $campos->pluck('clave', 'campo_id');

        $guardados = DB::table('valor_por_expediente')
            ->where('booking', $booking)
            ->pluck('valor', 'campo_id')
            ->mapWithKeys(fn (?string $valor, int $id) => isset($porId[$id]) ? [$porId[$id] => $valor] : [])
            ->all();

        return array_merge($vacios, $guardados);
    }

    /**
     * Guarda los campos propios de un expediente.
     *
     * Solo toca los campos ACTIVOS: si alguien apaga un campo, lo que ya se
     * capturó en él se queda donde está, igual que con los campos de siempre.
     *
     * @param  array<string, mixed>  $valores
     */
    public static function guardaValores(int $booking, array $valores): void
    {
        foreach (self::propios() as $campo) {
            if (! array_key_exists($campo->clave, $valores)) {
                continue;
            }

            $valor = $valores[$campo->clave];
            $valor = $valor === '' || $valor === null ? null : (string) $valor;

            DB::table('valor_por_expediente')->updateOrInsert(
                ['booking' => $booking, 'campo_id' => $campo->campo_id],
                ['valor' => $valor],
            );
        }
    }

    /**
     * Reglas de validación de los campos propios, con la forma que espera
     * Livewire para un arreglo (`propios.clave`).
     *
     * @return array<string, list<string>>
     */
    public static function reglasPropias(): array
    {
        $reglas = [];

        foreach (self::propios() as $campo) {
            $reglas['propios.'.$campo->clave] = array_merge(
                [$campo->obligatorio ? 'required' : 'nullable'],
                match ($campo->tipo) {
                    'number' => ['numeric'],
                    'date' => ['date'],
                    'boolean' => ['boolean'],
                    'select' => $campo->opciones === [] ? ['string'] : [Rule::in($campo->opciones)],
                    default => ['string', 'max:255'],
                },
            );
        }

        return $reglas;
    }

    /**
     * Etiquetas de los campos propios con la forma que espera el validador, para
     * que un error diga «El campo número de serie es obligatorio» y no
     * «propios.numero_serie».
     *
     * @return array<string, string>
     */
    public static function etiquetasPropias(): array
    {
        return self::propios()
            ->mapWithKeys(fn (object $campo) => ['propios.'.$campo->clave => mb_strtolower($campo->etiqueta)])
            ->all();
    }

    /**
     * Lo mismo, pero además cambia las claves a los nombres de columna, que es
     * lo que necesita el guardado.
     *
     * @param  array<string, mixed>  $porPropiedad
     * @return array<string, mixed>
     */
    public static function aColumnas(array $porPropiedad): array
    {
        $salida = [];

        foreach (self::soloVisibles($porPropiedad) as $propiedad => $valor) {
            $columna = self::COLUMNAS[$propiedad] ?? null;

            if ($columna !== null) {
                $salida[$columna] = $valor;
            }
        }

        return $salida;
    }
}
