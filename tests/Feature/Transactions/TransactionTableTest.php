<?php

namespace Tests\Feature\Transactions;

use App\Livewire\Transactions\TransactionTable;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * La pantalla: que dibuje, que filtre, que ordene y que pagine sin recargar.
 *
 * Corre contra la base real porque el valor está en ver el listado con volumen
 * de verdad; por eso va en el grupo `parity`.
 */
#[Group('parity')]
class TransactionTableTest extends LegacyDatabaseTestCase
{
    private function actAsUser(): void
    {
        $this->actingAs($this->userWithRole([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN]));
    }

    /**
     * Un usuario INTERNO y ACTIVO con (o sin) el rol: a los dados de baja el
     * sistema los saca con una redirección antes de llegar a la pantalla, y a
     * los del portal los manda a su portal. Se le fija el idioma en español
     * porque lo que se afirma son textos, y el primer administrador de la base
     * local tiene guardado el inglés.
     *
     * @param  int[]  $roles
     */
    private function userWithRole(array $roles, bool $matching = true): User
    {
        $user = User::query()
            ->when($matching, fn ($q) => $q->whereIn('role', $roles), fn ($q) => $q->whereNotIn('role', $roles))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 1))
            ->where(fn ($q) => $q->whereNull('access')->orWhere('access', User::ACCESS_INTERNAL))
            ->orderBy('usr_id')
            ->first();

        if (! $user) {
            $this->markTestSkipped('La base local no tiene un usuario activo con el rol necesario.');
        }

        return $user->setRelation('preference', (new UserPreference)->forceFill(['locale' => 'es']));
    }

    public function test_la_facturacion_es_solo_para_administradores(): void
    {
        // En Yii2 el TransactionController exige isUserAdmin() en todas sus
        // acciones. Sin esta puerta, cualquier cuenta con sesión —clientes,
        // proveedores, operación— vería la facturación completa de la empresa.
        $noAdmin = $this->userWithRole([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], matching: false);

        $this->assertFalse($noAdmin->isAdmin(), 'El usuario de prueba no debería ser administrador.');

        $this->actingAs($noAdmin);

        foreach (['transactions.invoice', 'transactions.bill', 'transactions.all'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_el_menu_no_ofrece_facturacion_a_quien_no_puede_entrar(): void
    {
        $this->actingAs($this->userWithRole([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], matching: false));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('transactions.invoice'))
            ->assertDontSee(route('transactions.all'));
    }

    public function test_el_administrador_si_entra(): void
    {
        $admin = $this->userWithRole([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN]);

        $this->assertTrue($admin->isAdmin());

        $this->actingAs($admin);
        $this->get(route('transactions.invoice'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertSee(route('transactions.invoice'));
    }

    public function test_cada_ruta_abre_su_pantalla(): void
    {
        $this->actAsUser();

        $booking = DB::table('transaction')
            ->join('booking', 'booking.booking_id', '=', 'transaction.booking')
            ->where('booking.mode', 10)
            ->value('transaction.booking');

        // El `screen` viaja como valor por omisión de la ruta hasta el mount()
        // del componente; si eso se rompiera, las tres rutas mostrarían lo mismo.
        $this->get(route('transactions.invoice'))->assertOk()->assertSee(__('Facturas'));
        $this->get(route('transactions.bill'))->assertOk()->assertSee('<title>'.__('Costos'), false);
        $this->get(route('transactions.all'))->assertOk()->assertSee('<title>'.__('Todas las transacciones'), false);
        $this->get(route('transactions.booking', $booking))
            ->assertOk()
            ->assertSee('<title>'.__('Transacciones del booking'), false);
    }

    public function test_el_listado_de_facturas_solo_trae_facturas(): void
    {
        $this->actAsUser();

        $rows = Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row->tran_type, 'La pantalla de facturas trajo un costo.');
            $this->assertSame(0, (int) $row->cancelled, 'Por omisión no deben verse canceladas.');
        }
    }

    public function test_el_listado_de_costos_solo_trae_costos(): void
    {
        $this->actAsUser();

        $rows = Livewire::test(TransactionTable::class, ['screen' => 'bill'])->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows as $row) {
            $this->assertContains((int) $row->tran_type, [1, 2]);
        }
    }

    public function test_filtrar_reduce_el_total_y_regresa_a_la_primera_pagina(): void
    {
        $this->actAsUser();

        $component = Livewire::test(TransactionTable::class, ['screen' => 'all']);
        $sinFiltro = $component->viewData('rows')->total();

        $component->set('paid', '0');
        $conFiltro = $component->viewData('rows')->total();

        $this->assertLessThan($sinFiltro, $conFiltro, 'El filtro de pago no redujo el conjunto.');
        $this->assertSame(1, $component->viewData('rows')->currentPage());
    }

    public function test_ordenar_por_una_columna_alterna_la_direccion(): void
    {
        $this->actAsUser();

        Livewire::test(TransactionTable::class, ['screen' => 'invoice'])
            ->call('sortBy', 'tran_date')
            ->assertSet('sort', 'tran_date')
            ->assertSet('direction', 'desc')
            ->call('sortBy', 'tran_date')
            ->assertSet('direction', 'asc');
    }

    public function test_las_canceladas_solo_aparecen_si_se_piden(): void
    {
        $this->actAsUser();

        $component = Livewire::test(TransactionTable::class, ['screen' => 'all'])->set('showCancelled', '2');

        foreach ($component->viewData('rows') as $row) {
            $this->assertSame(1, (int) $row->cancelled);
        }
    }

    public function test_limpiar_filtros_deja_la_pantalla_como_al_entrar(): void
    {
        $this->actAsUser();

        Livewire::test(TransactionTable::class, ['screen' => 'all'])
            ->set('tranNumber', 'F-1')
            ->set('paid', '1')
            ->set('showCancelled', '2')
            ->call('clearFilters')
            ->assertSet('tranNumber', '')
            ->assertSet('paid', '')
            ->assertSet('showCancelled', '0');
    }

    public function test_los_filtros_se_pueden_plegar_y_avisan_cuantos_hay_puestos(): void
    {
        $this->actAsUser();

        // Plegables: en pantalla angosta (o dentro de un iframe) los filtros
        // tapaban la tabla, que es justo lo que hay que ver.
        $componente = Livewire::test(TransactionTable::class, ['screen' => 'invoice']);

        $componente->assertSee('x-data="{ abierto: window.innerWidth >= 1024 }"', false)
            ->assertSee('x-on:click="abierto = !abierto"', false)
            ->assertSee(__('Filtros'));

        // El listado arranca acotado al año en curso, así que ya hay un filtro
        // puesto; con dos más, el contador dice 3.
        $componente->assertSeeHtml('text-brand">1</span>')
            ->set('tranNumber', 'F-1')
            ->set('paid', '0')
            ->assertSeeHtml('text-brand">3</span>');
    }

    public function test_la_pantalla_de_un_booking_solo_trae_ese_booking(): void
    {
        $this->actAsUser();

        $booking = DB::table('transaction')
            ->join('booking', 'booking.booking_id', '=', 'transaction.booking')
            ->where('booking.mode', 10)
            ->value('transaction.booking');

        $rows = Livewire::test(TransactionTable::class, ['screen' => 'booking', 'booking' => $booking])
            ->viewData('rows');

        $this->assertGreaterThan(0, $rows->total());

        foreach ($rows as $row) {
            $this->assertSame((int) $booking, (int) $row->booking);
        }
    }

    public function test_la_pantalla_de_un_booking_muestra_su_profit(): void
    {
        $this->actAsUser();

        $booking = DB::table('transaction')
            ->join('booking', 'booking.booking_id', '=', 'transaction.booking')
            ->where('booking.mode', 10)
            ->where('transaction.tran_type', 0)
            ->value('transaction.booking');

        Livewire::test(TransactionTable::class, ['screen' => 'booking', 'booking' => $booking])
            ->assertSee(__('Profit del booking'));
    }

    /** Como el original: un booking sin facturas también dice cuánto se lleva perdido. */
    public function test_el_profit_sale_aunque_el_booking_solo_tenga_costos(): void
    {
        $this->actAsUser();

        $booking = DB::table('transaction')
            ->whereNotIn('booking', DB::table('transaction')->where('tran_type', 0)->select('booking'))
            ->where('tran_type', 1)
            ->value('booking');

        Livewire::test(TransactionTable::class, ['screen' => 'booking', 'booking' => $booking])
            ->assertSee(__('Profit del booking'))
            ->assertSee(__('s/facturas'));
    }

    /** El profit del booking se reparte entre sus facturas según el subtotal de cada una. */
    public function test_el_profit_se_prorratea_entre_las_facturas_del_booking(): void
    {
        $this->actAsUser();

        $booking = DB::table('transaction')->where('tran_type', 0)->value('booking');

        $pantalla = Livewire::test(TransactionTable::class, ['screen' => 'booking', 'booking' => $booking]);
        $profit = $pantalla->viewData('bookingProfit');

        $this->assertEqualsWithDelta(
            $profit['profit_doc'],
            collect($pantalla->viewData('rows')->items())->sum(fn ($row) => $pantalla->instance()->invoiceProfit($row, $profit) ?? 0),
            0.01,
        );
    }

    /**
     * Livewire vuelve a fijar la vista del paginador en cada render, así que la
     * propia solo se aplica si el componente la declara. Sin esto salía la vista
     * que trae Laravel, con grises fijos que en tema oscuro no se leen.
     */
    public function test_el_listado_usa_el_paginador_propio(): void
    {
        $this->actAsUser();

        $this->get(route('transactions.invoice'))
            ->assertOk()
            ->assertSee(__('Página 1 de'))
            ->assertDontSee('dusk="nextPage.before"', false);
    }

    /**
     * Los filtros viven en la dirección, así que un enlace compartido abre la
     * tabla ya filtrada. Si la vista no pintara los valores desde el servidor,
     * los campos se verían vacíos hasta que arrancara el JavaScript y no habría
     * forma de saber por qué la lista viene acotada.
     */
    public function test_los_filtros_de_la_direccion_se_pintan_desde_el_servidor(): void
    {
        $this->actAsUser();

        $compania = DB::table('company')->orderBy('company_id')->value('company_id');

        $this->get(route('transactions.invoice', [
            'num' => 'F-14793',
            'f' => '01/12/2022 - 31/12/2022',
            'co' => $compania,
            'pago' => '1',
            'n' => 100,
        ]))
            ->assertOk()
            ->assertSee('value="F-14793"', false)
            ->assertSee('value="01/12/2022 - 31/12/2022"', false)
            ->assertSee('<option value="'.$compania.'" selected>', false)
            ->assertSee('<option value="1" selected>'.__('Pagadas').'</option>', false)
            ->assertSee('<option value="100" selected>100</option>', false);
    }
}
