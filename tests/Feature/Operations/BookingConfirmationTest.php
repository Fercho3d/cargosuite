<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Livewire\Operations\BookingForm;
use App\Mail\BookingConfirmationMail;
use App\Models\Core\Booking;
use App\Models\User;
use App\Support\Pdf\BookingConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * La confirmación del booking: el PDF y el correo con el que se le avisa al
 * cliente que su embarque quedó en firme.
 */
class BookingConfirmationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        Mail::fake();

        DB::table('client')->insert([[
            'client_id' => 1, 'fullName' => 'Frialsa Frigoríficos', 'email' => 'contacto@frialsa.mx',
            'email_notification' => 'trafico@frialsa.mx, logistica@frialsa.mx',
            'address' => 'Av. Central 100', 'city' => 'Guadalajara', 'state' => 'Jalisco',
            'postal_code' => '44100', 'country' => 'México',
        ]]);
        DB::table('provider')->insert([['provider_id' => 100, 'fullName' => 'Hapag-Lloyd', 'type_id' => 1]]);
        DB::table('vessel')->insert([['vessel_id' => 4, 'vessel_name' => 'MSC Anna']]);
        DB::table('loading_ports')->insert([['port_id' => 10, 'port_name' => 'Altamira', 'deleted' => 0]]);
        DB::table('dicharge_port')->insert([['dicharge_port_id' => 20, 'name' => 'Rotterdam', 'deleted' => 0]]);
        DB::table('pickup_place')->insert([['pick_id' => 30, 'name' => 'Monterrey']]);
        DB::table('final_destination')->insert([['final_destination_id' => 40, 'name' => 'Hamburgo', 'deleted' => 0]]);
        DB::table('container_types')->insert([['contType_id' => 1, 'container_name' => '40 RF']]);
    }

    private function booking(array $cambios = []): Booking
    {
        DB::table('booking')->insert([array_merge([
            'booking_id' => 1, 'booking_number' => 'FRE-2026-0184', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0, 'vessel' => 4, 'loading_port' => 10,
            'dicharge_port_id' => 20, 'pick_up_place_id' => 30, 'final_destination_id' => 40,
            'carrier_id' => 100, 'loading_EDT' => '2026-09-01', 'dicharge_ETA' => '2026-09-20',
            'remarks' => 'Temperatura -18°C', 'created_at' => '2026-08-20 10:00:00',
        ], $cambios)]);

        DB::table('containers')->insert([
            ['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 2,
                'number' => 'FFAU6079411', 'seal' => '78460', 'comodity' => 'Aguacate'],
            ['container_ID' => 2, 'booking' => 1, 'container_type' => 1, 'quantity' => 3,
                'number' => 'TGHU1234567', 'seal' => '99120', 'comodity' => 'Aguacate'],
        ]);

        return Booking::findOrFail(1);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    // ------------------------------------------------------------ Documento

    public function test_el_documento_lleva_la_ruta_la_carga_y_el_total_de_piezas(): void
    {
        $html = app(BookingConfirmation::class)->html($this->booking());

        $this->assertStringContainsString('Booking confirmation', $html);
        $this->assertStringContainsString('FRE-2026-0184', $html);
        $this->assertStringContainsString('Frialsa Frigoríficos', $html);
        $this->assertStringContainsString('Hapag-Lloyd', $html);
        $this->assertStringContainsString('Altamira', $html);
        $this->assertStringContainsString('Rotterdam', $html);
        $this->assertStringContainsString('Hamburgo', $html);
        $this->assertStringContainsString('FFAU6079411', $html);
        // Dos contenedores de 2 y 3: cinco piezas.
        $this->assertStringContainsString('<td>5</td>', $html);
    }

    public function test_una_cotizacion_se_titula_distinto_y_enseña_lo_que_se_facturaria(): void
    {
        $booking = $this->booking(['mode' => Booking::MODE_QUOTATION]);

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]]);
        DB::table('charge_type')->insert([['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0]]);
        DB::table('transaction')->insert([[
            'transc_id' => 5, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-1', 'tran_date' => '2026-08-20', 'invoice_type' => 1,
        ]]);
        DB::table('charge')->insert([[
            'charge_id' => 1, 'transaction' => 5, 'type' => 1, 'quantity' => 1, 'price' => 1000,
        ]]);
        DB::table('exchange')->insert([[
            'exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-08-20', 'account' => 1,
        ]]);

        $html = app(BookingConfirmation::class)->html($booking);

        $this->assertStringContainsString('Booking Quotation', $html);
        // Rareza conservada del original: el total arranca con las piezas (5),
        // porque reutiliza la variable que venía sumando los contenedores.
        $this->assertStringContainsString('$ 1,005.00', $html);
    }

    /** Una fecha capturada sin hora salía como «12:00:00 AM»; con hora, se imprime como el original. */
    public function test_las_fechas_llevan_hora_solo_cuando_la_tienen(): void
    {
        $booking = $this->booking();
        DB::table('booking_continuity')->insert([
            'cont_id' => 1, 'booking' => 1, 'pickup_date' => '2026-08-25 00:00:00', 'SI_date' => '2026-08-28 14:30:00',
        ]);

        $html = app(BookingConfirmation::class)->html($booking);

        $this->assertStringContainsString('<td>25/08/2026</td>', $html);
        $this->assertStringContainsString('<td>28/08/2026 02:30:00 PM</td>', $html);
        $this->assertStringNotContainsString('12:00:00 AM', $html);
    }

    public function test_el_pdf_se_sirve_en_linea(): void
    {
        $this->booking();

        $respuesta = $this->actingAs($this->usuario())->get('/operacion/bookings/1/confirmacion.pdf');

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    /** Como el `pdf` del original: para cualquier usuario interno. */
    public function test_cualquier_usuario_interno_baja_el_pdf(): void
    {
        $this->booking();

        $this->actingAs($this->usuario(User::ROLE_USER))
            ->get('/operacion/bookings/1/confirmacion.pdf')
            ->assertOk();
    }

    // --------------------------------------------------------------- Correo

    /**
     * El alta ya no avisa: nace como borrador sin contenedores, y un PDF sin
     * carga no le sirve al cliente. El correo sale al confirmar, como el
     * «Confirm & Save» del original.
     */
    public function test_dar_de_alta_un_booking_no_avisa_todavia(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(BookingForm::class)
            ->set('bookingNumber', 'FRE-2026-0200')
            ->set('clientId', '1')
            ->set('vesselId', '4')
            ->set('carrierId', '100')
            ->set('loadingPort', '10')
            ->set('loadingDate', '2026-09-01')
            ->set('dischargePort', '20')
            ->set('arrivalDate', '2026-09-20')
            ->set('pickupPlace', '30')
            ->call('save')
            ->assertHasNoErrors();

        Mail::assertNothingSent();
    }

    public function test_confirmar_el_borrador_le_avisa_al_cliente(): void
    {
        $this->booking(['is_draft' => 1]);

        Livewire::actingAs($this->usuario())
            ->test(BookingDetail::class, ['booking' => 1])
            ->assertSee('Este booking es un borrador.')
            ->call('confirm');

        $this->assertSame(0, (int) DB::table('booking')->where('booking_id', 1)->value('is_draft'));
        Mail::assertSent(BookingConfirmationMail::class, fn ($correo) => $correo->hasTo('trafico@frialsa.mx')
            && $correo->hasTo('logistica@frialsa.mx')
            && $correo->envelope()->subject === 'Booking [FRE-2026-0184]');
    }

    public function test_confirmar_una_cotizacion_no_manda_correo(): void
    {
        $this->booking(['is_draft' => 1, 'mode' => 9]);

        Livewire::actingAs($this->usuario())
            ->test(BookingDetail::class, ['booking' => 1])
            ->call('confirm');

        $this->assertSame(0, (int) DB::table('booking')->where('booking_id', 1)->value('is_draft'));
        Mail::assertNothingSent();
    }

    /** Confirmar vivía en `update`, que era de administradores. */
    public function test_solo_un_administrador_confirma(): void
    {
        $this->booking(['is_draft' => 1]);

        Livewire::actingAs($this->usuario(User::ROLE_USER))
            ->test(BookingDetail::class, ['booking' => 1])
            ->call('confirm')
            ->assertForbidden();

        $this->assertSame(1, (int) DB::table('booking')->where('booking_id', 1)->value('is_draft'));
    }

    public function test_sin_correos_de_notificacion_no_se_manda_nada(): void
    {
        DB::table('client')->where('client_id', 1)->update(['email' => '', 'email_notification' => null]);
        $this->booking(['is_draft' => 1]);

        Livewire::actingAs($this->usuario())
            ->test(BookingDetail::class, ['booking' => 1])
            ->call('confirm');

        $this->assertSame(0, (int) DB::table('booking')->where('booking_id', 1)->value('is_draft'));
        Mail::assertNothingSent();
    }

    public function test_se_puede_volver_a_mandar_desde_el_detalle(): void
    {
        $this->booking();

        Livewire::actingAs($this->usuario())
            ->test(BookingDetail::class, ['booking' => 1])
            ->call('sendConfirmation');

        Mail::assertSent(BookingConfirmationMail::class);
    }

    /** «Enviarme una copia»: la misma confirmación, al correo de quien la pide y a nadie más. */
    public function test_enviarme_una_copia_va_a_mi_correo_y_no_al_cliente(): void
    {
        $this->booking();
        $usuario = $this->usuario(User::ROLE_USER);
        $usuario->forceFill(['email' => 'ana@frego.mx'])->save();

        Livewire::actingAs($usuario)
            ->test(BookingDetail::class, ['booking' => 1])
            ->call('sendConfirmationToMe');

        Mail::assertSent(BookingConfirmationMail::class, fn ($correo) => $correo->hasTo('ana@frego.mx')
            && ! $correo->hasTo('trafico@frialsa.mx'));
    }

    public function test_sin_correo_en_el_usuario_no_hay_copia_que_mandar(): void
    {
        $this->booking();

        Livewire::actingAs($this->usuario())
            ->test(BookingDetail::class, ['booking' => 1])
            ->call('sendConfirmationToMe');

        Mail::assertNothingSent();
    }

    public function test_solo_un_administrador_lo_vuelve_a_mandar(): void
    {
        $this->booking();

        Livewire::actingAs($this->usuario(User::ROLE_USER))
            ->test(BookingDetail::class, ['booking' => 1])
            ->call('sendConfirmation')
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
