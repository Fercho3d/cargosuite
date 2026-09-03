<?php

namespace App\Actions\Transactions;

use App\Models\Core\Transaction;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\PacClient;
use App\Support\TransactionFiles;

/**
 * Cancela ante el SAT una factura ya timbrada.
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

    public function __construct(private PacClient $pac) {}

    public function handle(Transaction $transaccion, string $motivo, ?string $sustituye = null): void
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

        $this->pac->cancel($transaccion->seal, $this->emisorRfc($transaccion), $motivo, $sustituye);

        $transaccion->forceFill([
            'cancelled' => 1,
            'cancel_reason_id' => $motivo,
            'new_seal' => $sustituye,
        ])->save();
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
