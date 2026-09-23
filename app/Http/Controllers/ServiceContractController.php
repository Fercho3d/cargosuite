<?php

namespace App\Http\Controllers;

use App\Models\Core\Service;
use App\Support\ServiceFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entrega el contrato en PDF de un servicio (`actionContractFile` en Yii2),
 * abierto en el navegador.
 */
class ServiceContractController extends Controller
{
    public function __invoke(ServiceFiles $archivos, int $service): BinaryFileResponse
    {
        $servicio = Service::findOrFail($service);
        $ruta = $archivos->path($servicio->service_id, $servicio->contract);

        abort_if($ruta === null, 404, 'El servicio no tiene contrato o el archivo ya no está en el servidor.');

        return response()->file($ruta, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$servicio->contract.'"',
        ]);
    }
}
