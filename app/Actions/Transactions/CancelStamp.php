<?php

namespace App\Actions\Transactions;

use App\Models\CfdiCancelacion;
use App\Models\Core\Transaction;
use App\Support\Cfdi\CancelResult;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\PacClient;
use App\Support\TransactionFiles;

/**
 * Solicita ante el SAT la cancelación de una factura ya timbrada.
 *
 * ⚠️ **Pedir la cancelación no es cancelar.** Salvo los comprobantes que el SAT
 * deja cancelar sin aceptación, lo que devuelve el PAC es un acuse y la factura
 * sigue vigente hasta que el receptor autorice o se le venza el plazo. La
 * solicitud queda anotada en `cfdi_cancelacion` y `transaction.cancelled` solo
 * lo marca la consulta al SAT (`RefreshCancellationStatus`), que se hace aquí
 * mismo al terminar y a diario con `cfdi:revisar-cancelaciones`.
 *
 * ⚠️ El RFC que se le manda al PAC tiene que ser **aquel con el que se timbró**,
 * no el de la cuenta ni el de la compañía actual: el PAC busca el UUID dentro de
 * la base de ese emisor y, si recibe otro, responde «UUID no localizado en la
 * base de timbrados». La fuente de verdad es el XML guardado.
 */
class CancelStamp
{
    /** Motivos de cancelación del SAT. */
    public const MOTIVOS = [
        '01' => '01 · Comprobante emitido con errores, con relación',
        '02' => '02 · Comprobante emitido con errores, sin relación',
        '03' => '03 · No se llevó a cabo la operación',
        '04' => '04 · Operación nominativa relacionada en una factura global',
    ];

    public function __construct(private PacClient $pac, private RefreshCancellationStatus $consultarSat) {}

    public function handle(Transaction $transaccion, string $motivo, ?string $sustituye = null): CancelResult
    {
        if (blank($transaccion->seal)) {
            throw new CfdiException('Esta factura no está timbrada, no hay nada que cancelar.');
        }

        if (! array_key_exists($motivo, self::MOTIVOS)) {
            throw new CfdiException('El motivo de cancelación no es válido.');
        }

        if ($motivo === '01' && blank($sustituye)) {
            throw new CfdiException('El motivo 01 exige el folio fiscal del comprobante que lo sustituye.');
        }

        // El SAT solo admite folio de sustitución con el motivo 01; con los demás
        // se descarta aunque venga capturado, para no mandárselo al PAC.
        if ($motivo !== '01') {
            $sustituye = null;
        }

        $resultado = $this->pac->cancel($transaccion->seal, $this->emisorRfc($transaccion), $motivo, $sustituye);

        CfdiCancelacion::updateOrCreate(['transc_id' => $transaccion->transc_id], [
            'uuid' => $transaccion->seal,
            'motivo' => $motivo,
            'sustituye' => $sustituye,
            'estado' => $resultado->estado,
            'codigo' => $resultado->codigo,
            'mensaje' => $resultado->mensaje,
            'solicitado_por' => auth()->id(),
            'solicitado_at' => now(),
            // Una solicitud nueva deja sin valor lo último que dijo el SAT.
            'sat_estado' => null,
            'sat_estatus' => null,
            'verificado_at' => null,
        ]);

        // Las que no piden aceptación el SAT las cancela al momento; las demás
        // seguirán vigentes hasta que conteste el receptor.
        if ($this->consultarSat->handle($transaccion)->estaCancelado()) {
            return new CancelResult(CancelResult::CANCELADA, $resultado->codigo, 'El SAT canceló el comprobante.');
        }

        return $resultado;
    }

    /**
     * RFC con el que realmente se timbró, leído del XML guardado.
     *
     * Si el XML no está, se cae a la compañía emisora de la transacción; es lo
     * más cercano y avisa claro cuando no coincide.
     */
    private function emisorRfc(Transaction $transaccion): string
    {
        $ruta = app(TransactionFiles::class)->path($transaccion, 'xml');

        if ($ruta !== null) {
            $documento = @simplexml_load_string((string) file_get_contents($ruta));

            if ($documento !== false) {
                $emisor = $documento->xpath('//*[local-name()="Emisor"]');

                if (isset($emisor[0]['Rfc'])) {
                    return (string) $emisor[0]['Rfc'];
                }
            }
        }

        $rfc = $transaccion->company?->rfc;

        if (blank($rfc)) {
            throw new CfdiException(
                'No se encontró el RFC con el que se timbró: sin el XML ni compañía emisora, el PAC no puede localizar el folio.'
            );
        }

        return $rfc;
    }
}
