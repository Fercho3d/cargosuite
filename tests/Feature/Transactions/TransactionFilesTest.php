<?php

namespace Tests\Feature\Transactions;

use App\Livewire\Transactions\TransactionForm;
use App\Models\Core\Transaction;
use App\Models\User;
use App\Support\TransactionFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Adjuntos de la transacción (factura en PDF y su XML).
 *
 * Lo que se cuida aquí es la convivencia con el sistema viejo: los archivos
 * tienen que quedar donde Yii2 los busca, con el mismo nombre, y hay que seguir
 * encontrando los que ya existen aunque su extensión esté en mayúsculas.
 */
class TransactionFilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        Storage::fake('documentos');

        Http::preventStrayRequests();
        Http::fake(['sidofqa.segob.gob.mx/*' => Http::response(['ListaIndicadores' => []])]);

        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('booking')->insert([['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10]]);
        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-1', 'tran_date' => '2026-01-15', 'invoice_type' => 1,
        ]]);
    }

    private function admin(): User
    {
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = User::ROLE_ADMIN;

        return $usuario;
    }

    public function test_el_pdf_queda_donde_lo_busca_el_sistema_viejo(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(TransactionForm::class, ['transaction' => 1])
            ->set('pdfFile', UploadedFile::fake()->create('factura 001.pdf', 12, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        // Ojo: el XML también vive en la subcarpeta `pdf`; así lo dejó el original.
        Storage::disk('documentos')->assertExists('transactions/1/pdf/factura 001.pdf');

        $this->assertSame('factura 001.pdf', Transaction::find(1)->pdf_attach);
    }

    public function test_se_rechaza_un_archivo_que_no_es_pdf(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(TransactionForm::class, ['transaction' => 1])
            ->set('pdfFile', UploadedFile::fake()->create('factura.txt', 12, 'text/plain'))
            ->call('save')
            ->assertHasErrors('pdfFile');

        // La columna no admite nulos en la base real (es parte de la llave
        // primaria física y trae cadena vacía por omisión).
        $this->assertSame('', Transaction::find(1)->pdf_attach);
    }

    public function test_el_xml_se_guarda_junto_al_pdf(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(TransactionForm::class, ['transaction' => 1])
            ->set('xmlFile', UploadedFile::fake()->create('factura.xml', 4, 'application/xml'))
            ->call('save')
            ->assertHasNoErrors();

        Storage::disk('documentos')->assertExists('transactions/1/pdf/factura.xml');
        $this->assertSame('factura.xml', Transaction::find(1)->xml_attach);
    }

    /**
     * En los datos históricos hay archivos guardados con la extensión en
     * mayúsculas mientras la columna la trae en minúsculas. El sistema original
     * reintenta así, y sin ese reintento esas facturas dejarían de abrirse.
     */
    public function test_se_encuentra_el_archivo_aunque_la_extension_este_en_mayusculas(): void
    {
        Storage::disk('documentos')->put('transactions/1/pdf/FACTURA.PDF', 'contenido');
        DB::table('transaction')->where('transc_id', 1)->update(['pdf_attach' => 'FACTURA.pdf']);

        $ruta = app(TransactionFiles::class)->path(Transaction::find(1), TransactionFiles::PDF);

        // La comparación va sin distinguir mayúsculas a propósito: en macOS el
        // sistema de archivos tampoco las distingue y encuentra el archivo al
        // primer intento, mientras que en el Linux de producción hace falta el
        // reintento. Lo que se comprueba en los dos casos es que lo encuentra.
        $this->assertNotNull($ruta, 'No se encontró un archivo que sí está en el disco.');
        $this->assertStringEndsWith('factura.pdf', strtolower($ruta));
    }

    public function test_sin_adjunto_no_hay_ruta(): void
    {
        $this->assertNull(app(TransactionFiles::class)->path(Transaction::find(1), TransactionFiles::PDF));
    }

    public function test_la_descarga_entrega_el_archivo(): void
    {
        Storage::disk('documentos')->put('transactions/1/pdf/factura.pdf', 'contenido');
        DB::table('transaction')->where('transc_id', 1)->update(['pdf_attach' => 'factura.pdf']);

        $this->actingAs($this->admin())
            ->get(route('transactions.file', [1, 'pdf']))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="factura.pdf"');
    }

    public function test_pedir_un_adjunto_que_no_existe_responde_404(): void
    {
        $this->actingAs($this->admin())
            ->get(route('transactions.file', [1, 'pdf']))
            ->assertNotFound();
    }
}
