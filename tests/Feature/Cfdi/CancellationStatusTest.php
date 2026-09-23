<?php

namespace Tests\Feature\Cfdi;

use App\Livewire\Transactions\TransactionDetail;
use App\Livewire\Transactions\TransactionTable;
use App\Models\CfdiCancelacion;
use App\Models\Core\Transaction;
use App\Models\User;
use App\Support\Cfdi\CancelResult;
use App\Support\Cfdi\PacClient;
use App\Support\Cfdi\SatStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoreSchema;
use Tests\Support\FakePacClient;
use Tests\Support\FakeSatStatus;
use Tests\Support\InvoiceFixture;
use Tests\TestCase;

/**
 * El ciclo de vida real de una cancelación de CFDI.
 *
 * Pedir la cancelación no es cancelar: el PAC devuelve un acuse y el
 * comprobante sigue vigente ante el SAT hasta que el receptor autorice o se le
 * venza el plazo. Antes de esto el sistema marcaba «cancelada» en cuanto la
 * llamada no tronaba, y el ERP decía una cosa mientras el SAT decía otra.
 *
 * **Ni el PAC ni el SAT se tocan de verdad**: los dos van sustituidos.
 */
class CancellationStatusTest extends TestCase
{
    private FakePacClient $pac;

    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        Storage::fake('documentos');
        Http::preventStrayRequests();

        $this->pac = new FakePacClient;
        $this->app->instance(PacClient::class, $this->pac);
        $this->app->instance(SatStatus::class, new FakeSatStatus);

        InvoiceFixture::seed();
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        $usuario = new User;
        $usuario->usr_id = 7;
        $usuario->role = $rol;

        return $usuario;
    }

    /** Factura timbrada y lista para cancelar, con su XML guardado. */
    private function detalle(): Testable
    {
        $this->actingAs($this->usuario());

        return Livewire::test(TransactionDetail::class, ['transaction' => 1]);
    }

    private function timbrar(): void
    {
        $this->detalle()->call('stamp')->assertHasNoErrors();
    }

    private function cancelar(string $motivo = '02'): Testable
    {
        return $this->detalle()->call('startCancel')->set('cancelReason', $motivo)->call('cancelStamp');
    }

    private function sat(FakeSatStatus $sat): FakeSatStatus
    {
        $this->app->instance(SatStatus::class, $sat);

        return $sat;
    }

    // ------------------------------------------- Lo que contesta el PAC

    /** El SAT la da por cancelada al consultarlo justo después: ahí sí se marca. */
    public function test_una_cancelacion_confirmada_por_el_sat_marca_la_factura(): void
    {
        $this->timbrar();
        $this->sat(new FakeSatStatus(estado: 'Cancelado', estatusCancelacion: 'Cancelado sin aceptación'));

        $this->cancelar()->assertHasNoErrors();

        $this->assertSame(1, (int) Transaction::find(1)->cancelled);
    }

    public function test_una_cancelacion_confirmada_guarda_el_motivo_y_el_folio_que_sustituye(): void
    {
        $this->timbrar();
        $this->sat(new FakeSatStatus(estado: 'Cancelado', estatusCancelacion: 'Cancelado sin aceptación'));

        $this->detalle()
            ->call('startCancel')
            ->set('cancelReason', '01')
            ->set('replacementUuid', 'UUID-NUEVO')
            ->call('cancelStamp')
            ->assertHasNoErrors();

        $this->assertSame('UUID-NUEVO', Transaction::find(1)->new_seal);
    }

    /** GT11: el receptor tiene que autorizar, así que la factura SIGUE VIGENTE. */
    public function test_un_acuse_gt11_no_cancela_la_factura(): void
    {
        $this->timbrar();

        $this->cancelar()->assertHasNoErrors();

        $this->assertSame(0, (int) Transaction::find(1)->cancelled);
    }

    public function test_un_acuse_gt11_queda_anotado_como_solicitud(): void
    {
        $this->timbrar();

        $this->cancelar()->assertHasNoErrors();

        $solicitud = CfdiCancelacion::where('transc_id', 1)->first();

        $this->assertSame(
            [CancelResult::SOLICITADA, 'GT11', $this->pac->uuid, '02', 7],
            [$solicitud->estado, $solicitud->codigo, $solicitud->uuid, $solicitud->motivo, $solicitud->solicitado_por],
        );
    }

    /** El 402 del PAC no es un error: la solicitud ya estaba puesta. */
    public function test_el_folio_en_cola_no_se_enseña_como_error(): void
    {
        $this->timbrar();
        $this->pac->responde(new CancelResult(CancelResult::EN_COLA, '402', 'Este folio ya tenía una solicitud de cancelación en curso ante el SAT.'));

        $this->cancelar()->assertHasNoErrors();

        $this->assertSame(CancelResult::EN_COLA, CfdiCancelacion::where('transc_id', 1)->first()->estado);
    }

    /** Un rechazo de verdad (300: folio no localizado) sí se enseña. */
    public function test_un_rechazo_del_pac_no_deja_rastro_de_cancelacion(): void
    {
        $this->timbrar();
        $this->app->instance(PacClient::class, new FakePacClient(falla: 'El PAC respondió: [300] Error UUID no localizado en la base de timbrados'));

        $this->cancelar()->assertHasErrors('cfdi');

        $this->assertSame(0, CfdiCancelacion::count());
    }

    /**
     * Con una solicitud en curso sí se puede volver a pedir: el PAC contesta
     * que el folio ya está en su cola, y eso se enseña tal cual en vez de
     * dejar la pantalla sin salida.
     */
    public function test_con_una_solicitud_en_curso_se_puede_volver_a_pedir(): void
    {
        $this->detalle()->call('stamp')->call('cancelStamp');

        $this->assertTrue($this->detalle()->instance()->canCancel());
    }

    // ------------------------------------------- Lo que contesta el SAT

    /** Vigente: el receptor todavía no contesta, y la factura no se toca. */
    public function test_si_el_sat_dice_vigente_la_factura_sigue_sin_cancelar(): void
    {
        $this->timbrar();
        $this->cancelar();
        $this->sat(new FakeSatStatus(estado: 'Vigente', estatusCancelacion: 'En proceso'));

        $this->detalle()->call('refreshSatStatus');

        $this->assertSame(0, (int) Transaction::find(1)->cancelled);
    }

    public function test_la_consulta_al_sat_queda_anotada(): void
    {
        $this->timbrar();
        $this->cancelar();
        $this->sat(new FakeSatStatus(estado: 'Vigente', estatusCancelacion: 'En proceso'));

        $this->detalle()->call('refreshSatStatus');

        $solicitud = CfdiCancelacion::where('transc_id', 1)->first();

        $this->assertSame(
            ['Vigente', 'En proceso', CancelResult::SOLICITADA],
            [$solicitud->sat_estado, $solicitud->sat_estatus, $solicitud->estado],
        );
    }

    /** Cancelado ante el SAT: ahora sí, sea por autorización o por plazo vencido. */
    public function test_si_el_sat_dice_cancelado_se_marca_la_factura(): void
    {
        $this->timbrar();
        $this->cancelar();
        $this->sat(new FakeSatStatus(estado: 'Cancelado', estatusCancelacion: 'Plazo vencido'));

        $this->detalle()->call('refreshSatStatus');

        $this->assertSame(1, (int) Transaction::find(1)->cancelled);
    }

    /** @return array<string, array{string, string, string}> */
    public static function coloresDelSat(): array
    {
        return [
            'vigente en verde' => ['Vigente', '', 'badge-ok'],
            'cancelado en rojo' => ['Cancelado', 'Plazo vencido', 'badge-danger'],
            'no encontrado en ámbar' => ['No Encontrado', '', 'badge-warn'],
        ];
    }

    #[DataProvider('coloresDelSat')]
    public function test_lo_que_dice_el_sat_sale_con_color(string $estado, string $estatus, string $clase): void
    {
        $this->timbrar();
        $this->sat(new FakeSatStatus(estado: $estado, estatusCancelacion: $estatus));

        $this->detalle()->call('refreshSatStatus')
            ->assertSeeHtml("badge {$clase}\">{$estado}</span>");
    }

    /** Con solicitud de cancelación, la línea de la última consulta ya lo dice. */
    public function test_con_solicitud_el_estado_del_sat_no_se_repite(): void
    {
        $this->timbrar();
        $this->cancelar();
        $this->sat(new FakeSatStatus(estado: 'Vigente'));

        $html = $this->detalle()->call('refreshSatStatus')->html();

        $this->assertSame(1, substr_count($html, 'badge-ok">Vigente</span>'));
    }

    public function test_la_consulta_va_con_los_cuatro_datos_del_xml_timbrado(): void
    {
        $this->timbrar();
        $this->cancelar();
        $sat = $this->sat(new FakeSatStatus);

        $this->detalle()->call('refreshSatStatus');

        $this->assertSame(
            "?re=XAXX010101000&rr=AAA010101AAA&tt=2320.00&id={$this->pac->uuid}",
            $sat->consultas[0],
        );
    }

    /** Un servicio caído no puede dejar la factura en un estado inventado. */
    public function test_si_el_sat_no_contesta_no_se_cambia_nada(): void
    {
        $this->timbrar();
        $this->sat(new FakeSatStatus(caido: true));
        $this->cancelar();

        $this->detalle()->call('refreshSatStatus')->assertHasNoErrors();

        $this->assertNull(CfdiCancelacion::where('transc_id', 1)->first()->verificado_at);
    }

    /** Sin XML no hay con qué preguntar, y tampoco truena. */
    public function test_sin_xml_la_consulta_avisa_en_vez_de_tronar(): void
    {
        CfdiCancelacion::create([
            'transc_id' => 1, 'uuid' => 'UUID-SIN-XML', 'motivo' => '02',
            'estado' => CancelResult::SOLICITADA, 'solicitado_at' => now(),
        ]);

        $this->detalle()->call('refreshSatStatus')->assertSee('No se encontró el XML timbrado');
    }

    // ------------------------------------------------------- La pantalla

    public function test_el_detalle_avisa_que_falta_la_autorizacion_del_receptor(): void
    {
        $this->timbrar();

        $this->cancelar();

        $this->detalle()->assertSee('Cancelación en proceso')->assertSee('El receptor debe autorizarla');
    }

    public function test_el_listado_distingue_la_cancelacion_en_proceso_de_la_cancelada(): void
    {
        $this->timbrar();
        $this->cancelar();

        $this->actingAs($this->usuario());

        // La insignia roja de «Cancelada» es la otra de esta misma celda; con
        // una solicitud en curso no debe aparecer.
        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->assertSee('Cancelación en proceso')
            ->assertDontSee('badge badge-danger ml-1', false);
    }

    // --------------------------------------------------------- El comando

    public function test_el_comando_confirma_las_solicitudes_pendientes(): void
    {
        $this->timbrar();
        $this->cancelar();
        $this->sat(new FakeSatStatus(estado: 'Cancelado', estatusCancelacion: 'Cancelado con aceptación'));

        $this->artisan('cfdi:revisar-cancelaciones', ['--pausa' => 0])->assertSuccessful();

        $this->assertSame(1, (int) Transaction::find(1)->cancelled);
    }

    public function test_el_comando_deja_en_paz_las_que_el_sat_ve_vigentes(): void
    {
        $this->timbrar();
        $this->cancelar();
        $this->sat(new FakeSatStatus(estado: 'Vigente', estatusCancelacion: 'En proceso'));

        $this->artisan('cfdi:revisar-cancelaciones', ['--pausa' => 0])->assertSuccessful();

        $this->assertTrue(CfdiCancelacion::where('transc_id', 1)->first()->estaPendiente());
    }

    /**
     * Las facturas que se marcaron canceladas sin mirar la respuesta del PAC
     * entran como solicitudes, para que el comando las revise y las corrija. Su
     * `cancelled` no se toca: cambiarlo a ciegas sería el mismo error al revés.
     */
    public function test_la_migracion_rellena_las_canceladas_de_antes(): void
    {
        Schema::drop('cfdi_cancelacion');
        DB::table('transaction')->where('transc_id', 1)->update([
            'cancelled' => 1, 'seal' => 'UUID-VIEJO', 'cancel_reason_id' => '03', 'modified_by' => 4,
        ]);

        (require database_path('migrations/2026_09_22_000001_create_cfdi_cancelacion.php'))->up();

        $solicitud = CfdiCancelacion::where('transc_id', 1)->first();

        $this->assertSame(
            ['UUID-VIEJO', '03', CancelResult::SOLICITADA, 1],
            [$solicitud->uuid, $solicitud->motivo, $solicitud->estado, (int) Transaction::find(1)->cancelled],
        );
    }

    public function test_el_comando_no_vuelve_a_preguntar_por_las_ya_cerradas(): void
    {
        $this->timbrar();
        $this->cancelar();
        CfdiCancelacion::where('transc_id', 1)->update(['estado' => CancelResult::CANCELADA]);
        $sat = $this->sat(new FakeSatStatus);

        $this->artisan('cfdi:revisar-cancelaciones', ['--pausa' => 0])->assertSuccessful();

        $this->assertSame([], $sat->consultas);
    }

    // ------------------------------------- Estado en el listado y su filtro

    /**
     * Cada factura enseña en qué punto va su cancelación.
     *
     * Antes todas las que tenían `cancelled = 1` decían «Cancelada», incluidas
     * las que el SAT sigue viendo vigentes porque el receptor las rechazó o
     * porque la solicitud nunca llegó. En producción había once así.
     */
    public static function estadosEnElListado(): array
    {
        return [
            'confirmada por el SAT' => [CancelResult::CANCELADA, 'Cancelado', 'Cancelado sin aceptación', 1, 'Cancelada'],
            'esperando al receptor' => [CancelResult::SOLICITADA, null, null, 0, 'Cancelación en proceso'],
            'rechazada por el receptor' => [CancelResult::RECHAZADA, 'Vigente', 'Solicitud rechazada', 1, 'Cancelación rechazada'],
            'marcada aquí y vigente allá' => [CancelResult::SOLICITADA, 'Vigente', null, 1, 'Vigente ante el SAT'],
        ];
    }

    #[DataProvider('estadosEnElListado')]
    public function test_el_listado_dice_en_que_va_la_cancelacion(
        string $estado, ?string $satEstado, ?string $satEstatus, int $cancelled, string $insignia
    ): void {
        $this->anotaCancelacion($estado, $satEstado, $satEstatus, $cancelled);

        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->set('showCancelled', '1')
            ->assertSee(__($insignia));
    }

    #[DataProvider('estadosEnElListado')]
    public function test_el_filtro_trae_las_facturas_de_ese_estado(
        string $estado, ?string $satEstado, ?string $satEstatus, int $cancelled, string $insignia, ?string $vista = null
    ): void {
        $this->anotaCancelacion($estado, $satEstado, $satEstatus, $cancelled);

        $this->actingAs($this->usuario());

        $vistas = [
            'Cancelada' => CfdiCancelacion::VISTA_CANCELADA,
            'Cancelación en proceso' => CfdiCancelacion::VISTA_PROCESO,
            'Cancelación rechazada' => CfdiCancelacion::VISTA_RECHAZADA,
            'Vigente ante el SAT' => CfdiCancelacion::VISTA_VIGENTE,
        ];

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->set('cfdiEstado', $vistas[$insignia]);

        $this->assertSame([1], collect($pantalla->viewData('rows')->items())->pluck('transc_id')->map(intval(...))->all());
    }

    /** El filtro de un estado deja fuera a las facturas de los otros. */
    public function test_el_filtro_deja_fuera_las_de_otro_estado(): void
    {
        $this->anotaCancelacion(CancelResult::RECHAZADA, 'Vigente', 'Solicitud rechazada', 1);

        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->set('cfdiEstado', CfdiCancelacion::VISTA_CANCELADA);

        $this->assertSame([], collect($pantalla->viewData('rows')->items())->pluck('transc_id')->all());
    }

    /** Una solicitud dentro del plazo sigue «en proceso» aunque el SAT diga vigente. */
    public function test_dentro_del_plazo_la_solicitud_sigue_en_proceso(): void
    {
        $this->anotaCancelacion(CancelResult::SOLICITADA, 'Vigente', null, 1, now()->subHours(2));

        $this->assertSame(CfdiCancelacion::VISTA_PROCESO, CfdiCancelacion::first()->estadoVisible());
    }

    private function anotaCancelacion(
        string $estado, ?string $satEstado, ?string $satEstatus, int $cancelled, ?Carbon $solicitado = null
    ): void {
        DB::table('transaction')->where('transc_id', 1)->update([
            'cancelled' => $cancelled,
            'seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2',
        ]);

        CfdiCancelacion::create([
            'transc_id' => 1,
            'uuid' => '3ECE3E47-7242-44E9-B6DB-355091F891C2',
            'motivo' => '02',
            'estado' => $estado,
            'sat_estado' => $satEstado,
            'sat_estatus' => $satEstatus,
            'solicitado_at' => $solicitado ?? now()->subDays(10),
        ]);
    }

    // ------------------------------- Timbrar y cancelar desde el listado

    /** Timbrar una factura suelta, sin marcarla ni usar el lote. */
    public function test_se_timbra_desde_el_renglon_del_listado(): void
    {
        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->call('stampRow', 1)
            ->assertHasNoErrors();

        $this->assertSame($this->pac->uuid, Transaction::find(1)->seal);
    }

    public function test_el_rechazo_del_pac_se_avisa_en_el_listado(): void
    {
        $this->app->instance(PacClient::class, new FakePacClient(falla: 'El PAC respondió: [CFDI40211] Retenciones'));

        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->call('stampRow', 1)
            ->assertHasErrors('cfdi');

        $this->assertNull(Transaction::find(1)->seal);
    }

    /** Cancelar desde el listado pide el motivo y avisa lo que contestó el PAC. */
    public function test_se_cancela_desde_el_listado_eligiendo_el_motivo(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2']);

        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->call('startCancel', 1)
            ->assertSet('cancelling', 1)
            ->set('cancelReason', '02')
            ->call('cancelRow')
            ->assertHasNoErrors()
            ->assertSet('cancelling', null);

        $this->assertSame('02', CfdiCancelacion::where('transc_id', 1)->value('motivo'));
    }

    /** El motivo 01 exige el folio que sustituye, y el listado lo dice. */
    public function test_el_motivo_uno_exige_el_folio_que_sustituye(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update(['seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2']);

        $this->actingAs($this->usuario());

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->call('startCancel', 1)
            ->set('cancelReason', '01')
            ->call('cancelRow')
            ->assertHasErrors('cfdi');
    }

    public function test_quien_no_es_administrador_no_timbra_desde_el_listado(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER));

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->call('stampRow', 1)
            ->assertForbidden();
    }

    /**
     * Una factura con la cancelación en trámite se sigue viendo en «Solo
     * vigentes»: para el SAT sigue viva, y esconderla era justo lo que impedía
     * darle seguimiento. En producción había once así, invisibles.
     */
    public function test_la_cancelacion_en_tramite_no_esconde_la_factura(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update([
            'cancelled' => 1, 'seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2',
        ]);
        CfdiCancelacion::create([
            'transc_id' => 1, 'uuid' => '3ECE3E47-7242-44E9-B6DB-355091F891C2', 'motivo' => '02',
            'estado' => CancelResult::SOLICITADA, 'sat_estado' => 'Vigente', 'solicitado_at' => now(),
        ]);

        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);

        $this->assertSame([1], collect($pantalla->viewData('rows')->items())->pluck('transc_id')->map(intval(...))->all());
        $pantalla->assertSee(__('Cancelación en proceso'));
    }

    /** Una cancelada de verdad sigue fuera del listado de vigentes. */
    public function test_una_cancelada_confirmada_no_sale_en_vigentes(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update([
            'cancelled' => 1, 'seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2',
        ]);
        CfdiCancelacion::create([
            'transc_id' => 1, 'uuid' => '3ECE3E47-7242-44E9-B6DB-355091F891C2', 'motivo' => '02',
            'estado' => CancelResult::CANCELADA, 'sat_estado' => 'Cancelado', 'solicitado_at' => now(),
        ]);

        $this->actingAs($this->usuario());

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);

        $this->assertSame([], collect($pantalla->viewData('rows')->items())->pluck('transc_id')->all());
    }

    /**
     * Si el SAT la sigue viendo vigente, se puede volver a pedir la cancelación.
     *
     * Con el candado anterior, las facturas rechazadas por el receptor o
     * atoradas en la cola del PAC quedaban en un limbo: marcadas como
     * canceladas aquí, vivas para el SAT y sin forma de reintentar.
     */
    public function test_una_vigente_ante_el_sat_se_puede_volver_a_cancelar(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update([
            'cancelled' => 1, 'seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2',
        ]);
        CfdiCancelacion::create([
            'transc_id' => 1, 'uuid' => '3ECE3E47-7242-44E9-B6DB-355091F891C2', 'motivo' => '02',
            'estado' => CancelResult::RECHAZADA, 'sat_estado' => 'Vigente',
            'sat_estatus' => 'Solicitud rechazada', 'solicitado_at' => now()->subDays(5),
        ]);

        $this->actingAs($this->usuario());

        $this->assertTrue($this->detalle()->instance()->canCancel());

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->assertSee(__('Reintentar cancelación'));
    }

    /** Una cancelada de verdad ya no se vuelve a pedir. */
    public function test_una_cancelada_confirmada_no_se_vuelve_a_pedir(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update([
            'cancelled' => 1, 'seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2',
        ]);
        CfdiCancelacion::create([
            'transc_id' => 1, 'uuid' => '3ECE3E47-7242-44E9-B6DB-355091F891C2', 'motivo' => '02',
            'estado' => CancelResult::CANCELADA, 'sat_estado' => 'Cancelado', 'solicitado_at' => now()->subDays(5),
        ]);

        $this->actingAs($this->usuario());

        $this->assertFalse($this->detalle()->instance()->canCancel());
    }

    /**
     * Marcada como cancelada sin solicitud registrada (como F-14857 en
     * producción): al abrirla se le pregunta al SAT y, si sigue vigente, se
     * dice así y se puede pedir la cancelación.
     */
    public function test_marcada_cancelada_sin_solicitud_y_vigente_en_el_sat_se_puede_cancelar(): void
    {
        $this->timbrar();
        DB::table('transaction')->where('transc_id', 1)->update(['cancelled' => 1]);
        $this->sat(new FakeSatStatus(estado: 'Vigente'));
        $this->actingAs($this->usuario());

        $this->detalle()->call('refreshSatStatus')
            ->assertSee(__('Vigente ante el SAT'))
            ->assertSee(__('Cancelar CFDI'));
    }

    /** Sin consultar al SAT, la marca heredada se respeta y no se ofrece cancelar. */
    public function test_marcada_cancelada_sin_solicitud_no_ofrece_cancelar_sin_consultar(): void
    {
        DB::table('transaction')->where('transc_id', 1)->update([
            'cancelled' => 1, 'seal' => '3ECE3E47-7242-44E9-B6DB-355091F891C2',
        ]);
        $this->actingAs($this->usuario());

        $this->assertFalse($this->detalle()->instance()->canCancel());
    }

    /**
     * Un acuse del PAC que habla de «cancelado» no marca nada si el SAT la
     * sigue viendo vigente. Así quedó mal marcada F-14857 en producción.
     */
    public function test_un_acuse_del_pac_no_marca_cancelada_si_el_sat_la_ve_vigente(): void
    {
        $this->timbrar();
        $this->pac->responde(new CancelResult(CancelResult::SOLICITADA, 'XX', 'El CFDI no puede ser cancelado.'));

        $this->cancelar()->assertHasNoErrors();

        $this->assertSame(0, (int) Transaction::find(1)->cancelled);
    }

    /** Con la cancelación en proceso, el detalle trae el texto para el receptor. */
    public function test_en_proceso_muestra_las_instrucciones_para_el_receptor(): void
    {
        $this->timbrar();
        $this->sat(new FakeSatStatus(estado: 'Vigente', estatusCancelacion: 'En proceso'));
        $this->cancelar()->assertHasNoErrors();

        $this->detalle()
            ->assertSee(__('Copiar instrucciones para el cliente'))
            ->assertSee('Espere 72 horas', escape: false)
            ->assertSee(CfdiCancelacion::where('transc_id', 1)->value('uuid'));
    }

    /** Una cancelada de verdad ya no pide nada al receptor. */
    public function test_cancelada_no_muestra_instrucciones(): void
    {
        $this->timbrar();
        $this->sat(new FakeSatStatus(estado: 'Cancelado', estatusCancelacion: 'Cancelado sin aceptación'));
        $this->cancelar()->assertHasNoErrors();

        $this->detalle()->assertDontSee(__('Copiar instrucciones para el cliente'));
    }

    /**
     * Si el SAT todavía no tiene la solicitud, el receptor no puede aceptarla
     * ni corre el plazo: ni instrucciones ni «espere 72 horas».
     */
    public function test_sin_solicitud_en_el_sat_no_pide_esperar_ni_da_instrucciones(): void
    {
        $this->timbrar();
        $this->sat(new FakeSatStatus(estado: 'Vigente'));
        $this->cancelar()->assertHasNoErrors();

        $this->detalle()
            ->assertSee('sigue en el PAC')
            ->assertDontSee('Espere 72 horas')
            ->assertDontSee(__('Copiar instrucciones para el cliente'));
    }
}
