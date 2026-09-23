<?php

namespace Tests\Support;

use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\SatStatus;
use App\Support\TransactionFiles;

/**
 * El servicio de consulta del SAT, de mentiras.
 *
 * Sustituye lo ÚNICO que sale a la red y devuelve un sobre SOAP como el de
 * verdad, para que la lectura de la respuesta se pruebe igual que en producción.
 * Las pruebas no hablan con el SAT.
 */
class FakeSatStatus extends SatStatus
{
    /** Las expresiones impresas que se le pidieron, para poder afirmar sobre ellas. */
    public array $consultas = [];

    public function __construct(
        public string $estado = 'Vigente',
        public string $estatusCancelacion = '',
        public string $esCancelable = 'Cancelable con aceptación',
        public bool $caido = false,
    ) {
        parent::__construct(app(TransactionFiles::class));
    }

    protected function consultarServicio(string $expresionImpresa): string
    {
        $this->consultas[] = $expresionImpresa;

        if ($this->caido) {
            throw new CfdiException('El servicio de consulta del SAT no contestó.');
        }

        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            .'<ConsultaResponse xmlns="http://tempuri.org/"><ConsultaResult xmlns:a="http://schemas.datacontract.org/2004/07/Sat.Cfdi.Negocio.ConsultaCfdi.Servicio">'
            .'<a:CodigoEstatus>S - Comprobante obtenido satisfactoriamente.</a:CodigoEstatus>'
            .'<a:EsCancelable>'.$this->esCancelable.'</a:EsCancelable>'
            .'<a:Estado>'.$this->estado.'</a:Estado>'
            .'<a:EstatusCancelacion>'.$this->estatusCancelacion.'</a:EstatusCancelacion>'
            .'</ConsultaResult></ConsultaResponse></s:Body></s:Envelope>';
    }
}
