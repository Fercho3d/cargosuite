<?php

namespace App\Support\Pdf;

use App\Support\Cotizaciones\Cotizaciones;
use App\Support\Documentos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La cotización impresa: a quién, qué ruta, los conceptos con sus impuestos,
 * la vigencia y las condiciones. Mismo diseño que la solicitud de pago.
 *
 * El margen NO sale aquí: es interno.
 */
class QuoteDocument
{
    public function __construct(private PdfWriter $pdf) {}

    public function pdf(int $cotizacion): string
    {
        return $this->pdf->render($this->html($cotizacion), $this->css(), $this->fileName($cotizacion));
    }

    /** Deja el PDF en disco para adjuntarlo al correo y devuelve la ruta. */
    public function save(int $cotizacion): string
    {
        $carpeta = storage_path('app/mpdf');

        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        return $this->pdf->save($this->html($cotizacion), $carpeta.'/'.$this->fileName($cotizacion).'.pdf', $this->css());
    }

    public function fileName(int $cotizacion): string
    {
        return 'cotizacion_'.DB::table('cotizacion')->where('cotizacion_id', $cotizacion)->value('numero');
    }

    public function html(int $cotizacion): string
    {
        return Documentos::conIdioma(function () use ($cotizacion) {
            $c = DB::table('cotizacion')->where('cotizacion_id', $cotizacion)->first() ?? abort(404);
            $ruta = DB::table('ruta as r')
                ->join('loading_ports as o', 'o.port_id', '=', 'r.origen_id')
                ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'r.destino_id')
                ->where('r.ruta_id', $c->ruta_id)
                ->first(['r.km', 'r.horas', 'o.port_name as origen', 'd.name as destino']);
            $renglones = Cotizaciones::renglones($cotizacion);
            $cliente = $c->client_id ? DB::table('client')->where('client_id', $c->client_id)->first(['fullName', 'rfc']) : null;

            return view('pdf.cotizacion', [
                'c' => $c,
                'fecha' => Carbon::parse($c->enviada_en ?? $c->created_at ?? now())->format('d/m/Y'),
                'vigencia' => Carbon::parse($c->vigencia)->format('d/m/Y'),
                'fechaCarga' => $c->fecha_carga ? Carbon::parse($c->fecha_carga)->format('d/m/Y') : null,
                'destinatario' => Cotizaciones::destinatario($c),
                'rfc' => $cliente->rfc ?? null,
                'ruta' => $ruta,
                'renglones' => $renglones,
                'totales' => Cotizaciones::totales($renglones),
                'elaboro' => ($autor = DB::table('users')->where('usr_id', $c->created_by)->first(['name', 'username']))
                    ? ($autor->name ?: $autor->username) : '',
                'color' => (string) (config('marca.colores.acento_600') ?: '#0f766e'),
            ])->render();
        });
    }

    private function css(): string
    {
        return (string) file_get_contents(resource_path('views/pdf/payment-request.css'));
    }
}
