<?php

namespace App\Support\Pdf;

use App\Models\Core\Bank;
use App\Models\Core\PaymentRequest;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\Documentos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use NumberFormatter;

/**
 * La solicitud de pago (o de cobro) impresa: a quién, cuánto —con letra— y los
 * documentos que cubre, con su desglose de impuestos y espacio para firmas.
 *
 * Diseño propio de CargoSuite: ya no copia el «cheque» del sistema anterior. El
 * importe con letra sale en el idioma de los documentos (`Documentos`).
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
        $esCobro = (int) $solicitud->type === 1;
        // Yii2 solo leía el proveedor y el cobro a cliente (tipo 1) salía en blanco.
        $tercero = $esCobro ? $solicitud->client : $solicitud->provider;
        // La divisa sale de la primera factura del grupo, como en el original.
        $divisa = (string) ($transacciones->first()->currency ?? '');
        $banco = Bank::find($solicitud->bank_id);
        $suma = fn (string $columna) => (float) $transacciones->sum(fn ($t) => (float) $t->{$columna});

        return view('pdf.payment-request', [
            'solicitud' => $solicitud,
            'esCobro' => $esCobro,
            'fecha' => $solicitud->date ? Carbon::parse($solicitud->date)->format('d/m/Y') : '',
            'beneficiario' => $tercero?->fullName ?? '',
            'rfc' => $tercero?->rfc ?? '',
            'importe' => number_format($importe, 2),
            'importeEnLetra' => $this->spellOut($importe, $divisa),
            'divisa' => $divisa,
            'banco' => $banco?->bank_name ?? '',
            'cuentaBancaria' => $banco?->account_number ?? '',
            'elaboro' => ($autor = DB::table('users')->where('usr_id', $solicitud->created_by)->first(['name', 'username']))
                ? ($autor->name ?: $autor->username) : '',
            'transacciones' => $transacciones,
            'totales' => collect(['sub_0_paid', 'sub_16_paid', 'tax_16_mxn', 'tax_ret_mxn', 'non_dec', 'tran_paid_amount'])
                ->mapWithKeys(fn ($c) => [$c => $suma($c)])->all(),
            'logo' => $this->logo(),
            'color' => (string) (config('marca.colores.acento_600') ?: '#0f766e'),
        ])->render();
    }

    /**
     * El importe con letra, en mayúsculas y con los centavos en «/100», como se
     * escribe en un documento de pago: «MIL DOSCIENTOS TREINTA Y CUATRO PESOS
     * 56/100 M.N.».
     */
    private function spellOut(float $importe, string $divisa): string
    {
        $idioma = app()->getLocale() === 'es' ? 'es' : 'en';
        $letras = (new NumberFormatter($idioma, NumberFormatter::SPELLOUT))->format(floor($importe));
        $centavos = str_pad((string) (int) round(($importe - floor($importe)) * 100), 2, '0', STR_PAD_LEFT);

        $moneda = match (true) {
            $divisa === 'MXN' && $idioma === 'es' => 'PESOS '.$centavos.'/100 M.N.',
            $divisa === 'USD' && $idioma === 'es' => 'DÓLARES '.$centavos.'/100 USD',
            $divisa === 'MXN' => 'MEXICAN PESOS '.$centavos.'/100',
            $divisa === 'USD' => 'US DOLLARS '.$centavos.'/100',
            default => $centavos.'/100 '.$divisa,
        };

        return mb_strtoupper($letras).' '.$moneda;
    }

    /** Ruta local del logotipo (mPDF la lee del disco), o null si la marca es solo texto. */
    private function logo(): ?string
    {
        $ruta = trim((string) config('marca.logo.imagen.claro'));

        if ($ruta === '' || str_contains($ruta, '//')) {
            return null;
        }

        $local = public_path(ltrim($ruta, '/'));

        return is_file($local) ? $local : null;
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
        return __('impresos.payment_request_title').' '.$solicitud->number;
    }

    private function css(): string
    {
        return (string) file_get_contents(resource_path('views/pdf/payment-request.css'));
    }
}
