<?php

namespace App\Support\Cfdi;

/**
 * En qué quedó una solicitud de cancelación ante el PAC.
 *
 * Existe porque cancelar un CFDI **no es un sí o un no**: salvo en los casos que
 * el SAT deja cancelar sin aceptación, lo que devuelve el PAC es un acuse de
 * recibo («GT11: el receptor debe autorizar la cancelación»), y la factura sigue
 * vigente ante el SAT hasta que el receptor conteste o se le venza el plazo.
 *
 * Antes de esto el sistema marcaba la factura como cancelada en cuanto la
 * llamada al PAC no tronaba, y el ERP decía «cancelada» mientras el SAT seguía
 * diciendo «Vigente». La verdad fiscal es la del SAT, y aquí se distingue.
 */
readonly class CancelResult
{
    /** El SAT ya la tiene por cancelada: no hace falta esperar a nadie. */
    public const CANCELADA = 'cancelada';

    /** El PAC recibió la solicitud; falta que el receptor la autorice. */
    public const SOLICITADA = 'solicitada';

    /** Ya había una solicitud en curso para este folio (el PAC responde 402). */
    public const EN_COLA = 'en_cola';

    /** El receptor la rechazó o el SAT no la admitió. */
    public const RECHAZADA = 'rechazada';

    /** Los estados que siguen esperando respuesta del receptor o del SAT. */
    public const PENDIENTES = [self::SOLICITADA, self::EN_COLA];

    public function __construct(
        public string $estado,
        public ?string $codigo,
        public string $mensaje,
    ) {}

    /**
     * ¿El comprobante quedó cancelado de verdad?
     *
     * Es lo único que autoriza a marcar `transaction.cancelled`. Una solicitud
     * recibida o encolada NO lo es.
     */
    public function esCancelacionConfirmada(): bool
    {
        return $this->estado === self::CANCELADA;
    }
}
