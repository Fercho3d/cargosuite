<?php

namespace Tests\Feature;

use App\Models\Core\Client;
use App\Models\Core\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Las credenciales que viven fuera de `users`.
 *
 * `client`, `provider` y `carrier` tienen cada uno contraseña, clave de sesión y
 * token de recuperación: tres sistemas de acceso en paralelo al de verdad,
 * ninguno pasa por Fortify ni por el 2FA y nadie los administra desde ninguna
 * pantalla. En la base real hay siete cuentas con contraseña ahí.
 *
 * No se pueden borrar sin más: la instalación original convive con el portal
 * Yii2, que es lo único que las lee. Lo que sí se puede garantizar —y es lo que
 * fijan estas pruebas— es que **esta aplicación no las use ni las enseñe**, y
 * que quitarlas sea un paso explícito y documentado.
 */
class CredencialesHeredadasTest extends TestCase
{
    private const COLUMNAS = ['password', 'auth_key', 'password_reset_token', 'verification_code'];

    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
    }

    /** Nunca salen en un JSON, un correo o una respuesta del portal. */
    public function test_los_modelos_no_exponen_las_credenciales(): void
    {
        foreach ([new Client, new Provider] as $modelo) {
            foreach (self::COLUMNAS as $columna) {
                $this->assertContains(
                    $columna,
                    $modelo->getHidden(),
                    $modelo::class." expone «{$columna}»."
                );
            }
        }
    }

    /**
     * Guardián de verdad: que ningún código de la aplicación lea esas columnas.
     * Hoy no lo hace ninguno, y el día que alguien las use para «reaprovechar»
     * un acceso, esto se pone rojo.
     */
    public function test_ningun_codigo_lee_las_credenciales_de_terceros(): void
    {
        $sospechosos = [];

        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

        foreach ($archivos as $archivo) {
            if ($archivo->isDir() || ! str_ends_with($archivo->getFilename(), '.php')) {
                continue;
            }

            $contenido = (string) file_get_contents($archivo->getPathname());

            // Se ignoran las listas de columnas ocultas, que es justo lo correcto.
            $contenido = preg_replace('/\$hidden\s*=\s*\[[^\]]*\]/s', '', $contenido) ?? '';

            foreach (['auth_key', 'password_reset_token', 'verification_code'] as $columna) {
                if (str_contains($contenido, $columna)) {
                    $sospechosos[] = basename($archivo->getPathname()).' → '.$columna;
                }
            }
        }

        // El comando que las reporta y la migración que las quita sí las nombran.
        $sospechosos = array_values(array_filter(
            $sospechosos,
            fn (string $s) => ! str_starts_with($s, 'ReportLegacyCredentials.php'),
        ));

        $this->assertSame([], $sospechosos, 'Alguien empezó a usar las credenciales heredadas: '.implode(', ', $sospechosos));
    }

    /** La migración que las quita existe y está FUERA de la corrida normal. */
    public function test_quitarlas_es_un_paso_explicito(): void
    {
        $opcionales = glob(database_path('migrations/opcionales/*drop_legacy_credentials*.php')) ?: [];

        $this->assertCount(1, $opcionales, 'Falta la migración opcional que quita las credenciales heredadas.');

        $normales = glob(database_path('migrations/*drop_legacy_credentials*.php')) ?: [];

        $this->assertSame([], $normales, 'La migración NO puede ir en la corrida normal: dejaría fuera al portal antiguo.');
    }

    public function test_el_reporte_cuenta_las_credenciales_que_quedan(): void
    {
        // El esquema de pruebas ya no trae esas columnas —refleja una instalación
        // limpia—, así que para este caso se añade a mano la que se va a contar.
        Schema::table('client', fn ($tabla) => $tabla->string('password', 255)->nullable());

        DB::table('client')->insert([
            ['client_id' => 1, 'fullName' => 'Con acceso viejo', 'password' => 'hash-de-antes'],
            ['client_id' => 2, 'fullName' => 'Sin acceso viejo', 'password' => null],
        ]);

        $this->assertTrue(Schema::hasColumn('client', 'password'));

        $this->artisan('seguridad:credenciales-heredadas')
            ->expectsOutputToContain('no pasan por Fortify')
            ->assertSuccessful();
    }
}
