<?php

namespace Tests\Feature\Cfdi;

use App\Support\Cfdi\CancelResult;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\FacturacionModernaClient;
use SoapFault;
use Tests\TestCase;

/**
 * La petición SOAP que se le arma al PAC, sin hablar con él.
 *
 * La cancelación tiene que viajar EXACTAMENTE como la manda el componente
 * `FacturacionModerna` de Yii2, que es lo que hoy funciona en producción:
 * mismo método, mismo endpoint y mismos nombres de clave (con sus mayúsculas).
 * Un nombre distinto no da error claro: el PAC contesta que no encuentra el
 * folio.
 */
class FacturacionModernaClientTest extends TestCase
{
    /** Cliente que apunta lo que iba a mandar en vez de salir a la red. */
    private function cliente(): FacturacionModernaClient
    {
        return new class extends FacturacionModernaClient
        {
            /** @var array<int, array{metodo: string, peticion: array<string, mixed>, endpoint: string}> */
            public array $llamadas = [];

            protected function soap(string $metodo, array $peticion, string $endpoint): object
            {
                $this->llamadas[] = compact('metodo', 'peticion', 'endpoint');

                return (object) [];
            }
        };
    }

    public function test_la_cancelacion_va_como_en_el_sistema_original(): void
    {
        config(['timbrado.produccion' => false]);

        $cliente = $this->cliente();
        $cliente->cancel('UUID-1', 'FTM1507038V6', '02');

        $llamada = $cliente->llamadas[0];

        $this->assertSame('requestCancelarCFDI', $llamada['metodo']);
        $this->assertSame(config('timbrado.endpoints.pruebas'), $llamada['endpoint'], 'Se cancela ante el mismo endpoint que timbra.');
        $this->assertSame(['Motivo', 'uuid', 'emisorRFC', 'UserID', 'UserPass'], array_keys($llamada['peticion']));
        $this->assertSame('02', $llamada['peticion']['Motivo']);
        $this->assertSame('UUID-1', $llamada['peticion']['uuid']);
        // En pruebas el original manda el RFC de la cuenta demo, no el del emisor.
        $this->assertSame(config('timbrado.demo.rfc_cuenta'), $llamada['peticion']['emisorRFC']);
        $this->assertSame(config('timbrado.demo.usuario'), $llamada['peticion']['UserID']);
    }

    public function test_el_folio_de_sustitucion_solo_viaja_con_el_motivo_01(): void
    {
        config(['timbrado.produccion' => false]);

        $cliente = $this->cliente();
        $cliente->cancel('UUID-1', 'FTM1507038V6', '01', 'UUID-NUEVO');
        $cliente->cancel('UUID-1', 'FTM1507038V6', '03', 'UUID-QUE-SOBRA');

        $this->assertSame('UUID-NUEVO', $cliente->llamadas[0]['peticion']['FolioSustitucion']);
        $this->assertArrayNotHasKey('FolioSustitucion', $cliente->llamadas[1]['peticion']);
    }

    /** En producción `emisorRFC` es el RFC con el que se timbró: el PAC busca el folio en la base de ese emisor. */
    public function test_en_produccion_cancela_con_el_rfc_del_emisor_y_las_credenciales_reales(): void
    {
        config([
            'timbrado.produccion' => true,
            'timbrado.rfc_cuenta' => 'CUENTA010101AAA',
            'timbrado.usuario' => 'usuario-real',
            'timbrado.password' => 'clave-real',
        ]);

        $cliente = $this->cliente();
        $cliente->cancel('UUID-1', 'FTM1507038V6', '02');

        $peticion = $cliente->llamadas[0]['peticion'];

        $this->assertSame('FTM1507038V6', $peticion['emisorRFC']);
        $this->assertSame('usuario-real', $peticion['UserID']);
        $this->assertSame('clave-real', $peticion['UserPass']);
        $this->assertSame(config('timbrado.endpoints.produccion'), $cliente->llamadas[0]['endpoint']);
    }

    public function test_el_endpoint_de_cancelacion_se_puede_sobrescribir(): void
    {
        config(['timbrado.produccion' => false, 'timbrado.endpoints.cancelacion' => 'https://otro.pac.test/wsdl']);

        $cliente = $this->cliente();
        $cliente->cancel('UUID-1', 'FTM1507038V6', '02');

        $this->assertSame('https://otro.pac.test/wsdl', $cliente->llamadas[0]['endpoint']);
    }

    // ------------------------------------ Lo que contesta el PAC al cancelar

    /**
     * Cliente que contesta lo que se le diga: un acuse, o el rechazo ya
     * traducido por `soap()`, que es lo único que sale a la red.
     */
    private function clienteQueContesta(object $respuesta): FacturacionModernaClient
    {
        return new class($respuesta) extends FacturacionModernaClient
        {
            public function __construct(private object $respuesta) {}

            protected function soap(string $metodo, array $peticion, string $endpoint): object
            {
                if ($this->respuesta instanceof CfdiException) {
                    throw $this->respuesta;
                }

                return $this->respuesta;
            }
        };
    }

    /** Observado en producción: el acuse que deja la factura vigente. */
    public function test_el_acuse_gt11_queda_como_solicitud_a_la_espera_del_receptor(): void
    {
        $resultado = $this->clienteQueContesta((object) [
            'Code' => 'GT11',
            'Message' => 'Solicitud de cancelación recibida. El receptor debe autorizar la cancelación.',
        ])->cancel('UUID-1', 'FTM1507038V6', '02');

        $this->assertSame(
            [CancelResult::SOLICITADA, 'GT11', false],
            [$resultado->estado, $resultado->codigo, $resultado->esCancelacionConfirmada()],
        );
    }

    /** Ni un acuse que dice «cancelado» confirma: eso lo dice el SAT. */
    public function test_el_acuse_nunca_confirma_la_cancelacion(): void
    {
        $resultado = $this->clienteQueContesta((object) [
            'Code' => 'XX',
            'Message' => 'El CFDI no puede ser cancelado.',
        ])->cancel('UUID-1', 'FTM1507038V6', '02');

        $this->assertFalse($resultado->esCancelacionConfirmada());
    }

    /** Un acuse que no se reconoce no se da por cancelado: se conserva tal cual. */
    public function test_un_acuse_desconocido_no_se_da_por_cancelado(): void
    {
        $resultado = $this->clienteQueContesta((object) [
            'Code' => 'XX99',
            'Message' => 'Texto que nadie ha visto antes.',
        ])->cancel('UUID-1', 'FTM1507038V6', '02');

        $this->assertSame(
            [CancelResult::SOLICITADA, 'XX99', 'Texto que nadie ha visto antes.'],
            [$resultado->estado, $resultado->codigo, $resultado->mensaje],
        );
    }

    /** El 402 no es un error para quien factura: la solicitud ya estaba puesta. */
    public function test_el_folio_en_cola_no_es_un_error(): void
    {
        $resultado = $this->clienteQueContesta(
            CfdiException::delPac(new SoapFault('402', 'El UUID se encuentra en cola de solicitud de cancelacion.'))
        )->cancel('UUID-1', 'FTM1507038V6', '02');

        $this->assertSame(CancelResult::EN_COLA, $resultado->estado);
    }

    /** El 300 sí lo es, y el aviso lleva el código y el texto del PAC. */
    public function test_un_folio_que_el_pac_no_localiza_se_avisa_con_su_codigo(): void
    {
        $cliente = $this->clienteQueContesta(
            CfdiException::delPac(new SoapFault('300', 'Error UUID no localizado en la base de timbrados [UUID-1]'))
        );

        $this->expectExceptionMessage('El PAC respondió: [300] Error UUID no localizado en la base de timbrados [UUID-1]');

        $cliente->cancel('UUID-1', 'FTM1507038V6', '02');
    }

    /** El `faultcode` del sobre SOAP («soap:Server») no es una clave del PAC. */
    public function test_el_espacio_de_nombres_del_sobre_no_se_toma_por_codigo(): void
    {
        $fallo = CfdiException::delPac(new SoapFault('soap:Server', 'El servicio no está disponible.'));

        $this->assertNull($fallo->codigo);
    }

    /** El timbrado sí viaja con el RFC de la CUENTA: el del emisor va dentro del layout. */
    public function test_el_timbrado_viaja_con_las_credenciales_de_la_cuenta(): void
    {
        config(['timbrado.produccion' => false]);

        $cliente = $this->cliente();

        try {
            $cliente->stamp('[ComprobanteFiscalDigital]');
        } catch (CfdiException) {
            // El doble no devuelve XML; aquí solo interesa la petición.
        }

        $llamada = $cliente->llamadas[0];

        $this->assertSame('requestTimbrarCFDI', $llamada['metodo']);
        $this->assertSame(config('timbrado.endpoints.pruebas'), $llamada['endpoint']);
        $this->assertSame(base64_encode('[ComprobanteFiscalDigital]'), $llamada['peticion']['text2CFDI']);
        $this->assertSame(config('timbrado.demo.rfc_cuenta'), $llamada['peticion']['emisorRFC']);
    }
}
