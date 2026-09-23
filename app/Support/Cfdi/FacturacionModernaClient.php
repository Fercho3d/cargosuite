<?php

namespace App\Support\Cfdi;

use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Cliente SOAP del PAC (Facturación Moderna / DINVBOX).
 *
 * Traducción del componente `FacturacionModerna` de Yii2. El endpoint y las
 * credenciales salen de `config/timbrado.php`, que en local apunta al ambiente
 * de **pruebas** salvo que se encienda `TIMBRADO_PRODUCCION`.
 */
class FacturacionModernaClient implements PacClient
{
    private const TIEMPO_LIMITE = 60;

    public function stamp(string $layout): StampedInvoice
    {
        $respuesta = $this->soap('requestTimbrarCFDI', [
            'text2CFDI' => base64_encode($layout),
            'generarCBB' => false,
            'generarPDF' => true,
            'generarTXT' => false,
        ] + $this->credentials(), $this->endpoint());

        $xml = isset($respuesta->xml) ? base64_decode($respuesta->xml) : null;

        if (blank($xml)) {
            throw new CfdiException('El PAC no devolvió el XML del comprobante.');
        }

        return new StampedInvoice(
            uuid: $this->uuidFrom($xml),
            xml: $xml,
            pdf: isset($respuesta->pdf) ? base64_decode($respuesta->pdf) : null,
        );
    }

    /**
     * La petición es la MISMA que arma `FacturacionModerna::cancelar()` de Yii2,
     * que es la que hoy funciona en producción: método `requestCancelarCFDI`
     * contra el endpoint de timbrado, claves `Motivo`, `FolioSustitucion` (solo
     * con el motivo 01: con otros el PAC la rechaza) y `uuid` en minúsculas, más
     * las credenciales de la cuenta.
     *
     * En producción `emisorRFC` es el RFC con el que se timbró; en pruebas el
     * original manda el de la cuenta demo, y aquí igual.
     *
     * Lo que cambia respecto del original es que **se lee la respuesta**: el PAC
     * contesta con un acuse (`Code`/`Message`), y salvo cancelación consumada lo
     * que hay es una solicitud a la espera del receptor.
     */
    public function cancel(string $uuid, string $rfcEmisor, string $motivo, ?string $sustituye = null): CancelResult
    {
        $peticion = ['Motivo' => $motivo];

        if ($motivo === '01') {
            $peticion['FolioSustitucion'] = (string) $sustituye;
        }

        $peticion['uuid'] = $uuid;

        $credenciales = $this->credentials();

        if (config('timbrado.produccion')) {
            $credenciales['emisorRFC'] = $rfcEmisor;
        }

        try {
            $respuesta = $this->soap('requestCancelarCFDI', $peticion + $credenciales, $this->cancellationEndpoint());
        } catch (CfdiException $e) {
            /*
             * El 402 —«El UUID se encuentra en cola de solicitud de
             * cancelacion»— NO es un error para quien factura: la solicitud ya
             * viajó antes y sigue en pie. Enseñarlo como falla llevaba a
             * reintentar una cancelación que ya estaba puesta.
             */
            if ($e->codigo === '402') {
                return new CancelResult(
                    CancelResult::EN_COLA,
                    $e->codigo,
                    'Este folio ya tenía una solicitud de cancelación en curso ante el SAT.',
                );
            }

            throw $e;
        }

        return $this->cancellationResult($respuesta);
    }

    /**
     * Lee el acuse de cancelación del PAC.
     *
     * Observado en producción: `GT11` con «Solicitud de cancelación recibida. El
     * receptor debe autorizar la cancelación.».
     *
     * El acuse nunca da la factura por cancelada: eso solo lo sabe el SAT y lo
     * confirma `RefreshCancellationStatus` justo después. Antes bastaba con que
     * el texto dijera «cancelad…», y un rechazo como «no puede ser cancelado»
     * dejaba marcadas facturas que el SAT seguía viendo vigentes (F-14857).
     */
    private function cancellationResult(object $respuesta): CancelResult
    {
        $codigo = isset($respuesta->Code) ? trim((string) $respuesta->Code) : null;
        $mensaje = isset($respuesta->Message) ? trim((string) $respuesta->Message) : '';

        // Con aceptación: el comprobante sigue VIGENTE hasta que conteste el receptor.
        if ($codigo === 'GT11' || preg_match('/autoriz|acepta/i', $mensaje) === 1) {
            return new CancelResult(
                CancelResult::SOLICITADA,
                $codigo,
                'Solicitud de cancelación enviada. El receptor debe autorizarla; si no responde en 72 horas, el SAT la cancela por plazo vencido.',
            );
        }

        // Cualquier otro acuse se guarda tal cual, con el código y el texto del PAC.
        return new CancelResult(
            CancelResult::SOLICITADA,
            $codigo,
            $mensaje !== '' ? $mensaje : 'El PAC recibió la solicitud sin decir en qué estado quedó.',
        );
    }

    /**
     * El folio fiscal vive en el complemento de timbre del XML que devuelve el PAC.
     */
    private function uuidFrom(string $xml): string
    {
        $documento = @simplexml_load_string($xml);

        if ($documento === false) {
            throw new CfdiException('El PAC devolvió un XML que no se pudo leer.');
        }

        $documento->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
        $timbre = $documento->xpath('//tfd:TimbreFiscalDigital');

        if (! isset($timbre[0]['UUID'])) {
            throw new CfdiException('El comprobante llegó sin folio fiscal (UUID).');
        }

        return (string) $timbre[0]['UUID'];
    }

    /**
     * La llamada SOAP en sí. Es lo único que sale a la red, y por eso va aparte
     * y es sobrescribible: las pruebas la sustituyen para ver qué petición se
     * armó sin hablar con el PAC.
     *
     * @param  array<string, mixed>  $peticion  Ya con las credenciales.
     */
    protected function soap(string $metodo, array $peticion, string $endpoint): object
    {
        try {
            $cliente = new SoapClient($endpoint, [
                'exceptions' => true,
                'trace' => 1,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'keep_alive' => false,
                'stream_context' => stream_context_create([
                    /*
                     * El sistema original desactivaba la verificación del
                     * certificado. Aquí se verifica por omisión —son documentos
                     * fiscales— y solo se puede desactivar a propósito, si la
                     * cadena de certificados del PAC diera problemas.
                     */
                    'ssl' => [
                        'verify_peer' => (bool) config('timbrado.verificar_tls', true),
                        'verify_peer_name' => (bool) config('timbrado.verificar_tls', true),
                    ],
                    'http' => ['user_agent' => 'CargoSuite/1.0', 'timeout' => self::TIEMPO_LIMITE],
                ]),
            ]);

            return (object) $cliente->{$metodo}((object) $peticion);
        } catch (SoapFault $e) {
            // El mensaje del PAC trae la clave del rechazo (CFDI40211, 300, 402…)
            // y es lo que necesita ver quien factura; las credenciales no se
            // registran. `delPac()` conserva esa clave para poder decidir con ella.
            Log::warning('El PAC rechazó la operación', [
                'metodo' => $metodo,
                'codigo' => $e->faultcode ?? null,
                'error' => $e->getMessage(),
            ]);

            throw CfdiException::delPac($e);
        } catch (Throwable $e) {
            Log::error('No se pudo hablar con el PAC', ['metodo' => $metodo, 'error' => $e->getMessage()]);

            throw new CfdiException('No se pudo conectar con el PAC: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Credenciales de la cuenta del PAC, con los nombres de clave que espera su
     * servicio web.
     *
     * @return array{emisorRFC: string, UserID: string, UserPass: string}
     */
    private function credentials(): array
    {
        if (! config('timbrado.produccion')) {
            $demo = config('timbrado.demo');

            return ['emisorRFC' => $demo['rfc_cuenta'], 'UserID' => $demo['usuario'], 'UserPass' => $demo['password']];
        }

        foreach (['rfc_cuenta', 'usuario', 'password'] as $clave) {
            if (blank(config("timbrado.{$clave}"))) {
                throw new CfdiException(
                    "Falta la credencial «{$clave}» del PAC: revisa el .env del servidor."
                );
            }
        }

        return [
            'emisorRFC' => config('timbrado.rfc_cuenta'),
            'UserID' => config('timbrado.usuario'),
            'UserPass' => config('timbrado.password'),
        ];
    }

    private function endpoint(): string
    {
        return config('timbrado.produccion')
            ? config('timbrado.endpoints.produccion')
            : config('timbrado.endpoints.pruebas');
    }

    /**
     * Un CFDI se cancela ante el MISMO PAC que lo timbró: por omisión es el
     * endpoint de timbrado, como en el original, que también admitía
     * sobrescribirlo (`urlCancelacion`).
     */
    private function cancellationEndpoint(): string
    {
        return config('timbrado.endpoints.cancelacion') ?: $this->endpoint();
    }
}
