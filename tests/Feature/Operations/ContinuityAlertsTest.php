<?php

namespace Tests\Feature\Operations;

use App\Mail\ContinuityAlertMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Avisos de tareas del booking sin marcar: el cron que en Yii2 vivía detrás de
 * dos direcciones web.
 */
class ContinuityAlertsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        Mail::fake();

        config(['marca.correo.avisos_operacion' => ['avisos@ejemplo.test']]);

        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
        ]]);
    }

    /** Continuidad del booking con una fecha estimada en el hito que se indique. */
    private function continuidad(string $campo, string $fecha): void
    {
        DB::table('booking_continuity')->insert([[
            'cont_id' => 1, 'booking' => 1, $campo => $fecha,
        ]]);
    }

    private function checklist(array $cambios = []): void
    {
        DB::table('check_list')->insert([array_merge(['check_id' => 1, 'booking' => 1], $cambios)]);
    }

    public function test_una_tarea_pendiente_con_fecha_futura_es_un_aviso(): void
    {
        $this->continuidad('SI_date', now()->addDays(3)->toDateTimeString());
        $this->checklist();

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'aviso'])->assertSuccessful();

        Mail::assertSent(ContinuityAlertMail::class, fn ($correo) => $correo->hasTo('avisos@ejemplo.test')
            && $correo->envelope()->subject === 'SI task is not completed booking[BK-1]');
    }

    public function test_la_misma_tarea_con_la_fecha_cumplida_es_un_vencimiento(): void
    {
        $this->continuidad('SI_date', now()->subDay()->toDateTimeString());
        $this->checklist();

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'vencido'])->assertSuccessful();

        Mail::assertSent(ContinuityAlertMail::class, fn ($correo) => $correo->envelope()->subject === 'SI task deadline! booking[BK-1]');
    }

    public function test_los_niveles_no_se_pisan(): void
    {
        $this->continuidad('SI_date', now()->addDays(3)->toDateTimeString());
        $this->checklist();

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'vencido'])->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_una_tarea_ya_marcada_no_se_avisa(): void
    {
        $this->continuidad('SI_date', now()->addDays(3)->toDateTimeString());
        $this->checklist(['SI_date_chk_date' => now()->toDateTimeString()]);

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'aviso'])->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_sin_fecha_estimada_no_hay_nada_que_vigilar(): void
    {
        $this->continuidad('SI_date', '');
        $this->checklist();

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'aviso'])->assertSuccessful();

        Mail::assertNothingSent();
    }

    /** Réplica del caso especial: sin lista de verificación se avisa del primer hito. */
    public function test_un_booking_sin_lista_de_verificacion_se_avisa_por_el_primer_hito(): void
    {
        $this->continuidad('vacuum_maneuver', now()->addDay()->toDateTimeString());

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'aviso'])->assertSuccessful();

        Mail::assertSent(ContinuityAlertMail::class, fn ($correo) => $correo->envelope()->subject === 'Empty Pass task is not completed booking[BK-1]');
        Mail::assertSentCount(1);
    }

    /**
     * «Gated Out» está dos veces en la lista del original y mandaba dos correos
     * iguales; en el nuevo sale uno.
     */
    public function test_gated_out_manda_un_solo_correo(): void
    {
        $this->continuidad('gated_out', now()->addDay()->toDateTimeString());
        $this->checklist();

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'aviso'])->assertSuccessful();

        Mail::assertSentCount(1);
    }

    public function test_sin_destinatario_avisa_y_no_manda_nada(): void
    {
        config(['marca.correo.avisos_operacion' => []]);
        $this->continuidad('SI_date', now()->addDays(3)->toDateTimeString());
        $this->checklist();

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'aviso'])
            ->expectsOutputToContain('MARCA_MAIL_AVISOS')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_simular_no_manda_nada(): void
    {
        $this->continuidad('SI_date', now()->addDays(3)->toDateTimeString());
        $this->checklist();

        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'aviso', '--simular' => true])->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_un_nivel_desconocido_no_hace_nada(): void
    {
        $this->artisan('operacion:avisos-continuidad', ['nivel' => 'urgente'])->assertFailed();

        Mail::assertNothingSent();
    }
}
