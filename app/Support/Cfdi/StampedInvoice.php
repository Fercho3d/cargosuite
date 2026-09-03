<?php

namespace App\Support\Cfdi;

/** Lo que devuelve el PAC cuando timbra: el folio fiscal y los archivos. */
final class StampedInvoice
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $xml,
        public readonly ?string $pdf = null,
    ) {}
}
