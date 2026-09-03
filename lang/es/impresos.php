<?php

/**
 * Rótulos de los documentos impresos.
 *
 * Van en un archivo de grupo y NO en `en.json` a propósito: el documento tiene
 * que replicar **carácter por carácter** el que emitía el sistema anterior
 * —mayúsculas raras y erratas incluidas, «Dicharge», «Non Deduc»— mientras que
 * la interfaz lleva el inglés bueno. Compartir llaves hacía que traducir una
 * pantalla rompiera un documento, y al revés; ya pasó y lo cazó la paridad.
 */
return [
    'amount' => 'Importe',
    'arrival_date' => 'Fecha de arribo',
    'carrier' => 'Naviera',
    'client_information' => 'Datos del cliente',
    'commodity' => 'Mercancía',
    'container_type' => 'Tipo de contenedor',
    'creation_date' => 'Fecha de creación',
    'currency' => 'Divisa',
    'cut_off_si' => 'Corte de instrucciones',
    'departure_date' => 'Fecha de zarpe',
    'destination_port' => 'Puerto de destino',
    'delivery_address' => 'Dirección de entrega del equipo',
    'final_destination' => 'Destino final',
    'non_deductible' => 'No deducible',
    'number' => 'Número',
    'origin_port' => 'Puerto de origen',
    'pick_up_place' => 'Lugar de recolección',
    'pieces' => 'Piezas',
    'port_closing' => 'Cierre de puerto',
    'quantity' => 'Cantidad',
    'reference' => 'Referencia',
    'remarks' => 'Observaciones',
    'ret_vat' => 'IVA retenido',
    'seal' => 'Sello',
    'spotting_date' => 'Fecha de posicionamiento',
    'total' => 'Total',
    'totals' => 'Totales',
    'memo' => 'Concepto',
    'pay' => 'PÁGUESE A',
    'to_the_order' => 'A la orden de',
    'confirmation_title' => 'Confirmación de booking',
    'quotation_title' => 'Cotización de booking',
];
