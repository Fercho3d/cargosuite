<?php

namespace App\Support\Milestones;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El cumplimiento de los hitos: la lista de verificación (`check_list`).
 *
 * La continuidad tiene dos capas, y conviene no confundirlas:
 *
 * · la fecha **planeada** de cada hito, en `hito_por_expediente` (con espejo en
 *   `booking_continuity`), que captura `BookingMilestones`;
 * · el **cumplimiento**, en `check_list`: por casilla, cuándo se marcó
 *   (`<casilla>_chk_date`) y quién (`<casilla>_chk_by`). Eso es lo que escribe
 *   esta clase.
 *
 * Marcar un hito escribe aquí y NO toca la fecha planeada: antes «marcar»
 * pisaba la estimación con la fecha de hoy y se perdía contra qué comparar.
 *
 * La casilla de cada hito es su columna heredada: en el catálogo de origen la
 * clave del hito, su columna de `booking_continuity` y el prefijo de su casilla
 * en `check_list` son la misma palabra. Un hito sin columna heredada no tiene
 * casilla y no pasa por aquí.
 *
 * `check_list_history` la escribe un disparador de la base, igual que
 * `containers_history`; por eso cada escritura firma `modified_by`.
 */
class Checklist
{
    /** Las 28 casillas de `check_list`, por prefijo de columna. */
    public const CASILLAS = [
        'booking_number', 'pickup_date', 'modality', 'doc_cut_of', 'SI_date', 'cleared',
        'departure', 'bl_payment', 'swb', 'vessel', 'number', 'client', 'loading_port',
        'loading_EDT', 'dicharge_port', 'container_type', 'commodity', 'set_point',
        'dicharge_ETA', 'vacuum_maneuver', 'draf_client', 'gated_IN', 'gated_out',
        'delivered', 'pick_up_place', 'insurance', 'corrected_draft', 'vgm',
    ];

    /**
     * Las verificaciones de DATOS del booking, en el orden del original: que
     * el número, el cliente, el buque… estén bien capturados, más la modalidad.
     * Son casillas de `check_list` sin hito detrás, y abren la lista en el
     * sistema de origen (`booking-continuity/_form.php`).
     *
     * Solo aplican donde el avance es el heredado (`marca.avance` en
     * `verificacion`): ahí cuentan en el porcentaje. En cualquier otra
     * instalación el avance son los hitos y estas casillas no significan nada.
     *
     * @var array<string, string> casilla => etiqueta
     */
    public const DATOS_DEL_BOOKING = [
        'booking_number' => 'Número de booking',
        'client' => 'Cliente',
        'vessel' => 'Buque',
        'loading_port' => 'Puerto de carga',
        'loading_EDT' => 'Fecha de carga',
        'dicharge_port' => 'Puerto de descarga',
        'dicharge_ETA' => 'Fecha de arribo',
        'container_type' => 'Tipo de contenedor',
        'commodity' => 'Mercancía',
        'set_point' => 'Temperatura',
        'pick_up_place' => 'Lugar de recolección',
        'modality' => 'Modalidad',
    ];

    /** ¿Esta instalación verifica los datos del booking? Ver `DATOS_DEL_BOOKING`. */
    public static function conDatosDelBooking(): bool
    {
        return config('marca.avance') === 'verificacion';
    }

    /** Prefijo de la casilla de un hito en `check_list`, o null si no tiene. */
    public static function casilla(?object $hito): ?string
    {
        $columna = $hito?->columna_legado;

        return $columna !== null && in_array($columna, self::CASILLAS, true) ? $columna : null;
    }

    /**
     * Cumplimiento por clave de hito de un expediente. Los hitos sin marcar no
     * salen en el arreglo.
     *
     * @return array<string, array{fecha: string, por: ?int}>
     */
    public static function de(int $booking): array
    {
        return self::deVarios([$booking])[$booking] ?? [];
    }

    /**
     * Lo mismo para varios expedientes en una consulta, para la rejilla.
     *
     * @param  list<int>  $bookings
     * @return array<int, array<string, array<string, array{fecha: string, por: ?int}>>>
     */
    public static function deVarios(array $bookings): array
    {
        $hitos = MilestoneCatalog::todos()->filter(fn (object $hito) => self::casilla($hito) !== null);
        $salida = [];

        foreach (self::casillasDeVarios($bookings) as $booking => $casillas) {
            foreach ($hitos as $hito) {
                if (isset($casillas[self::casilla($hito)])) {
                    $salida[$booking][$hito->clave] = $casillas[self::casilla($hito)];
                }
            }
        }

        return $salida;
    }

    /**
     * Las casillas marcadas de un expediente, por casilla y no por hito: es lo
     * que necesitan las verificaciones de datos y la regla de orden.
     *
     * @return array<string, array{fecha: string, por: ?int}>
     */
    public static function casillasDe(int $booking): array
    {
        return self::casillasDeVarios([$booking])[$booking] ?? [];
    }

    /**
     * @param  list<int>  $bookings
     * @return array<int, array<string, array{fecha: string, por: ?int}>>
     */
    private static function casillasDeVarios(array $bookings): array
    {
        if ($bookings === [] || ! Schema::hasTable('check_list')) {
            return [];
        }

        $salida = [];

        foreach (DB::table('check_list')->whereIn('booking', $bookings)->get() as $fila) {
            foreach (self::CASILLAS as $casilla) {
                if (($fila->{$casilla.'_chk_date'} ?? null) !== null) {
                    $salida[(int) $fila->booking][$casilla] = [
                        'fecha' => (string) $fila->{$casilla.'_chk_date'},
                        'por' => $fila->{$casilla.'_chk_by'} === null ? null : (int) $fila->{$casilla.'_chk_by'},
                    ];
                }
            }
        }

        return $salida;
    }

    /** Marca el hito como cumplido ahora (o en la fecha dada) por ese usuario. */
    public static function marca(int $booking, string $clave, int $usuario, ?string $fecha = null): void
    {
        $casilla = self::casilla(MilestoneCatalog::porClave($clave));

        if ($casilla !== null) {
            self::marcaCasilla($booking, $casilla, $usuario, $fecha);
        }
    }

    /** Quita la marca: fecha y autor en nulo. Quién lo quitó queda en `modified_by`. */
    public static function desmarca(int $booking, string $clave, int $usuario): void
    {
        $casilla = self::casilla(MilestoneCatalog::porClave($clave));

        if ($casilla !== null) {
            self::desmarcaCasilla($booking, $casilla, $usuario);
        }
    }

    /** Lo mismo por casilla, para las verificaciones de datos. */
    public static function marcaCasilla(int $booking, string $casilla, int $usuario, ?string $fecha = null): void
    {
        self::escribe($booking, [
            $casilla.'_chk_date' => $fecha ?? now()->format('Y-m-d H:i:s'),
            $casilla.'_chk_by' => $usuario,
            'modified_by' => $usuario,
        ]);
    }

    public static function desmarcaCasilla(int $booking, string $casilla, int $usuario): void
    {
        self::escribe($booking, [
            $casilla.'_chk_date' => null,
            $casilla.'_chk_by' => null,
            'modified_by' => $usuario,
        ]);
    }

    /**
     * La fila de `check_list` se crea al vuelo: los expedientes viejos no la
     * tienen hasta que alguien marca algo, igual que en el original.
     *
     * @param  array<string, mixed>  $valores
     */
    private static function escribe(int $booking, array $valores): void
    {
        DB::table('check_list')->updateOrInsert(['booking' => $booking], $valores);
    }

    /**
     * La lista completa, en el orden en que se marca: primero las
     * verificaciones de datos (donde aplican) y luego los hitos activos con
     * casilla, en el orden del catálogo.
     *
     * @return array<string, string> casilla => etiqueta
     */
    public static function orden(): array
    {
        $datos = self::conDatosDelBooking()
            ? array_map(fn (string $etiqueta) => __($etiqueta), self::DATOS_DEL_BOOKING)
            : [];

        $hitos = MilestoneCatalog::activos()
            ->filter(fn (object $hito) => self::casilla($hito) !== null)
            ->mapWithKeys(fn (object $hito) => [self::casilla($hito) => $hito->etiqueta])
            ->all();

        return $datos + $hitos;
    }

    /**
     * La casilla que va antes de esta en `orden()`, con su etiqueta.
     *
     * Es la regla del original para quien no es administrador: no se marca una
     * tarea si la anterior no está marcada. Y como allá, la cadena es una sola:
     * el primer hito exige la última verificación de datos.
     *
     * @return object{casilla: string, etiqueta: string}|null
     */
    public static function anterior(string $casilla): ?object
    {
        $orden = array_keys(self::orden());
        $posicion = array_search($casilla, $orden, true);

        if ($posicion === false || $posicion === 0) {
            return null;
        }

        $previa = $orden[$posicion - 1];

        return (object) ['casilla' => $previa, 'etiqueta' => self::orden()[$previa]];
    }

    /**
     * «Delivery time»: cuánto se apartó el cumplimiento de la fecha planeada.
     *
     * Porta `CheckList::calcDeliveryTime()` del original: a tiempo si la real
     * no rebasa la planeada; si no, el retraso en días, horas y minutos. Cuando
     * la planeada se capturó sin hora se compara por día, porque una fecha
     * «para el martes» no está retrasada el martes a las diez de la mañana.
     *
     * @return array{texto: string, aTiempo: bool}|null
     */
    public static function retraso(?string $planeada, ?string $real): ?array
    {
        if (blank($planeada) || blank($real)) {
            return null;
        }

        $plan = Carbon::parse($planeada);
        $cumplida = Carbon::parse($real);

        if ($plan->format('H:i:s') === '00:00:00') {
            $plan = $plan->endOfDay();
        }

        if ($cumplida->lessThanOrEqualTo($plan)) {
            return ['texto' => __('A tiempo'), 'aTiempo' => true];
        }

        $diferencia = $plan->diff($cumplida);
        $partes = array_filter([
            $diferencia->days > 0 ? trans_choice('{1}:n día|[2,*]:n días', $diferencia->days, ['n' => $diferencia->days]) : null,
            $diferencia->h > 0 ? trans_choice('{1}:n hora|[2,*]:n horas', $diferencia->h, ['n' => $diferencia->h]) : null,
            $diferencia->i > 0 ? trans_choice('{1}:n minuto|[2,*]:n minutos', $diferencia->i, ['n' => $diferencia->i]) : null,
        ]);

        return [
            'texto' => __(':tiempo de retraso', ['tiempo' => implode(', ', $partes) ?: __('menos de un minuto')]),
            'aTiempo' => false,
        ];
    }
}
