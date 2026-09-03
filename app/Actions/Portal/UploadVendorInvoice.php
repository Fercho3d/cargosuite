<?php

namespace App\Actions\Portal;

use App\Models\Core\Transaction;
use App\Support\TransactionFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * El proveedor sube su factura contra un costo que la empresa le registró.
 *
 * Es la razón de ser del portal viejo (`TransactionController::actionUpdate`
 * con el escenario `bill`): el proveedor NO captura importes —esos los pone
 * la empresa— sino que adjunta su comprobante y anota con qué número y fecha lo
 * emitió.
 *
 * Lo que el escenario `bill` del original deja tocar, y nada más:
 * `tran_number`, `tran_date`, `pdf_attach` y `xml_attach`.
 */
class UploadVendorInvoice
{
    public function __construct(private TransactionFiles $archivos) {}

    /**
     * @param  array{tran_number: string, tran_date: string}  $datos
     */
    public function handle(
        Transaction $transaccion,
        int $proveedorId,
        array $datos,
        ?UploadedFile $pdf,
        ?UploadedFile $xml,
    ): void {
        $this->assertOwnership($transaccion, $proveedorId);
        $this->assertEditable($transaccion);

        // `pdf_attach` es obligatorio en el escenario `bill` del original: sin
        // comprobante, el documento no sirve de nada.
        if ($pdf === null && blank($transaccion->pdf_attach)) {
            throw ValidationException::withMessages(['pdf' => __('Hace falta la factura en PDF.')]);
        }

        $transaccion->tran_number = $datos['tran_number'];
        $transaccion->tran_date = $datos['tran_date'];

        if ($pdf !== null) {
            $transaccion->pdf_attach = $this->archivos->store($transaccion, $pdf);
        }

        if ($xml !== null) {
            $transaccion->xml_attach = $this->archivos->store($transaccion, $xml);
        }

        $transaccion->save();
    }

    /**
     * El costo tiene que ser de ESTE proveedor.
     *
     * Se responde 404 y no 403: un 403 confirmaría que el documento existe.
     */
    private function assertOwnership(Transaction $transaccion, int $proveedorId): void
    {
        abort_unless((int) $transaccion->vendor === $proveedorId, 404);
    }

    /**
     * Un documento ya pagado, cancelado o metido en una solicitud de pago deja
     * de ser del proveedor: cambiarle el número o la fecha movería papeles que
     * la empresa ya dio por buenos.
     */
    private function assertEditable(Transaction $transaccion): void
    {
        if ((int) $transaccion->cancelled === 1) {
            throw ValidationException::withMessages(['pdf' => __('El documento está cancelado.')]);
        }

        if ((int) $transaccion->payment_request === 1) {
            throw ValidationException::withMessages(['pdf' => __('El documento ya está en una solicitud de pago.')]);
        }
    }
}
