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
    'confirmation_title' => 'Confirmación de booking',
    'quotation_title' => 'Cotización de booking',
    'payment_request_title' => 'Solicitud de pago',
    'collection_request_title' => 'Solicitud de cobro',
    'page' => 'Página',
    'folio' => 'Folio',
    'date' => 'Fecha',
    'customer' => 'Cliente',
    'pay_to' => 'Pagar a',
    'bank_account' => 'Cuenta',
    'amount_in_words' => 'Importe con letra',
    'documents_covered' => 'Documentos que cubre',
    'subtotal_0' => 'Subtotal 0 %',
    'subtotal_16' => 'Subtotal 16 %',
    'vat_16' => 'IVA 16 %',
    'documents_count' => '{1} :n documento|[2,*] :n documentos',
    'prepared_by' => 'Elaboró',
    'authorized_by' => 'Autorizó',
    'paid_by' => 'Pagó',
    'received_by' => 'Recibió',
];
