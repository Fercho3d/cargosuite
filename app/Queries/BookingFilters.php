<?php

namespace App\Queries;

/**
 * Filtros del listado de bookings, equivalentes a las propiedades de
 * `BookingSearch` en Yii2.
 */
class BookingFilters
{
    // --- Búsquedas por texto ---
    public ?string $booking_number = null;

    public ?string $client_name = null;

    public ?string $vessel_name = null;

    public ?string $port_name = null;

    public ?string $commodity = null;

    // --- Igualdades ---
    public ?int $client = null;

    public ?int $vessel = null;

    public ?int $dicharge_port_id = null;

    public ?int $pick_up_place_id = null;

    public ?string $booking_type = null;

    // --- Rangos "dd/mm/aaaa - dd/mm/aaaa" ---
    /** Sobre `booking_continuity.pickup_date`. */
    public ?string $dates = null;

    /** Sobre `booking_continuity.SI_date`. */
    public ?string $si_filter = null;

    /** Sobre `booking.loading_EDT`. */
    public ?string $loading_EDT = null;

    /** Sobre `booking.dicharge_ETA`. */
    public ?string $dicharge_ETA = null;

    /** Sobre `booking.created_at`: el default del listado (año en curso). */
    public ?string $created = null;

    /**
     * Modo del booking: 10 = booking real, 9 = cotización. El listado del
     * sistema original solo enseña los reales y descarta los borradores.
     */
    public int $mode = 10;

    /**
     * Borradores: 0 = solo los bookings en firme (lo de siempre), 1 = solo los
     * borradores, null = los dos. El detalle usa null porque un borrador se
     * abre para capturarle contenedores y confirmarlo.
     */
    public ?int $is_draft = 0;

    public bool $onlyLocked = false;

    /** @param  array<string, mixed>  $values */
    public static function make(array $values = []): self
    {
        $filtros = new self;

        foreach ($values as $clave => $valor) {
            if (property_exists($filtros, $clave) && $valor !== null && $valor !== '') {
                $filtros->{$clave} = $valor;
            }
        }

        return $filtros;
    }

    /** @return array{0: string, 1: string}|null */
    public function range(string $property): ?array
    {
        return TransactionFilters::parseRange($this->{$property});
    }
}
