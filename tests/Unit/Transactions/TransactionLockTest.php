<?php

namespace Tests\Unit\Transactions;

use App\Models\User;
use App\Support\TransactionLock;
use Tests\TestCase;

/**
 * Los candados de edición, con la misma prioridad que el formulario de Yii2.
 *
 * Es lógica pura, así que se prueba sin base de datos: interesa el orden en que
 * se aplican las reglas, no de dónde salen los números.
 */
class TransactionLockTest extends TestCase
{
    private function fila(array $valores = []): object
    {
        return (object) array_merge([
            'seal' => null,
            'amount_original' => 1000.0,
            'left_to_pay' => 1000.0,
            'tran_paid_amount' => 0.0,
        ], $valores);
    }

    private function usuario(int $rol): User
    {
        $usuario = new User;
        $usuario->role = $rol;

        return $usuario;
    }

    public function test_un_administrador_edita_una_transaccion_abierta(): void
    {
        $candado = TransactionLock::evaluate($this->fila(), false, $this->usuario(User::ROLE_ADMIN));

        $this->assertFalse($candado->locked);
        $this->assertNull($candado->reason);
    }

    public function test_quien_no_es_administrador_no_edita(): void
    {
        $candado = TransactionLock::evaluate($this->fila(), false, $this->usuario(User::ROLE_USER));

        $this->assertTrue($candado->locked);
        $this->assertStringContainsString('permiso', $candado->reason);
    }

    public function test_sin_sesion_queda_bloqueada(): void
    {
        $this->assertTrue(TransactionLock::evaluate($this->fila(), false, null)->locked);
    }

    public function test_una_transaccion_timbrada_queda_bloqueada(): void
    {
        $candado = TransactionLock::evaluate(
            $this->fila(['seal' => 'ABC123']),
            false,
            $this->usuario(User::ROLE_ADMIN),
        );

        $this->assertTrue($candado->locked);
        $this->assertStringContainsString('timbrada', $candado->reason);
    }

    public function test_una_transaccion_con_pagos_queda_bloqueada(): void
    {
        $candado = TransactionLock::evaluate(
            $this->fila(['tran_paid_amount' => 250.0]),
            false,
            $this->usuario(User::ROLE_ADMIN),
        );

        $this->assertTrue($candado->locked);
    }

    public function test_un_booking_bloqueado_bloquea_la_transaccion(): void
    {
        $candado = TransactionLock::evaluate($this->fila(), true, $this->usuario(User::ROLE_SUPER_ADMIN));

        $this->assertTrue($candado->locked);
        $this->assertStringContainsString('booking', $candado->reason);
    }

    /**
     * La rareza que se conserva del original: el super administrador recupera la
     * edición cuando no queda saldo, pero la última regla vuelve a bloquear si el
     * documento tiene importe. En la práctica solo se abre una transacción sin
     * importe (por ejemplo, recién creada y aún sin conceptos).
     */
    public function test_el_super_administrador_solo_recupera_la_edicion_sin_importe(): void
    {
        $saldada = TransactionLock::evaluate(
            $this->fila(['left_to_pay' => 0.0, 'tran_paid_amount' => 1000.0]),
            false,
            $this->usuario(User::ROLE_SUPER_ADMIN),
        );

        $sinImporte = TransactionLock::evaluate(
            $this->fila(['amount_original' => 0.0, 'left_to_pay' => 0.0]),
            false,
            $this->usuario(User::ROLE_SUPER_ADMIN),
        );

        $this->assertTrue($saldada->locked, 'con importe saldado debe seguir bloqueada');
        $this->assertFalse($sinImporte->locked, 'sin importe el super administrador sí edita');
    }

    public function test_un_administrador_normal_no_recupera_la_edicion_de_una_saldada(): void
    {
        $candado = TransactionLock::evaluate(
            $this->fila(['left_to_pay' => 0.0, 'tran_paid_amount' => 1000.0]),
            false,
            $this->usuario(User::ROLE_ADMIN),
        );

        $this->assertTrue($candado->locked);
        $this->assertStringContainsString('saldada', $candado->reason);
    }

    // ------------------------------------------------------------ Borrado

    public function test_solo_el_super_administrador_borra(): void
    {
        $this->assertFalse(TransactionLock::canDelete($this->fila(), false, $this->usuario(User::ROLE_ADMIN)));
        $this->assertTrue(TransactionLock::canDelete($this->fila(), false, $this->usuario(User::ROLE_SUPER_ADMIN)));
        $this->assertFalse(TransactionLock::canDelete($this->fila(), false, null));
    }

    public function test_no_se_borra_con_el_booking_cerrado_ni_saldada_ni_con_pagos(): void
    {
        $superAdmin = $this->usuario(User::ROLE_SUPER_ADMIN);

        $this->assertFalse(
            TransactionLock::canDelete($this->fila(), true, $superAdmin),
            'Booking cerrado.',
        );
        $this->assertFalse(
            TransactionLock::canDelete($this->fila(['left_to_pay' => 0.0]), false, $superAdmin),
            'Transacción saldada.',
        );
        $this->assertFalse(
            TransactionLock::canDelete($this->fila(['tran_paid_amount' => 10.0]), false, $superAdmin),
            'Transacción con pagos.',
        );
    }

    /** En el original el sello y el PDF no estorban cuando quien borra es super administrador. */
    public function test_el_sello_no_impide_borrar_al_super_administrador(): void
    {
        $this->assertTrue(TransactionLock::canDelete(
            $this->fila(['seal' => 'ABC123']),
            false,
            $this->usuario(User::ROLE_SUPER_ADMIN),
        ));
    }

    // ------------------------------------------------------- Cambio de fecha

    public function test_la_fecha_solo_la_corrige_el_super_administrador_con_booking_abierto(): void
    {
        $this->assertTrue(TransactionLock::canChangeDate(false, $this->usuario(User::ROLE_SUPER_ADMIN)));
        $this->assertFalse(TransactionLock::canChangeDate(true, $this->usuario(User::ROLE_SUPER_ADMIN)));
        $this->assertFalse(TransactionLock::canChangeDate(false, $this->usuario(User::ROLE_ADMIN)));
    }
}
