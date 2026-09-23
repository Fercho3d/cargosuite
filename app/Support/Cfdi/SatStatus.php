<?php

namespace App\Support\Cfdi;

use App\Models\Core\Transaction;
use App\Support\TransactionFiles;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le pregunta al SAT en qué estado está un comprobante.
 *
 * Es el servicio público de consulta de CFDI (el mismo que usa el lector de
 * códigos QR de la factura): **solo lectura, sin credenciales**. Es la única
 * fuente que sabe si una cancelación solicitada ya se consumó, porque el PAC
 * contesta una vez y no vuelve a avisar.
 *
 * Los cuatro datos de la consulta —RFC emisor, RFC receptor, total y folio
 * fiscal— salen del XML timbrado que ya guarda el sistema.
 *
 * Nunca lanza hacia la pantalla: si falta el XML o el SAT no contesta devuelve
 * un resultado con `estado` en null y el motivo. Un servicio caído no puede
 * dejar la factura en un estado inventado.
 *
 * `consultarServicio()` es lo único que sale a la red, y por eso va aparte y es
 * sobrescribible: las pruebas lo sustituyen.
 */
class SatStatus
{
    private const ACCION = 'http://tempuri.org/IConsultaCFDIService/Consulta';

    /** Corto a propósito: esto corre dentro de una petición de pantalla. */
    private const TIEMPO_LIMITE = 15;

    public function __construct(private readonly TransactionFiles $archivos) {}

    public function consultar(Transaction $transaccion): SatStatusResult
    {
        $datos = $this->datosDelXml($transaccion);

        if ($datos === null) {
            return new SatStatusResult(
                estado: null,
                motivo: 'No se encontró el XML timbrado de esta factura, que es de donde salen los datos de la consulta.',
            );
        }

        [$emisor, $receptor, $total, $uuid] = $datos;

        try {
            $respuesta = $this->consultarServicio("?re={$emisor}&rr={$receptor}&tt={$total}&id={$uuid}");
        } catch (Throwable $e) {
            Log::warning('No se pudo consultar el estado en el SAT', [
                'transc_id' => $transaccion->transc_id,
                'error' => $e->getMessage(),
            ]);

            return new SatStatusResult(estado: null, motivo: 'El servicio de consulta del SAT no contestó.');
        }

        $estado = $this->valor($respuesta, 'Estado');

        if ($estado === null) {
            return new SatStatusResult(estado: null, motivo: 'El SAT contestó algo que no se pudo leer.');
        }

        return new SatStatusResult(
            estado: $estado,
            esCancelable: $this->valor($respuesta, 'EsCancelable'),
            estatusCancelacion: $this->valor($respuesta, 'EstatusCancelacion'),
            codigoEstatus: $this->valor($respuesta, 'CodigoEstatus'),
        );
    }

    /**
     * La llamada al servicio del SAT. Devuelve el sobre SOAP tal cual.
     *
     * @throws Throwable si el servicio no contesta.
     */
    protected function consultarServicio(string $expresionImpresa): string
    {
        $sobre = <<<XML
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
              <soapenv:Header/>
              <soapenv:Body>
                <tem:Consulta>
                  <tem:expresionImpresa><![CDATA[{$expresionImpresa}]]></tem:expresionImpresa>
                </tem:Consulta>
              </soapenv:Body>
            </soapenv:Envelope>
            XML;

        $curl = curl_init(config('timbrado.endpoints.consulta_sat'));

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $sobre,
            CURLOPT_TIMEOUT => self::TIEMPO_LIMITE,
            CURLOPT_CONNECTTIMEOUT => self::TIEMPO_LIMITE,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: '.self::ACCION,
            ],
        ]);

        $respuesta = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($respuesta === false) {
            throw new CfdiException('El servicio de consulta del SAT no contestó: '.$error);
        }

        return (string) $respuesta;
    }

    /**
     * Los cuatro datos de la consulta, leídos del XML timbrado.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    private function datosDelXml(Transaction $transaccion): ?array
    {
        $ruta = $this->archivos->path($transaccion, TransactionFiles::XML);

        if ($ruta === null) {
            return null;
        }

        $documento = @simplexml_load_string((string) file_get_contents($ruta));

        if ($documento === false) {
            return null;
        }

        $emisor = $documento->xpath('//*[local-name()="Emisor"]')[0] ?? null;
        $receptor = $documento->xpath('//*[local-name()="Receptor"]')[0] ?? null;
        $timbre = $documento->xpath('//*[local-name()="TimbreFiscalDigital"]')[0] ?? null;

        $datos = [
            (string) ($emisor['Rfc'] ?? ''),
            (string) ($receptor['Rfc'] ?? ''),
            (string) ($documento['Total'] ?? ''),
            (string) ($timbre['UUID'] ?? $transaccion->seal),
        ];

        return in_array('', $datos, true) ? null : $datos;
    }

    /** El contenido de una etiqueta del sobre del SAT, venga con el prefijo que venga. */
    private function valor(string $xml, string $etiqueta): ?string
    {
        if (preg_match('/<(?:\w+:)?'.$etiqueta.'>(.*?)<\//s', $xml, $encontrado) !== 1) {
            return null;
        }

        return trim($encontrado[1]);
    }
}
