<?php

namespace Tests;

use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas que necesitan la base real de Frego.
 *
 * Son SOLO LECTURA: comparan resultados y miden tiempos contra los datos de
 * verdad. Nunca migran, truncan ni escriben — la base la comparte el sistema Yii2
 * que sigue en operación.
 *
 * Si la base no está disponible, las pruebas se saltan en vez de fallar, para no
 * romper la suite en una máquina sin copia local.
 */
abstract class LegacyDatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'frego_legacy']);

        try {
            DB::connection('frego_legacy')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No hay conexión a la base `frego` local: '.$e->getMessage());
        }

        if (! DB::connection('frego_legacy')->table('transaction')->exists()) {
            $this->markTestSkipped('La base `frego` local no tiene transacciones cargadas.');
        }
    }
}
