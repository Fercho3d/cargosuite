<?php

namespace App\Support\History;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La historia de un booking: quién cambió qué y cuándo.
 *
 * El sistema guarda cuatro bitácoras —del booking, de sus contenedores, de su
 * continuidad y de su lista de verificación— y **las escribe la propia base de
 * datos**, no la aplicación: en el código de Yii2 nadie inserta en ellas. Por eso
 * aquí solo se leen; siguen llenándose igual con la aplicación nueva.
 *
 * En Yii2 esto eran cuatro rejillas, una por tabla, con todas las columnas de
 * cada una —el booking tiene cincuenta y siete— y las que habían cambiado
 * pintadas de rojo. Aquí se calcula ese mismo cambio y se enseña **solo lo que
 * cambió**, en una sola línea de tiempo: la misma información, legible y sin
 * cincuenta columnas de ancho.
 */
class BookingTimeline
{
    /** Columnas de control que no son cambios que interesen. */
    private const IGNORADAS = [
        'change_type', 'change_date', 'trash',
        'created_at', 'created_by', 'modified_at', 'modified_by',
        'booking_id', 'booking', 'cont_id', 'check_id', 'container_ID',
    ];

    /**
     * Nombre visible de cada columna. Las que no estén aquí salen con el nombre
     * de la columna, que es mejor que esconderlas.
     *
     * @var array<string, string>
     */
    private const ETIQUETAS = [
        'booking_number' => 'Número de booking', 'HB' => 'HB', 'customer_reference' => 'Referencia del cliente',
        'client' => 'Cliente', 'vessel' => 'Buque', 'loading_port' => 'Puerto de carga',
        'loading_EDT' => 'Fecha de carga', 'dicharge_port' => 'Puerto de descarga (texto)',
        'dicharge_port_id' => 'Puerto de descarga', 'dicharge_ETA' => 'Arribo estimado',
        'container_type' => 'Tipo de contenedor', 'commodity' => 'Mercancía', 'set_point' => 'Temperatura',
        'pick_up_place' => 'Lugar de recolección (texto)', 'pick_up_place_id' => 'Lugar de recolección',
        'carrier' => 'Naviera (texto)', 'carrier_id' => 'Naviera', 'transport_id' => 'Transportista',
        'custom_brocker_id' => 'Agente aduanal', 'final_destination' => 'Destino final (texto)',
        'final_destination_id' => 'Destino final', 'booking_type' => 'Tipo de booking',
        'email_notification' => 'Correos de notificación', 'is_draft' => 'Borrador', 'locked' => 'Cerrado',
        'mode' => 'Modo', 'remarks' => 'Notas',
        // La lista de verificación del booking (`Booking::LISTA_DE_VERIFICACION`).
        'arrival' => 'Arribo', 'realeased_from_shiping' => 'Liberado por la naviera',
        'customs_cleared' => 'Despachado en aduana', 'truck_service_request' => 'Transporte solicitado',
        'delivered_consigned' => 'Entregado al consignatario',
        'shipper_is' => 'Shipper (es)', 'shipper_should' => 'Shipper (debe ser)',
        'consignee_is' => 'Consignee (es)', 'consignee_should' => 'Consignee (debe ser)',
        'notify_party_is' => 'Notify party (es)', 'notify_party_should' => 'Notify party (debe ser)',
        'description_is' => 'Descripción (es)', 'description_should' => 'Descripción (debe ser)',
        'quantity' => 'Cantidad', 'comodity' => 'Mercancía', 'number' => 'Número', 'seal' => 'Sello',
        'pick_up_date' => 'Fecha de recolección', 'pickup_date' => 'Fecha de recolección',
        'modality' => 'Modalidad', 'vacuum_maneuver' => 'Maniobra de vacío', 'doc_cut_of' => 'Corte documental',
        'SI_date' => 'Instrucciones de embarque', 'draf_client' => 'Draft del cliente', 'gated_IN' => 'Gate in',
        'cleared' => 'Despacho aduanal', 'departure' => 'Zarpe', 'bl_payment' => 'Pago del BL', 'swb' => 'SWB',
        'delivered' => 'Entregado', 'gated_out' => 'Gate out', 'insurance' => 'Seguro',
        'corrected_draft' => 'Draft corregido', 'vgm' => 'VGM',
    ];

    /** Columnas que guardan el id de un catálogo, con la tabla de la que sale el nombre. */
    private const CATALOGOS = [
        'client' => ['client', 'client_id', 'fullName'],
        'vessel' => ['vessel', 'vessel_id', 'vessel_name'],
        'loading_port' => ['loading_ports', 'port_id', 'port_name'],
        'dicharge_port_id' => ['dicharge_port', 'dicharge_port_id', 'name'],
        'pick_up_place_id' => ['pickup_place', 'pick_id', 'name'],
        'final_destination_id' => ['final_destination', 'final_destination_id', 'name'],
        'carrier_id' => ['provider', 'provider_id', 'fullName'],
        'transport_id' => ['provider', 'provider_id', 'fullName'],
        'custom_brocker_id' => ['provider', 'provider_id', 'fullName'],
        'container_type' => ['container_types', 'contType_id', 'container_name'],
        'modality' => ['modality', 'modality_id', 'modality_name'],
    ];

    /**
     * La historia completa del booking, de lo más reciente a lo más viejo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forBooking(int $bookingId): Collection
    {
        return collect()
            ->merge($this->changes('booking_history', 'booking_id', $bookingId, __('Booking')))
            ->merge($this->containerChanges($bookingId))
            ->merge($this->changes('booking_continuity_history', 'booking', $bookingId, __('Continuidad')))
            ->merge($this->checklistChanges($bookingId))
            ->sortByDesc('fecha')
            ->values();
    }

    /**
     * Los cambios de una bitácora, comparando cada renglón con el anterior.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function changes(string $tabla, string $columna, int $bookingId, string $origen): Collection
    {
        $filas = DB::table($tabla)->where($columna, $bookingId)->orderBy('change_date')->get();

        return $this->diff($filas, $origen);
    }

    /** Cada contenedor lleva su propia serie: se comparan entre ellos, no mezclados. */
    private function containerChanges(int $bookingId): Collection
    {
        return DB::table('containers_history')
            ->where('booking', $bookingId)
            ->orderBy('container_ID')
            ->orderBy('change_date')
            ->get()
            ->groupBy('container_ID')
            ->flatMap(fn (Collection $serie, $id) => $this->diff($serie->values(), __('Contenedor :id', ['id' => $id])));
    }

    /**
     * La lista de verificación se lee distinto: no cambian valores, se marcan y
     * se desmarcan casillas. Cada marca lleva quién la hizo (`{casilla}_chk_by`),
     * que no siempre es quien firmó el renglón (`modified_by`).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function checklistChanges(int $bookingId): Collection
    {
        $filas = DB::table('check_list_history')->where('booking', $bookingId)->orderBy('change_date')->get();
        $eventos = collect();
        $anterior = null;

        foreach ($filas as $fila) {
            $marcas = [];

            foreach ((array) $fila as $columna => $valor) {
                if (! str_ends_with($columna, '_chk_date')) {
                    continue;
                }

                $casilla = substr($columna, 0, -strlen('_chk_date'));
                $antes = $anterior?->{$columna};

                if (filled($valor) && blank($antes)) {
                    $marcas[] = [
                        'campo' => __(self::etiqueta($casilla)), 'antes' => null, 'despues' => __('marcada'),
                        'por' => $this->userName($fila->{$casilla.'_chk_by'} ?? null),
                    ];
                } elseif (blank($valor) && filled($antes)) {
                    $marcas[] = ['campo' => __(self::etiqueta($casilla)), 'antes' => __('marcada'), 'despues' => null];
                }
            }

            if ($marcas !== [] || $anterior === null) {
                $eventos->push($this->event($fila, __('Lista de verificación'), $marcas));
            }

            $anterior = $fila;
        }

        return $eventos;
    }

    /**
     * @param  Collection<int, object>  $filas
     * @return Collection<int, array<string, mixed>>
     */
    private function diff(Collection $filas, string $origen): Collection
    {
        $eventos = collect();
        $anterior = null;

        foreach ($filas as $fila) {
            $cambios = [];

            foreach ((array) $fila as $columna => $valor) {
                if (in_array($columna, self::IGNORADAS, true)) {
                    continue;
                }

                $antes = $anterior?->{$columna};

                if ($anterior !== null && (string) $antes === (string) $valor) {
                    continue;
                }

                if ($anterior === null && blank($valor)) {
                    continue;
                }

                $cambios[] = [
                    'campo' => __(self::etiqueta($columna)),
                    'antes' => $anterior === null ? null : $this->display($columna, $antes),
                    'despues' => $this->display($columna, $valor),
                ];
            }

            if ($cambios !== [] || $anterior === null) {
                $eventos->push($this->event($fila, $origen, $cambios));
            }

            $anterior = $fila;
        }

        return $eventos;
    }

    /** @param  array<int, array<string, mixed>>  $cambios */
    private function event(object $fila, string $origen, array $cambios): array
    {
        return [
            'origen' => $origen,
            'tipo' => (string) ($fila->change_type ?? 'UPDATE'),
            'fecha' => $fila->change_date,
            'usuario' => $this->userName($fila->modified_by ?? null),
            'cambios' => $cambios,
        ];
    }

    /** Nombre visible de una columna, para quien la enseñe fuera del historial. */
    public static function etiqueta(string $columna): string
    {
        return self::ETIQUETAS[$columna] ?? $columna;
    }

    /** Traduce ids de catálogo y fechas a algo que se pueda leer. */
    private function display(string $columna, mixed $valor): ?string
    {
        if (blank($valor)) {
            return null;
        }

        if (isset(self::CATALOGOS[$columna])) {
            [$tabla, $llave, $nombre] = self::CATALOGOS[$columna];

            return $this->catalogName($tabla, $llave, $nombre, $valor) ?? (string) $valor;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}:\d{2})?$/', (string) $valor) === 1) {
            $fecha = Carbon::parse((string) $valor);

            return $fecha->format($fecha->format('H:i:s') === '00:00:00' ? 'd/m/Y' : 'd/m/Y H:i');
        }

        return (string) $valor;
    }

    /** @var array<string, array<string, string|null>> */
    private array $cache = [];

    private function catalogName(string $tabla, string $llave, string $nombre, mixed $valor): ?string
    {
        $this->cache[$tabla] ??= DB::table($tabla)->pluck($nombre, $llave)
            ->map(fn ($v) => $v === null ? null : (string) $v)->all();

        return $this->cache[$tabla][(int) $valor] ?? null;
    }

    private function userName(mixed $usrId): ?string
    {
        if (blank($usrId)) {
            return null;
        }

        $this->cache['users'] ??= DB::table('users')->pluck('name', 'usr_id')
            ->map(fn ($v) => $v === null ? null : (string) $v)->all();

        return $this->cache['users'][(int) $usrId] ?? null;
    }
}
