<?php

namespace Tests\Support;

use App\Support\Cfdi\CancelResult;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\PacClient;
use App\Support\Cfdi\StampedInvoice;

/**
 * PAC de mentiras para las pruebas.
 *
 * Guarda lo que se le mandó para poder afirmar sobre el layout, y nunca sale a
 * la red. Con `$falla` se simula un rechazo del PAC.
 *
 * `$respuestaCancelacion` decide en qué queda la cancelación. Por omisión es lo
 * que contesta el PAC de verdad en producción: un acuse GT11 a la espera de que
 * el receptor autorice, **no** una cancelación consumada.
 */
class FakePacClient implements PacClient
{
    public ?string $layoutRecibido = null;

    /** @var array<int, array<string, mixed>> */
    public array $cancelaciones = [];

    public CancelResult $respuestaCancelacion;

    public function __construct(
        public string $uuid = '11111111-2222-3333-4444-555555555555',
        public ?string $falla = null,
    ) {
        $this->respuestaCancelacion = new CancelResult(
            CancelResult::SOLICITADA,
            'GT11',
            'Solicitud de cancelación enviada. El receptor debe autorizarla; si no responde en 72 horas, el SAT la cancela por plazo vencido.',
        );
    }

    /** Encadenable, para dejar en una línea qué va a contestar el PAC. */
    public function responde(CancelResult $resultado): static
    {
        $this->respuestaCancelacion = $resultado;

        return $this;
    }

    public function stamp(string $layout): StampedInvoice
    {
        $this->layoutRecibido = $layout;

        if ($this->falla !== null) {
            throw new CfdiException($this->falla);
        }

        return new StampedInvoice(
            uuid: $this->uuid,
            xml: $this->xmlDePrueba(),
            pdf: '%PDF-1.4 de prueba',
        );
    }

    public function cancel(string $uuid, string $rfcEmisor, string $motivo, ?string $sustituye = null): CancelResult
    {
        if ($this->falla !== null) {
            throw new CfdiException($this->falla);
        }

        $this->cancelaciones[] = compact('uuid', 'rfcEmisor', 'motivo', 'sustituye');

        return $this->respuestaCancelacion;
    }

    /**
     * XML mínimo con lo que el sistema le lee después: el emisor (para cancelar
     * con el RFC bueno) y el receptor, el total y el folio fiscal, que son los
     * cuatro datos con los que se le pregunta al SAT por el estado.
     */
    private function xmlDePrueba(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Total="2320.00">'
            .'<cfdi:Emisor Rfc="XAXX010101000" Nombre="EMPRESA DEMO SA DE CV"/>'
            .'<cfdi:Receptor Rfc="AAA010101AAA" Nombre="Cliente Uno"/>'
            .'<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="'.$this->uuid.'"/></cfdi:Complemento>'
            .'</cfdi:Comprobante>';
    }
}
