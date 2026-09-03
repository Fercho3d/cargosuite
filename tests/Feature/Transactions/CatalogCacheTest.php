<?php

namespace Tests\Feature\Transactions;

use App\Models\Core\Account;
use App\Models\Core\Company;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Los catálogos del módulo no deben depender de la caché de la aplicación.
 *
 * Existe por dos caídas seguidas en producción, ambas por cachear cosas diminutas:
 *
 *  1. Se guardaban **modelos de Eloquent**. Laravel 13 deserializa la caché con
 *     `allowed_classes` restringido, así que al releerlos volvían como
 *     `__PHP_Incomplete_Class` y la pantalla daba 500.
 *  2. Ya con arreglos planos, los archivos de caché quedaron con otro dueño
 *     (los escribió un comando de consola) y al expirar el TTL el proceso web
 *     no pudo reescribirlos: 500 otra vez.
 *
 * Son tablas de 2 y 4 filas: consultarlas cada vez cuesta menos que administrar
 * su caché. Estas pruebas corren con el driver `file` —el que sí serializa, y el
 * que la suite normal no ejercita porque usa `array`— para que, si alguien vuelve
 * a meter caché aquí, se note antes de llegar al servidor.
 */
class CatalogCacheTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = storage_path('framework/testing/cache-'.getmypid());

        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->cachePath,
        ]);

        CoreSchema::create();

        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN'],
            ['account_id' => 2, 'account_name' => 'Dólares', 'default' => null, 'prefix' => 'USD'],
            ['account_id' => 4, 'account_name' => 'Cuenta sin prefijo', 'default' => null, 'prefix' => null],
        ]);

        DB::table('company')->insert([
            ['company_id' => 1, 'name' => 'FTA', 'rfc' => 'AAA010101AAA'],
            ['company_id' => 2, 'name' => 'FTM', 'rfc' => 'BBB020202BBB'],
        ]);

        DB::table('exchange')->insert([
            ['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2021-01-01', 'account' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    public function test_las_divisas_devuelven_datos_planos_y_son_estables(): void
    {
        $esperado = [1 => 'MXN', 2 => 'USD'];

        $this->assertSame($esperado, Account::options(), 'la cuenta sin prefijo no debe aparecer');
        $this->assertSame($esperado, Account::options(), 'la segunda llamada debe dar lo mismo');
    }

    public function test_las_companias_devuelven_datos_planos_y_son_estables(): void
    {
        $esperado = [1 => 'FTA', 2 => 'FTM'];

        $this->assertSame($esperado, Company::options());
        $this->assertSame($esperado, Company::options());
    }

    public function test_los_catalogos_reflejan_los_cambios_de_inmediato(): void
    {
        Company::options();

        DB::table('company')->insert(['company_id' => 3, 'name' => 'FTZ', 'rfc' => 'CCC030303CCC']);

        $this->assertSame(
            [1 => 'FTA', 2 => 'FTM', 3 => 'FTZ'],
            Company::options(),
            'sin caché de por medio, un alta se ve al instante'
        );
    }

    public function test_el_modulo_no_deja_nada_en_la_cache_de_la_aplicacion(): void
    {
        Account::options();
        Company::options();
        TransactionQuery::make(TransactionFilters::make([]))->get();

        // Si alguien vuelve a cachear, aquí aparecerán archivos y esta prueba lo dirá.
        $archivos = File::isDirectory($this->cachePath)
            ? File::allFiles($this->cachePath)
            : [];

        $this->assertCount(
            0,
            $archivos,
            'El módulo escribió en la caché. Ver el comentario de esta clase antes de hacerlo.'
        );
    }
}
