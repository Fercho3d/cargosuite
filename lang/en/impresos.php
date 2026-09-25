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
    'confirmation_title' => 'Booking confirmation',
    'quotation_title' => 'Booking Quotation',
    'payment_request_title' => 'Payment request',
    'collection_request_title' => 'Collection request',
    'page' => 'Page',
    'folio' => 'No.',
    'date' => 'Date',
    'customer' => 'Customer',
    'pay_to' => 'Pay to',
    'bank_account' => 'Account',
    'amount_in_words' => 'Amount in words',
    'documents_covered' => 'Documents covered',
    'subtotal_0' => 'Subtotal 0%',
    'subtotal_16' => 'Subtotal 16%',
    'vat_16' => 'VAT 16%',
    'documents_count' => '{1} :n document|[2,*] :n documents',
    'prepared_by' => 'Prepared by',
    'authorized_by' => 'Authorized by',
    'paid_by' => 'Paid by',
    'received_by' => 'Received by',
];
