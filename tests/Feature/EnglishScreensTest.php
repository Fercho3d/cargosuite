<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Catalogs\CatalogRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Pinta las pantallas EN INGLÉS y busca español en lo que sale.
 *
 * Es la comprobación que faltaba: el rastreo estático encuentra llaves sin
 * traducir, pero no ve las etiquetas que nunca se envolvieron en `__()`. Aquí
 * se mira el HTML final, que es lo que el usuario tiene enfrente.
 *
 * Va en el grupo `parity` porque necesita la base real: las pantallas no dicen
 * nada sin datos.
 */
#[Group('parity')]
class EnglishScreensTest extends LegacyDatabaseTestCase
{
    /**
     * Palabras que delatan español. Se buscan como palabra suelta y
     * DISTINGUIENDO mayúsculas: «Banco» es una etiqueta de la interfaz, pero
     * «BANCO BASE MXN» es el nombre de un banco guardado en la base y no se
     * traduce. Sin esa distinción los datos daban falsos avisos.
     *
     * @var string[]
     */
    private const DELATORAS = [
        'Todas', 'Todos', 'Facturas', 'Costos', 'Pagadas', 'Parciales', 'Vigentes',
        'Canceladas', 'Fecha', 'Número', 'Importe', 'Pagado',
        'Proveedor', 'Proveedores', 'Clientes', 'Buscar', 'Guardar', 'Cancelar',
        'Borrar', 'Nombre', 'Correo', 'Teléfono', 'Dirección', 'Moneda',
        'Solicitudes', 'Filtros', 'Limpiar', 'Compañía', 'Aplicado',
    ];

    /**
     * Trozos del armazón que llevan español a propósito o que no son texto de
     * la interfaz: el nombre de las rutas, los datos de la base y los códigos.
     *
     * @var string[]
     */
    private const RUIDO = [
        '/transacciones', '/solicitudes', '/facturas', '/costos', '/catalogos',
        '/operacion', '/pagos', '/terceros', '/preferencias', '/seguridad', '/avisos',
        'app_theme', 'app_locale', 'wire:', 'x-on:', 'x-data', 'livewire',
    ];

    private function admin(): User
    {
        $usuario = User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->orderBy('usr_id')
            ->first();

        if (! $usuario) {
            $this->markTestSkipped('La base local no tiene una cuenta administradora.');
        }

        return $usuario;
    }

    /** @return string[] rutas con nombre que se pintan sin parámetros */
    public static function pantallas(): array
    {
        return [
            ['transactions.invoice'], ['transactions.bill'], ['transactions.all'],
            ['transactions.report.booking'], ['payments.requests'],
            ['payments.report.customer'], ['payments.report.vendor'], ['payments.report.general'],
            ['operations.bookings'], ['operations.continuity'],
            ['parties.clients'], ['parties.providers'], ['parties.services'],
            ['exchange'], ['users'], ['dashboard'],
        ];
    }

    /**
     * Las 16 pantallas de catálogo, que van todas por la misma ruta con un
     * parámetro. No entran en el proveedor de datos porque la lista sale del
     * registro, y ese ya traduce: necesita la aplicación levantada.
     *
     * Aquí se coló la última tanda —«Navieras», «Modalidades», «Monedas»,
     * «Buques», «Destinos finales»—: se quedaron en español porque esta prueba
     * no miraba estas pantallas.
     */
    public function test_los_catalogos_no_dejan_texto_en_espanol(): void
    {
        // Con las dos formas de transporte encendidas: si no, los catálogos de
        // flota responden 404 y se quedarían sin vigilar justo por ser nuevos.
        config(['marca.modalidades' => 'maritimo,terrestre']);

        $this->actingAs($this->admin());

        $sucias = [];

        foreach (CatalogRegistry::all() as $definicion) {
            $html = $this->withUnencryptedCookie('app_locale', 'en')
                ->get(route('catalogs.show', $definicion->slug))
                ->assertOk()
                ->getContent();

            $limpio = str_replace(self::RUIDO, ' ', $html);

            foreach (self::DELATORAS as $palabra) {
                if (preg_match('/(?<![\w\/-])'.preg_quote($palabra, '/').'(?![\w-])/u', $limpio)) {
                    $sucias[$definicion->slug][] = $palabra;
                }
            }
        }

        $this->assertSame([], $sucias, 'Catálogos con texto en español: '.json_encode($sucias, JSON_UNESCAPED_UNICODE));
    }

    #[DataProvider('pantallas')]
    public function test_la_pantalla_no_deja_texto_en_espanol(string $ruta): void
    {
        $this->actingAs($this->admin());

        $html = $this->withUnencryptedCookie('app_locale', 'en')
            ->get(route($ruta))
            ->assertOk()
            ->getContent();

        // Fuera el ruido: rutas, atributos de Livewire y demás armazón.
        $limpio = str_replace(self::RUIDO, ' ', $html);

        $encontradas = [];

        foreach (self::DELATORAS as $palabra) {
            if (preg_match('/(?<![\w\/-])'.preg_quote($palabra, '/').'(?![\w-])/u', $limpio)) {
                $encontradas[] = $palabra;
            }
        }

        $this->assertSame([], $encontradas, "En «{$ruta}» quedó texto en español: ".implode(', ', $encontradas));
    }
}
