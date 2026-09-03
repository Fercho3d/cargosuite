<?php

namespace App\Support\Pdf;

use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Envoltorio de mPDF con los ajustes que traía el original.
 *
 * El sistema anterior imprime con mPDF desde Yii2 (a través de `kartik-v/yii2-mpdf`), y los
 * documentos están escritos para ese motor: tablas con `float`, anchos en píxeles
 * y hojas de estilo que otro renderizador acomodaría distinto. Se conserva el
 * mismo motor para que salgan iguales, y los mismos ajustes: carta, márgenes de
 * 7 mm y sin encabezado.
 *
 * El CSS va en una escritura aparte, como en el original (`WriteHTML($css, 1)`
 * antes del cuerpo): mPDF necesita verlo antes del HTML para aplicarlo.
 */
class PdfWriter
{
    private const MARGEN = 7;

    public function render(string $html, string $css = '', string $titulo = ''): string
    {
        return $this->build($html, $css, $titulo)->Output('', Destination::STRING_RETURN);
    }

    /** Deja el archivo en disco y devuelve la ruta, para poder adjuntarlo. */
    public function save(string $html, string $ruta, string $css = '', string $titulo = ''): string
    {
        $this->build($html, $css, $titulo)->Output($ruta, Destination::FILE);

        return $ruta;
    }

    private function build(string $html, string $css, string $titulo): Mpdf
    {
        $temporales = storage_path('app/mpdf');

        if (! is_dir($temporales)) {
            mkdir($temporales, 0775, true);
        }

        $mpdf = new Mpdf([
            'format' => 'Letter',
            'orientation' => 'P',
            'margin_top' => self::MARGEN,
            'margin_bottom' => self::MARGEN,
            'margin_left' => self::MARGEN,
            'margin_right' => self::MARGEN,
            'tempDir' => $temporales,
        ]);

        $mpdf->SetTitle($titulo);
        $mpdf->SetHeader('');

        if ($css !== '') {
            $mpdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        }

        $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

        return $mpdf;
    }
}
