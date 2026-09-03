<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Booking;
use App\Support\Billing\BillingBlock;
use App\Support\Billing\ServiceCandidate;
use App\Support\Billing\ServiceMatcher;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Por qué atributos empareja el catálogo de precios con el expediente.
 *
 * El emparejador nació para rutas marítimas —puerto de carga, puerto de
 * descarga, destino final, lugar de recolección— y un negocio que cobre por otra
 * cosa necesita apagar las que no usa; si no, sus precios quedan atados a una
 * geografía que no tiene.
 */
class DimensionesEmparejadorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno', 'match_pickup_place' => 0]]);
        DB::table('charge_type')->insert([[
            'charge_type_id' => 1, 'charge_type_name' => 'Servicio', 'tax_rate' => 0.16,
            'tax_retention' => 0, 'non_deductible' => 0,
        ]]);

        // Y un expediente que va a OTRO puerto de descarga.
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0,
            'loading_port' => 1, 'dicharge_port_id' => 7, 'final_destination_id' => null,
            'pick_up_place_id' => 1,
        ]]);

        // El emparejador une contra los contenedores del expediente: sin tipo de
        // contenedor y sin contenedores, NADA empata nunca — y una prueba de «no
        // empata» pasaría por el motivo equivocado.
        DB::table('container_types')->insert([['contType_id' => 1, 'container_name' => "40'HC"]]);
        DB::table('containers')->insert([[
            'container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 2,
            'comodity' => 'Carga', 'created_by' => 1, 'modified_by' => 1,
            'created_at' => '2026-05-01', 'modified_at' => '2026-05-01',
        ]]);

        // Un precio del cliente atado a UN puerto de descarga concreto.
        DB::table('service')->insert([[
            'service_id' => 1, 'description' => 'Servicio base',
            'price' => 1000, 'account_id' => 1, 'charge_type_id' => 1, 'type' => 0,
            'active' => 1, 'auto_include' => 1, 'client_id' => 1,
            'loading_port_id' => 1, 'dicharge_port_id' => 99, 'final_destination_id' => null,
            'pickup_place_id' => null, 'container_type_id' => 1,
        ]]);

    }

    /** @return list<string> descripciones de los servicios que empataron */
    private function empatados(): array
    {
        $candidatos = app(ServiceMatcher::class)->forBlock(Booking::findOrFail(1), BillingBlock::Invoice);

        return array_map(fn (ServiceCandidate $c) => $c->description, $candidatos);
    }

    public function test_por_omision_el_puerto_de_descarga_manda(): void
    {
        config(['marca.emparejador_dimensiones' => '']);

        $this->assertSame([], $this->empatados(), 'El precio es de otro puerto: no debería empatar.');
    }

    /**
     * Apagando esa dimensión, el mismo precio sirve para cualquier destino. Es
     * lo que necesita un negocio que cobra por el trabajo y no por la ruta.
     */
    public function test_apagando_la_dimension_el_precio_deja_de_estar_atado_al_puerto(): void
    {
        config(['marca.emparejador_dimensiones' => 'puerto_carga']);

        $this->assertSame(['Servicio base'], $this->empatados());
    }

    /** El cliente NO se puede apagar: su precio no puede saltar a otro cliente. */
    public function test_el_cliente_sigue_mandando_aunque_se_apaguen_las_rutas(): void
    {
        config(['marca.emparejador_dimensiones' => 'puerto_carga']);

        DB::table('booking')->where('booking_id', 1)->update(['client' => 2]);
        DB::table('client')->insert([['client_id' => 2, 'fullName' => 'Cliente Dos', 'match_pickup_place' => 0]]);

        $this->assertSame([], $this->empatados(), 'Un precio saltó de un cliente a otro.');
    }
}
