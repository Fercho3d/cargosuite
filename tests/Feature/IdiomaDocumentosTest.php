<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Models\Core\Booking;
use App\Support\Documentos;
use App\Support\Pdf\BookingConfirmation;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * El idioma de los documentos que salen de la empresa.
 *
 * Los PDF y los correos estaban **en inglés fijo** porque replican los del
 * sistema anterior. Ahora lo pone la instalación, y va **aparte del idioma de la
 * interfaz** a propósito: el documento lo lee el cliente, no el operador. Un
 * operador trabajando en inglés no debe mandarle una factura en inglés a un
 * cliente mexicano, ni al revés.
 *
 * Por omisión sigue siendo `en`, para que la instalación original emita
 * exactamente lo mismo que emitía; eso lo vigilan las pruebas de paridad.
 */
class IdiomaDocumentosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'created_at' => '2026-05-01 10:00:00',
        ]]);
    }

    private function documento(): string
    {
        return app(BookingConfirmation::class)->html(Booking::findOrFail(1));
    }

    public function test_por_omision_los_documentos_salen_en_ingles(): void
    {
        $this->assertSame('en', config('marca.idioma_documentos'));
        $this->assertStringContainsString('Client Information', $this->documento());
    }

    public function test_una_instalacion_puede_emitirlos_en_espanol(): void
    {
        config(['marca.idioma_documentos' => 'es']);

        $html = $this->documento();

        $this->assertStringContainsString('Datos del cliente', $html);
        $this->assertStringNotContainsString('Client Information', $html);
    }

    /**
     * Lo importante: el idioma del documento **no** lo decide quien lo genera.
     * Un operador con la interfaz en español seguía mandando el PDF en inglés,
     * que es justo lo que se quiere cuando el cliente es extranjero.
     */
    public function test_el_idioma_de_la_interfaz_no_manda_sobre_el_documento(): void
    {
        App::setLocale('es');
        config(['marca.idioma_documentos' => 'en']);

        $this->assertStringContainsString('Client Information', $this->documento());
        $this->assertSame('es', App::getLocale(), 'El idioma de la pantalla se quedó cambiado.');
    }

    /** Y si la plantilla revienta, el idioma de la petición tiene que volver. */
    public function test_el_idioma_se_restaura_aunque_algo_falle(): void
    {
        App::setLocale('es');
        config(['marca.idioma_documentos' => 'en']);

        try {
            Documentos::conIdioma(function (): void {
                throw new \RuntimeException('la plantilla reventó');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame('es', App::getLocale());
    }

    public function test_el_correo_declara_el_idioma_de_los_documentos(): void
    {
        config(['marca.idioma_documentos' => 'en']);
        App::setLocale('es');

        $correo = new BookingConfirmationMail(Booking::findOrFail(1));

        $this->assertSame('en', $correo->locale);
    }
}
