<?php

namespace App\Actions\Transactions;

use App\Models\Core\Charge;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\CfdiLayout;
use App\Support\Cfdi\PacClient;
use App\Support\TransactionFiles;
use Illuminate\Support\Facades\Storage;

/**
 * Timbra una factura ante el PAC y guarda el comprobante.
 *
 * Porta `Transaction::timbrar()` de Yii2. El orden importa: primero se arma el
 * layout, luego se timbra, y **solo si el PAC contesta** se guardan los archivos
 * y se marca la transacción. Si algo falla, la factura queda como estaba.
 */
class StampTransaction
{
    public function __construct(
        private PacClient $pac,
        private TransactionFiles $archivos,
        private SendInvoice $correo,
    ) {}

    /**
     * Cómo le fue al correo del último timbrado (una de las constantes de
     * `SendInvoice`), para que la pantalla lo pueda decir. Va aparte del valor de
     * retorno porque el timbrado vale aunque el correo falle.
     */
    public ?string $mailStatus = null;

    /**
     * ⚠️ La comprobación va también aquí y no solo en la pantalla: es la única
     * que protege si alguien llama la acción desde una consola, un trabajo en
     * cola o una pantalla futura que se olvide de preguntar.
     */
    public function handle(Transaction $transaccion): string
    {
        if (! config('timbrado.habilitado')) {
            throw new CfdiException(__('Esta instalación no factura con CFDI.'));
        }

        $this->assertStampable($transaccion);

        $comprobante = $this->pac->stamp($this->layoutFor($transaccion));

        // Los archivos se nombran con el folio fiscal, igual que el original, y
        // van a la misma carpeta que ya lee el sistema viejo.
        $carpeta = $this->archivos->directory($transaccion->transc_id);
        $disco = Storage::disk('documentos');

        $disco->put("{$carpeta}/{$comprobante->uuid}.xml", $comprobante->xml);

        if ($comprobante->pdf !== null) {
            $disco->put("{$carpeta}/{$comprobante->uuid}.pdf", $comprobante->pdf);
        }

        $transaccion->forceFill([
            'seal' => $comprobante->uuid,
            'xml_attach' => $comprobante->uuid.'.xml',
            'pdf_attach' => $comprobante->uuid.'.pdf',
        ])->save();

        // Timbrar y avisarle al cliente son un solo acto en el original: en
        // cuanto el PAC responde bien, le sale su factura por correo.
        $this->mailStatus = $this->correo->handle(
            $transaccion,
            (string) ($transaccion->bookingModel?->booking_number ?? ''),
        );

        return $comprobante->uuid;
    }

    private function assertStampable(Transaction $transaccion): void
    {
        if ((int) $transaccion->tran_type !== Transaction::TYPE_INVOICE) {
            throw new CfdiException('Solo se timbran facturas al cliente, no costos de proveedor.');
        }

        if (filled($transaccion->seal)) {
            throw new CfdiException('Esta factura ya está timbrada.');
        }

        if ((int) $transaccion->invoice_type === Transaction::INVOICE_TYPE_HISTORY) {
            throw new CfdiException('Las facturas históricas no se timbran: su sello se captura a mano.');
        }

        // Multiemisor: sin compañía, o con una sin datos fiscales completos, el
        // CFDI saldría a nombre equivocado. El original abortaba igual aquí.
        $emisorError = $transaccion->emisorError();

        if ($emisorError !== null) {
            throw new CfdiException($emisorError);
        }

        if ($transaccion->client === null) {
            throw new CfdiException('La factura no tiene cliente al cual emitirla.');
        }

        if (blank($transaccion->client->rfc)) {
            throw new CfdiException('El cliente no tiene RFC capturado.');
        }
    }

    private function layoutFor(Transaction $transaccion): string
    {
        // El encabezado necesita las columnas calculadas del motor (tipo de
        // cambio del día y número de booking), no solo las de la tabla.
        $fila = TransactionQuery::make(
            TransactionFilters::make(['tran_in' => [$transaccion->transc_id], 'showCancelled' => 1])
        )->get(1)->first();

        if ($fila === null) {
            throw new CfdiException('No se pudo calcular la factura para timbrarla.');
        }

        // `assertStampable` ya garantizó compañía con datos fiscales completos.
        $emisor = $transaccion->company;

        if ($emisor === null) {
            throw new CfdiException('La factura no tiene compañía emisora.');
        }

        return (new CfdiLayout(
            transaccion: $transaccion,
            emisor: $emisor,
            receptor: $transaccion->client,
            conceptos: Charge::with('chargeType')
                ->where('transaction', $transaccion->transc_id)
                ->orderBy('charge_id')
                ->get(),
            tipoCambio: $fila->exchange_value === null ? null : (float) $fila->exchange_value,
            numeroBooking: $fila->booking_number,
        ))->build();
    }
}
