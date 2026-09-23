<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Una factura mínima pero completa para las pruebas de CFDI: compañía emisora
 * con sus datos fiscales, cliente, un cargo de 2 × 1 000 con IVA del 16 % (total
 * 2 320) y la transacción que los une.
 *
 * Vive aparte porque la usan el timbrado y la cancelación, y las dos tienen que
 * ver exactamente los mismos números.
 */
class InvoiceFixture
{
    public static function seed(): void
    {
        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN']]);
        DB::table('exchange')->insert([['exchange_id' => 1, 'exchange_value' => 1, 'date_exchange' => '2026-01-15', 'account' => 1]]);
        DB::table('booking')->insert([['booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10]]);
        DB::table('company')->insert([[
            'company_id' => 1, 'name' => 'FTM', 'business_name' => 'EMPRESA DEMO SA DE CV',
            'rfc' => 'XAXX010101000', 'regimen_fiscal' => '601', 'postal_code' => '44100', 'active' => 1,
        ]]);
        DB::table('client')->insert([[
            'client_id' => 1, 'fullName' => 'Cliente Uno', 'rfc' => 'AAA010101AAA',
            'pay_form' => '03', 'pay_method' => 'PUE', 'invoice_use' => 'G03',
            'regimen_fiscal_id' => '601', 'postal_code' => '44100',
        ]]);
        DB::table('charge_type')->insert([[
            'charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_name' => 'IVA',
            'tax_rate' => 0.16, 'tax_retention' => 0, 'non_deductible' => 0, 'product_code' => '78101800',
        ]]);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'company_id' => 1,
            'account' => 1, 'tran_number' => 'F-1', 'tran_date' => '2026-01-15', 'invoice_type' => 1,
        ]]);
        DB::table('charge')->insert([[
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 2, 'price' => 1000,
            'description' => 'Flete Manzanillo',
        ]]);
    }
}
