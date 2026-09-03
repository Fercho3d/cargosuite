<?php

namespace Tests\Support;

use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\PacClient;
use App\Support\Cfdi\StampedInvoice;

/**
 * PAC de mentiras para las pruebas.
 *
 * Guarda lo que se le mandó para poder afirmar sobre el layout, y nunca sale a
 * la red. Con `$falla` se simula un rechazo del PAC.
 */
class FakePacClient implements PacClient
{
    public ?string $layoutRecibido = null;

    /** @var array<int, array<string, mixed>> */
    public array $cancelaciones = [];

    public function __construct(
        public string $uuid = '11111111-2222-3333-4444-555555555555',
        public ?string $falla = null,
    ) {}

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

    public function cancel(string $uuid, string $rfcEmisor, string $motivo, ?string $sustituye = null): void
    {
        if ($this->falla !== null) {
            throw new CfdiException($this->falla);
        }

        $this->cancelaciones[] = compact('uuid', 'rfcEmisor', 'motivo', 'sustituye');
    }

    /** XML mínimo con el complemento de timbre y el emisor, que es lo que se lee. */
    private function xmlDePrueba(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital">'
            .'<cfdi:Emisor Rfc="XAXX010101000" Nombre="EMPRESA DEMO SA DE CV"/>'
            .'<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="'.$this->uuid.'"/></cfdi:Complemento>'
            .'</cfdi:Comprobante>';
    }
}
