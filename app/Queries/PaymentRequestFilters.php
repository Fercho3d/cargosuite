<?php

namespace App\Queries;

use Illuminate\Support\Carbon;

/**
 * Filtros y modos del motor de solicitudes de pago.
 *
 * Equivale a las propiedades públicas de `PaymentRequestSearch` en Yii2.
 */
class PaymentRequestFilters
{
    // --- Igualdades simples ---
    public ?int $request_id = null;

    public ?int $transc_id = null;

    public ?int $bank_id = null;

    public ?int $provider_id = null;

    public ?int $client_id = null;

    public ?int $currency_id = null;

    /** 1 = cobro a cliente, 2 = pago a proveedor. */
    public ?int $type = null;

    public ?string $number = null;

    public ?int $paid = null;

    // --- Fechas ---
    /** Rango sobre `payment_request.date`, formato "dd/mm/aaaa - dd/mm/aaaa". */
    public ?string $dates = null;

    /**
     * Fecha con la que se valúa lo pagado ("dd/mm/aaaa"). De ella sale la
     * columna `total_to_pay` y la diferencia cambiaria contra el documento.
     */
    public ?string $date_pay = null;

    // --- Modos que cambian la aritmética ---
    /** No multiplicar por el tipo de cambio: deja los montos en su divisa. */
    public bool $noExchange = false;

    /** No invertir el signo de los pagos a proveedor. */
    public bool $noNegative = false;

    /**
     * Agrupación: 'request' (una fila por solicitud), 'client', 'provider'
     * (proveedor y divisa) o 'type'.
     */
    public string $groupBy = 'request';

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

    /** La fecha de valuación en formato de base de datos, o null. */
    public function payDate(): ?string
    {
        if (blank($this->date_pay)) {
            return null;
        }

        return Carbon::createFromFormat('d/m/Y', trim($this->date_pay))->toDateString();
    }

    /**
     * Rango de fechas ya convertido, o null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function dateRange(): ?array
    {
        return TransactionFilters::parseRange($this->dates);
    }
}
