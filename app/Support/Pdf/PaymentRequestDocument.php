<?php

namespace App\Support\Pdf;

use App\Models\Core\Bank;
use App\Models\Core\PaymentRequest;
use App\Models\Core\Provider;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\Documentos;
use Illuminate\Support\Carbon;
use NumberFormatter;

/**
 * La solicitud de pago impresa: el cheque con el importe en letra y las facturas
 * que cubre.
 *
 * Porta `PaymentRequest::generateDocument()` de Yii2. El importe en letra sale
 * **en inglés** porque el original usa el formateador de Yii con el idioma de la
 * aplicación, que es `en-US`; se conserva para no cambiar un documento que se
 * imprime sobre formato preimpreso.
 */
class PaymentRequestDocument
{
    public function __construct(private PdfWriter $pdf) {}

    public function pdf(PaymentRequest $solicitud): string
    {
        return $this->pdf->render($this->html($solicitud), $this->css(), $this->title($solicitud));
    }

    public function fileName(PaymentRequest $solicitud): string
    {
        return 'request_'.str_pad((string) $solicitud->request_id, 3, '0', STR_PAD_LEFT);
    }

    public function html(PaymentRequest $solicitud): string
    {
        return Documentos::conIdioma(fn () => $this->arma($solicitud));
    }

    private function arma(PaymentRequest $solicitud): string
    {
        $transacciones = $this->transactions($solicitud);
        $importe = (float) $solicitud->amount;

        return view('pdf.payment-request', [
            'solicitud' => $solicitud,
            'fecha' => $solicitud->date ? Carbon::parse($solicitud->date)->format('d/m/Y') : '',
            'beneficiario' => Provider::find($solicitud->provider_id)?->fullName ?? '',
            'importe' => number_format($importe, 2),
            'importeEnLetra' => $this->spellOut($importe),
            'centavos' => number_format($importe - floor($importe), 2),
            // La divisa sale de la primera factura del grupo, como en el original.
            'divisa' => $transacciones->first()->currency ?? '',
            'cuentaBancaria' => Bank::find($solicitud->bank_id)?->account_number ?? '',
            'transacciones' => $transacciones,
            'total' => (float) $transacciones->sum('tran_paid_amount'),
        ])->render();
    }

    /**
     * El importe con letra, rellenado con guiones hasta 110 caracteres para que
     * nadie pueda escribir de más en el cheque. Igual que `getAmountText()`.
     */
    private function spellOut(float $importe): string
    {
        $letras = (new NumberFormatter('en-US', NumberFormatter::SPELLOUT))->format(floor($importe));

        return str_pad($letras.' ', 110, '-', STR_PAD_RIGHT).' ';
    }

    /**
     * Las facturas de la solicitud, con los mismos modos que pedía el original:
     * importes en su divisa (`noExchange`) y en modo pago, que es el que trae lo
     * efectivamente aplicado a cada documento.
     */
    private function transactions(PaymentRequest $solicitud)
    {
        return TransactionQuery::make(TransactionFilters::make([
            'request_id' => $solicitud->request_id,
            'noExchange' => true,
            'noNegative' => false,
            'paymentMode' => true,
        ]))->get();
    }

    private function title(PaymentRequest $solicitud): string
    {
        return 'Payment Request'.str_pad((string) $solicitud->number, 3, '0', STR_PAD_LEFT);
    }

    private function css(): string
    {
        return (string) file_get_contents(resource_path('views/pdf/payment-request.css'));
    }
}
