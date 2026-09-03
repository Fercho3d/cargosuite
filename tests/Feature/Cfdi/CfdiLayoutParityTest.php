<?php

namespace Tests\Feature\Cfdi;

use App\Models\Core\Charge;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\Cfdi\CfdiLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Paridad del layout CFDI contra el sistema original, línea por línea.
 *
 * Es la prueba más delicada de la migración: de este texto salen documentos
 * fiscales. Se compara contra el layout REAL que produce `models/CFDI.php` de
 * Yii2 para facturas de verdad de la base local, capturado con
 * `tools/export_legacy_cfdi.php`. **No se habla con el PAC en ningún momento.**
 *
 * Se ignora la línea `Fecha=`, que es el momento de la generación.
 */
#[Group('parity')]
class CfdiLayoutParityTest extends LegacyDatabaseTestCase
{
    /**
     * El nombre del emisor por omisión ya no está escrito en el código sino en
     * `config/timbrado.php`, y por omisión viene vacío. La paridad se compara
     * contra los documentos de la instalación anterior, así que aquí se fija el
     * suyo: es el que usan las facturas previas al catálogo de compañías.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['timbrado.emisor_nombre' => 'FREGO TRADING & LOGISTICS DE MEXICO']);
    }

    public static function facturas(): array
    {
        $path = dirname(__DIR__, 2).'/Fixtures/legacy-cfdi-layout.json';

        if (! file_exists($path)) {
            return ['fixture ausente' => ['__missing__']];
        }

        $casos = [];

        foreach (json_decode(file_get_contents($path), true) as $caso) {
            $casos['factura '.$caso['transc_id']] = [$caso];
        }

        return $casos;
    }

    #[DataProvider('facturas')]
    public function test_el_layout_es_identico_al_del_sistema_original(array|string $caso): void
    {
        if ($caso === '__missing__') {
            $this->markTestSkipped('Falta tests/Fixtures/legacy-cfdi-layout.json (regenerar con export_legacy_cfdi.php).');
        }

        $nuestro = $this->build($caso['transc_id']);

        $this->assertSame(
            $this->normalize($caso['layout']),
            $this->normalize($nuestro),
            "El layout de la factura {$caso['transc_id']} no coincide con el del sistema original."
        );
    }

    private function build(int $transactionId): string
    {
        $transaccion = Transaction::with(['company', 'client', 'currency'])->findOrFail($transactionId);

        // El encabezado necesita las columnas calculadas (tipo de cambio, número
        // de booking), las mismas que el original toma del buscador al timbrar.
        $fila = TransactionQuery::make(
            TransactionFilters::make(['tran_in' => [$transactionId], 'showCancelled' => 1])
        )->get(1)->first();

        return (new CfdiLayout(
            transaccion: $transaccion,
            emisor: $transaccion->company,
            receptor: $transaccion->client,
            conceptos: Charge::with('chargeType')->where('transaction', $transactionId)->orderBy('charge_id')->get(),
            nombrePorOmision: (string) config('timbrado.emisor_nombre'),
            rfcPorOmision: (string) config('timbrado.demo.rfc_cuenta'),
            lugarPorOmision: (string) config('timbrado.lugar_expedicion_por_omision'),
            tipoCambio: $fila->exchange_value === null ? null : (float) $fila->exchange_value,
            numeroBooking: $fila->booking_number,
        ))->build();
    }

    /**
     * Normaliza para comparar: quita la fecha de generación y los espacios de
     * orilla de cada renglón, que no cambian el significado del layout.
     */
    private function normalize(string $layout): array
    {
        return collect(preg_split('/\R/', trim($layout)))
            ->map(fn (string $linea) => rtrim($linea))
            ->reject(fn (string $linea) => str_starts_with($linea, 'Fecha='))
            ->reject(fn (string $linea) => $linea === '')
            ->values()
            ->all();
    }
}
