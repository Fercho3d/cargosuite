<?php

namespace Tests\Feature\Operations;

use App\Models\Core\Booking;
use App\Support\Pdf\BookingConfirmation;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Paridad de la confirmación de booking contra el documento real de Yii2.
 *
 * El fixture trae el HTML que produce `Booking::generateBokingConfirmation()`
 * para doce bookings de la base local —diez normales y dos cotizaciones— y aquí
 * se compara con el que arma la plantilla nueva.
 *
 * Se compara el HTML y no el PDF a propósito: el PDF lo pinta el mismo motor
 * (mPDF, con los mismos márgenes y formato), así que si el HTML y el CSS son los
 * mismos, la hoja impresa también.
 *
 * Para regenerar el fixture:
 *   php tools/export_legacy_booking_pdf.php
 */
#[Group('parity')]
class BookingConfirmationParityTest extends LegacyDatabaseTestCase
{
    /**
     * La ficha de la empresa ya no está escrita en la plantilla sino en
     * `config/marca.php`, y por omisión trae la de la demostración. La paridad
     * se comprueba contra el documento que produce la instalación de Frego, así
     * que aquí se fija la suya: la del fixture.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marca.empresa.nombre' => 'Freight Global Operator',
            'marca.empresa.domicilio' => 'Av Mariano Otero 2347-112 Col. Verde Valle',
            'marca.empresa.rfc' => 'FTM1507038V6',
            'marca.empresa.telefono' => '+5233130061992',
        ]);
    }

    public function test_el_documento_es_el_mismo_que_produce_el_sistema_viejo(): void
    {
        $documento = app(BookingConfirmation::class);
        $comparados = 0;

        foreach ($this->fixture() as $caso) {
            $booking = Booking::find($caso['booking_id']);

            if ($booking === null) {
                continue;
            }

            $this->assertSame(
                $this->cells($caso['html']),
                $this->cells($documento->html($booking)),
                'Booking '.$caso['booking_id'].' (mode '.$caso['mode'].')',
            );

            $comparados++;
        }

        $this->assertGreaterThan(8, $comparados, 'El fixture quedó vacío: corre tools/export_legacy_booking_pdf.php.');
    }

    public function test_la_hoja_de_estilo_es_la_misma(): void
    {
        $css = $this->fixture()[0]['css'] ?? '';

        $this->assertSame(
            $this->normalize($css),
            $this->normalize(file_get_contents(resource_path('views/pdf/booking.css'))),
        );
    }

    /**
     * El contenido del documento: cada título y cada celda, en orden.
     *
     * Se compara esto y no el marcado carácter por carácter porque el original
     * escribe el HTML a mano y trae espacios sueltos dentro de las etiquetas
     * (`<td class="backcolor" >`) y un par de `<tr>` anidados de más que el
     * motor ignora. Nada de eso se ve en la hoja impresa; lo que sí se vería es
     * un dato distinto, uno de menos o uno en otro lugar, y eso es justo lo que
     * esta lista detecta.
     *
     * @return list<string>
     */
    private function cells(string $html): array
    {
        $dom = new DOMDocument;
        $anteriores = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?><body>'.$html.'</body>', LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($anteriores);

        $celdas = [];

        foreach ((new DOMXPath($dom))->query('//h1|//td|//th|//div[@class="address"]') as $nodo) {
            $celdas[] = $this->normalize($nodo->textContent);
        }

        return $celdas;
    }

    /** Colapsa los espacios: el original los reparte a su antojo. */
    private function normalize(string $texto): string
    {
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('#/\*.*?\*/#s', '', $texto) ?? '';
        $texto = preg_replace('/>\s+</', '><', $texto) ?? '';
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? '';

        return trim($texto);
    }

    /** @return array<int, array<string, mixed>> */
    private function fixture(): array
    {
        $ruta = base_path('tests/Fixtures/legacy-booking-confirmation.json');

        if (! file_exists($ruta)) {
            $this->markTestSkipped('Falta el fixture: corre tools/export_legacy_booking_pdf.php');
        }

        return json_decode((string) file_get_contents($ruta), true);
    }
}
