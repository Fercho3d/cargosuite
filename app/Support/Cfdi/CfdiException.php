<?php

namespace App\Support\Cfdi;

use RuntimeException;
use SoapFault;
use Throwable;

/** Algo impidió timbrar o cancelar: falta un dato, o el PAC rechazó. */
class CfdiException extends RuntimeException
{
    /**
     * @param  string|null  $codigo  Clave con la que rechazó el PAC (300, 402,
     *                               CFDI40211…). Se conserva porque el sistema
     *                               decide con ella: el 402 no es un error.
     */
    public function __construct(string $message, public readonly ?string $codigo = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Traduce el rechazo del PAC a un mensaje para quien factura, **sin ocultar
     * el código**: es lo que hay que repetirle al PAC si hay que llamarlo.
     */
    public static function delPac(SoapFault $falla): self
    {
        $codigo = self::codigoDe($falla);
        $texto = trim($falla->getMessage());

        // El texto del PAC a veces ya trae su propia clave; no se repite.
        $prefijo = $codigo !== null && ! str_contains($texto, $codigo) ? "[{$codigo}] " : '';

        return new self('El PAC respondió: '.$prefijo.$texto, codigo: $codigo, previous: $falla);
    }

    /**
     * El `faultcode` trae a veces el espacio de nombres del sobre SOAP
     * («soap:Server»), que no dice nada; solo interesa la clave del PAC.
     */
    private static function codigoDe(SoapFault $falla): ?string
    {
        $codigo = (string) preg_replace('/^.*:/', '', trim((string) ($falla->faultcode ?? '')));

        return preg_match('/^[A-Z]*\d{3,}$/', $codigo) === 1 ? $codigo : null;
    }
}
