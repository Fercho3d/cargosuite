<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Models\User;
use App\Support\BookingFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Documentos adjuntos de un booking.
 *
 * Lo delicado aquí es el formato heredado: los nombres de archivo viven en una
 * sola columna separados por « / », y hay que conservarlo para que el sistema
 * viejo los siga encontrando.
 */
class BookingFilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        Storage::fake('documentos');

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0,
        ]]);
        DB::table('file_fields')->insert([['field_id' => 1, 'field' => 'swb_file', 'label' => 'SWB']]);
        DB::table('fields_by_client')->insert([['customer_field_id' => 1, 'client_id' => 1, 'field_id' => 1]]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function detalle(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(BookingDetail::class, ['booking' => 1]);
    }

    public function test_solo_se_ofrecen_los_campos_que_pide_ese_cliente(): void
    {
        DB::table('file_fields')->insert([['field_id' => 2, 'field' => 'otro', 'label' => 'De otro cliente']]);

        $documentos = $this->detalle()->viewData('documentos');

        $this->assertCount(1, $documentos);
        $this->assertSame('SWB', $documentos[0]->label);
    }

    public function test_adjuntar_un_documento(): void
    {
        $this->detalle()
            ->call('chooseField', 1)
            ->set('upload', UploadedFile::fake()->create('swb.pdf', 10, 'application/pdf'))
            ->assertHasNoErrors();

        Storage::disk('documentos')->assertExists('bookings/1/docs/swb.pdf');

        $this->assertSame('swb.pdf', DB::table('files_by_booking')->value('value'));
    }

    /** El formato heredado: varios archivos en una columna, separados por « / ». */
    public function test_un_segundo_archivo_se_agrega_a_la_lista_con_el_separador_heredado(): void
    {
        DB::table('files_by_booking')->insert([
            'booking_file_id' => 1, 'booking_id' => 1, 'field_id' => 1, 'value' => 'primero.pdf',
        ]);

        $this->detalle()
            ->call('chooseField', 1)
            ->set('upload', UploadedFile::fake()->create('segundo.pdf', 10, 'application/pdf'));

        $this->assertSame('primero.pdf / segundo.pdf', DB::table('files_by_booking')->value('value'));
    }

    public function test_quitar_un_documento_lo_saca_de_la_lista(): void
    {
        DB::table('files_by_booking')->insert([
            'booking_file_id' => 1, 'booking_id' => 1, 'field_id' => 1, 'value' => 'uno.pdf / dos.pdf',
        ]);

        $this->detalle()->call('removeFile', 1, 'uno.pdf');

        $this->assertSame('dos.pdf', DB::table('files_by_booking')->value('value'));
    }

    /** Como el `delete-file` del original: se quita el renglón, el archivo se queda. */
    public function test_quitar_un_documento_no_lo_borra_del_disco(): void
    {
        DB::table('files_by_booking')->insert([
            'booking_file_id' => 1, 'booking_id' => 1, 'field_id' => 1, 'value' => 'uno.pdf',
        ]);
        Storage::disk('documentos')->put('bookings/1/docs/uno.pdf', 'contenido');

        $this->detalle()->call('removeFile', 1, 'uno.pdf');

        Storage::disk('documentos')->assertExists('bookings/1/docs/uno.pdf');
    }

    /** Solo las extensiones que aceptaba el original. */
    public function test_solo_se_adjuntan_las_extensiones_del_original(): void
    {
        $this->detalle()
            ->call('chooseField', 1)
            ->set('upload', UploadedFile::fake()->create('virus.exe', 10))
            ->assertHasErrors('upload');

        $this->assertSame(0, DB::table('files_by_booking')->count());
    }

    public function test_una_hoja_de_calculo_se_adjunta(): void
    {
        $this->detalle()
            ->call('chooseField', 1)
            ->set('upload', UploadedFile::fake()->create('costos.xlsx', 10))
            ->assertHasNoErrors();

        $this->assertSame('costos.xlsx', DB::table('files_by_booking')->value('value'));
    }

    /** Lo que no es PDF ni imagen se descarga aunque se pida verlo. */
    public function test_lo_que_no_es_pdf_ni_imagen_siempre_se_descarga(): void
    {
        DB::table('files_by_booking')->insert([
            'booking_file_id' => 1, 'booking_id' => 1, 'field_id' => 1, 'value' => 'costos.xlsx',
        ]);
        Storage::disk('documentos')->put('bookings/1/docs/costos.xlsx', 'contenido');

        $this->actingAs($this->usuario());

        $this->get(route('operations.bookings.file', [1, 'costos.xlsx', 'ver' => 1]))
            ->assertOk()->assertHeader('content-disposition', 'attachment; filename=costos.xlsx');
    }

    public function test_la_descarga_verifica_que_el_archivo_sea_de_ese_booking(): void
    {
        DB::table('files_by_booking')->insert([
            'booking_file_id' => 1, 'booking_id' => 1, 'field_id' => 1, 'value' => 'uno.pdf',
        ]);
        Storage::disk('documentos')->put('bookings/1/docs/uno.pdf', 'contenido');

        $this->actingAs($this->usuario());

        $this->get(route('operations.bookings.file', [1, 'uno.pdf']))->assertOk();
        $this->get(route('operations.bookings.file', [1, 'ajeno.pdf']))->assertNotFound();
    }

    public function test_con_ver_el_archivo_se_abre_en_el_navegador(): void
    {
        DB::table('files_by_booking')->insert([
            'booking_file_id' => 1, 'booking_id' => 1, 'field_id' => 1, 'value' => 'uno.pdf',
        ]);
        Storage::disk('documentos')->put('bookings/1/docs/uno.pdf', 'contenido');

        $this->actingAs($this->usuario());

        // Por omisión se descarga (adjunto); con ?ver=1 se muestra en línea.
        $this->get(route('operations.bookings.file', [1, 'uno.pdf']))
            ->assertOk()->assertHeader('content-disposition', 'attachment; filename=uno.pdf');

        $ver = $this->get(route('operations.bookings.file', [1, 'uno.pdf', 'ver' => 1]))->assertOk();
        $this->assertStringContainsString('inline', $ver->headers->get('content-disposition'));
    }

    public function test_un_booking_cerrado_no_recibe_documentos(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        $this->detalle()->call('chooseField', 1)->assertForbidden();
    }

    public function test_los_nombres_se_leen_del_formato_heredado(): void
    {
        $archivos = app(BookingFiles::class);

        $this->assertSame(['uno.pdf', 'dos.pdf'], $archivos->names('uno.pdf / dos.pdf'));
        $this->assertSame([], $archivos->names(null));
        $this->assertSame(['solo.pdf'], $archivos->names('  solo.pdf  '));
    }
}
