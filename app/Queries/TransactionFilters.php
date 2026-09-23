<?php

namespace App\Queries;

/**
 * Filtros y modos del listado de transacciones.
 *
 * Equivale a las ~25 propiedades públicas de `TransactionSearch` en Yii2, pero
 * como objeto explícito: así se sabe qué se puede filtrar sin leer 500 líneas de
 * constructor de consulta.
 */
class TransactionFilters
{
    // --- Igualdades simples ---
    public ?int $transc_id = null;

    public ?int $account = null;

    public ?int $company_id = null;

    public ?int $booking = null;

    public ?int $customer = null;

    public ?int $vendor = null;

    /** `tran_type` exacto. En Yii2 podía llegar como arreglo desde las acciones. */
    public int|array|null $type = null;

    /** `invoice_type` exacto (normal, histórica o nota de crédito al cliente). */
    public ?int $invoice_type = null;

    // --- Búsquedas por texto (LIKE %valor%) ---
    public ?string $tran_number = null;

    public ?string $booking_number = null;

    public ?string $seal = null;

    /** Busca a la vez en el nombre del proveedor y en el del cliente. */
    public ?string $appliedTo = null;

    // --- Listas de IDs ---
    /** @var int[]|null Facturas a timbrar: fuerza tran_type=0 y sin CFDI previo. */
    public ?array $transc_id_in = null;

    /** @var int[]|null Filtro llano por IDs de transacción. */
    public ?array $tran_in = null;

    /** @var int[]|null */
    public ?array $booking_in = null;

    /** @var int[]|null */
    public ?array $type_in = null;

    /** Excluye las transacciones ya incluidas en esta solicitud de pago. */
    public ?int $notIn = null;

    // --- Rangos de fecha, formato "dd/mm/aaaa - dd/mm/aaaa" ---
    /** Sobre `transaction.tran_date`. */
    public ?string $dates = null;

    /** Sobre `booking.loading_EDT`. */
    public ?string $dates_booking = null;

    /** Sobre `payment_request.date`. */
    public ?string $request_date = null;

    // --- Estado ---
    /** null = sin filtro, 0 = Unpaid, 1 = Paid, 2 = Partial. */
    public ?int $paid = null;

    /** 0 = solo vigentes, 1 = vigentes y canceladas, 2 = solo canceladas. */
    public ?int $showCancelled = 0;

    public ?int $cancelled = null;

    /**
     * Estado de la cancelación ante el SAT: una de las `CfdiCancelacion::VISTA_*`
     * o null. Es lo que separa una factura cancelada de verdad de una que el
     * sistema da por cancelada y el SAT sigue viendo vigente.
     */
    public ?string $cfdiEstado = null;

    /** Valores de `$cfdiEstado` que miran el sello y no la bitácora de cancelaciones. */
    public const CFDI_SIN_TIMBRAR = 'sin_timbrar';

    public const CFDI_TIMBRADA = 'timbrada';

    public bool $onlyUndpaid = false;

    // --- Solicitud de pago ---
    public ?int $request_id = null;

    public ?int $request_type = null;

    // --- Modos que cambian la aritmética (no son filtros) ---
    /** No multiplicar por el tipo de cambio: deja los montos en su divisa. */
    public bool $noExchange = false;

    /** No invertir el signo de los costos. */
    public bool $noNegative = false;

    public bool $invoiceMode = false;

    public bool $paymentMode = false;

    /** false = bookings reales (mode 10); true = cotizaciones (mode 9). */
    public bool $showQuatation = false;

    /** Columna de agrupación: 'transc_id', 'booking', 'vendor' o 'customer'. */
    public string $groupBy = 'transc_id';

    // --- Orden ---
    public string $sort = 'transc_id';

    public string $direction = 'desc';

    public static function make(array $values = []): self
    {
        $filters = new self;

        foreach ($values as $key => $value) {
            if (property_exists($filters, $key)) {
                $filters->{$key} = $value;
            }
        }

        return $filters;
    }

    /**
     * `tran_type` como lista de enteros, venga como escalar o como arreglo.
     *
     * @return int[]
     */
    public function types(): array
    {
        if ($this->type === null || $this->type === '' || $this->type === []) {
            return [];
        }

        return array_map('intval', (array) $this->type);
    }

    /**
     * Los cinco tipos de documento que distingue el sistema, con clave legible
     * para la dirección. Son las combinaciones de `tran_type` e `invoice_type`
     * que existen en la base (los costos y sus notas de crédito siempre llevan
     * `invoice_type` 1, así que ahí no se filtra por él).
     *
     * @return array<string, array{type: int, invoice_type: ?int}>
     */
    public static function documentTypes(): array
    {
        return [
            'factura' => ['type' => 0, 'invoice_type' => 1],
            'historica' => ['type' => 0, 'invoice_type' => 2],
            'nota-credito-cliente' => ['type' => 0, 'invoice_type' => 3],
            'costo' => ['type' => 1, 'invoice_type' => null],
            'nota-credito' => ['type' => 2, 'invoice_type' => null],
        ];
    }

    /**
     * Acota el filtro a un tipo de documento. Solo estrecha: si la pantalla ya
     * limita los `tran_type` (Facturas, Costos), un tipo que no cabe ahí se
     * ignora en vez de abrir la pantalla a documentos que no le tocan.
     */
    public function applyDocumentType(?string $clave): void
    {
        $definicion = self::documentTypes()[$clave ?? ''] ?? null;

        if ($definicion === null) {
            return;
        }

        if ($this->types() !== [] && ! in_array($definicion['type'], $this->types(), true)) {
            return;
        }

        $this->type = [$definicion['type']];
        $this->invoice_type = $definicion['invoice_type'];
    }

    /**
     * Convierte "dd/mm/aaaa - dd/mm/aaaa" en [inicio, fin] con formato ISO.
     * Devuelve null si el valor no trae exactamente ese formato.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseRange(?string $range): ?array
    {
        if (empty($range) || ! str_contains($range, ' - ')) {
            return null;
        }

        [$from, $to] = explode(' - ', $range, 2);

        $iso = static function (string $date): ?string {
            $parts = explode('/', trim($date));

            if (count($parts) !== 3) {
                return null;
            }

            [$d, $m, $y] = $parts;

            return checkdate((int) $m, (int) $d, (int) $y)
                ? sprintf('%04d-%02d-%02d', $y, $m, $d)
                : null;
        };

        $start = $iso($from);
        $end = $iso($to);

        return ($start && $end) ? [$start, $end] : null;
    }

    /** ¿El filtro depende de los agregados y por tanto obliga a usar HAVING? */
    public function needsAggregateFilter(): bool
    {
        return $this->onlyUndpaid || ($this->paid !== null && $this->paid !== '');
    }

    public function groupedByTransaction(): bool
    {
        return in_array($this->groupBy, ['transc_id', 'transaction.transc_id'], true);
    }
}
