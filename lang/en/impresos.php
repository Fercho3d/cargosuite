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
    'amount' => 'Amount',
    'arrival_date' => 'Arrival Date',
    'carrier' => 'Carrier',
    'client_information' => 'Client Information',
    'commodity' => 'Commodity',
    'container_type' => 'Container Type',
    'creation_date' => 'Creation Date',
    'currency' => 'Currency',
    'cut_off_si' => 'Cut off Date SI',
    'departure_date' => 'Departure Date',
    'destination_port' => 'Destination Port',
    'delivery_address' => 'Equipment Delivery Address',
    'final_destination' => 'Final Destination',
    'non_deductible' => 'Non Deduc',
    'number' => 'Number',
    'origin_port' => 'Origin Port',
    'pick_up_place' => 'Pick Up Place',
    'pieces' => 'Pieces',
    'port_closing' => 'Port Closing date',
    'quantity' => 'Quantity',
    'reference' => 'Reference',
    'remarks' => 'Remarks',
    'ret_vat' => 'Ret VAT',
    'seal' => 'Seal',
    'spotting_date' => 'Spotting Date',
    'total' => 'Total',
    'totals' => 'Totals',
    'memo' => 'Memo',
    'pay' => 'PAY',
    'to_the_order' => 'To the order',
    'confirmation_title' => 'Booking confirmation',
    'quotation_title' => 'Booking Quotation',
];
