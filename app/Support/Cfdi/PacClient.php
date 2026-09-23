<?php

namespace App\Support\Cfdi;

/**
 * El proveedor autorizado de certificación (PAC) que timbra y cancela.
 *
 * Es una interfaz para poder sustituirlo en pruebas: **nada de lo que se prueba
 * debe salir a la red**, y un timbrado de prueba contra el PAC real consume
 * folios.
 */
interface PacClient
{
    /**
     * Timbra el layout y devuelve el comprobante.
     *
     * @throws CfdiException si el PAC rechaza o no contesta.
     */
    public function stamp(string $layout): StampedInvoice;

    /**
     * Pide la cancelación de un comprobante ya timbrado.
     *
     * Devuelve **en qué quedó la solicitud**, que casi nunca es «cancelada»:
     * mientras el PAC no confirme la cancelación el comprobante sigue vigente
     * ante el SAT. Quien llama tiene que mirar el resultado.
     *
     * @param  string  $rfcEmisor  El RFC con el que se timbró: el PAC busca el UUID
     *                             dentro de la base de ESE emisor.
     * @param  string  $motivo  Clave de cancelación del SAT (01–04).
     * @param  string|null  $sustituye  UUID que sustituye a este, si el motivo es 01.
     *
     * @throws CfdiException si el PAC rechaza la solicitud o no contesta.
     */
    public function cancel(string $uuid, string $rfcEmisor, string $motivo, ?string $sustituye = null): CancelResult;
}
