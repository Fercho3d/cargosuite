<?php

use App\Http\Controllers\BookingConfirmationController;
use App\Http\Controllers\BookingFileController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PaymentRequestDocumentController;
use App\Http\Controllers\PortalFileController;
use App\Http\Controllers\ServiceContractController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\TransactionExportController;
use App\Http\Controllers\TransactionFileController;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsInternal;
use App\Http\Middleware\EnsureUserIsPortal;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Livewire\Catalogs\CatalogManager;
use App\Livewire\Dashboard;
use App\Livewire\DemoRequests;
use App\Livewire\Exchange\ExchangeManager;
use App\Livewire\Home;
use App\Livewire\Notifications;
use App\Livewire\Operations\BillingGenerator;
use App\Livewire\Operations\BookingDetail;
use App\Livewire\Operations\BookingForm;
use App\Livewire\Operations\BookingHistory;
use App\Livewire\Operations\BookingList;
use App\Livewire\Operations\ContinuityReport;
use App\Livewire\Parties\PartyForm;
use App\Livewire\Parties\PartyManager;
use App\Livewire\Payments\PaymentRequestDetail;
use App\Livewire\Payments\PaymentRequestForm;
use App\Livewire\Payments\PaymentRequestList;
use App\Livewire\Payments\PaymentsReport;
use App\Livewire\Payments\PayrollManager;
use App\Livewire\Payments\SettlementManager;
use App\Livewire\Portal\PortalDocument;
use App\Livewire\Portal\PortalHome;
use App\Livewire\Services\ServiceForm;
use App\Livewire\Services\ServiceManager;
use App\Livewire\Settings;
use App\Livewire\Transactions\BookingReport;
use App\Livewire\Transactions\TransactionDetail;
use App\Livewire\Transactions\TransactionForm;
use App\Livewire\Transactions\TransactionTable;
use App\Livewire\Users\UserManager;
use App\Livewire\Workshop\InventoryManager;
use App\Livewire\Workshop\MaintenanceManager;
use Illuminate\Support\Facades\Route;

// La raíz es la página pública. Quien ya tiene sesión no la ve: `Home::mount()`
// lo manda a su sitio —el personal al sistema, el cliente y el proveedor a su
// portal—, que es lo que hacía antes esta ruta para todo el mundo.
Route::get('/', Home::class)->name('home');

// Tema claro/oscuro. Sin `auth` a propósito: la pantalla de acceso también
// deja elegirlo (se recuerda por cookie hasta que haya sesión).
Route::put('/preferencias/tema', ThemeController::class)->name('preferences.theme');

// Idioma de la interfaz, con la misma regla que el tema.
Route::put('/preferencias/idioma', LocaleController::class)->name('preferences.locale');

// Seguridad de la cuenta (contraseña + 2FA). Va fuera de los dos bloques porque
// es de cualquiera con sesión, incluidas las cuentas de portal. Las acciones las
// expone Laravel Fortify.
Route::view('/seguridad', 'security.show')->middleware('auth')->name('security.show');

// Los códigos de recuperación del 2FA solo se enseñan tras volver a confirmar
// la contraseña, como hace Jetstream: una sesión abierta en una computadora
// ajena no debe bastar para copiarlos.
Route::view('/seguridad/codigos-de-recuperacion', 'security.recovery-codes')
    ->middleware(['auth', 'password.confirm'])->name('security.recovery-codes');

/*
 * Portal de clientes y proveedores. Cada cuenta ve únicamente sus documentos y,
 * si es cliente, sus embarques.
 */
Route::middleware(['auth', EnsureUserIsPortal::class])->prefix('portal')->group(function () {
    Route::get('/', PortalHome::class)->name('portal');
    Route::get('/documento/{transaction}', PortalDocument::class)
        ->whereNumber('transaction')->name('portal.document');
    Route::get('/documento/{transaction}/{kind}', PortalFileController::class)
        ->whereNumber('transaction')->name('portal.file');
});

/*
 * Sistema interno. `EnsureUserIsInternal` deja fuera a las cuentas de portal:
 * sin esa puerta verían la operación completa de la empresa.
 */
Route::middleware(['auth', EnsureUserIsInternal::class])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/avisos', Notifications::class)->name('notifications');

    /*
     * Operación: los embarques y lo que cuelga de ellos.
     */
    Route::prefix('operacion')->name('operations.')->group(function () {
        Route::get('/bookings', BookingList::class)->name('bookings');
        Route::get('/continuidad', ContinuityReport::class)->name('continuity');
        Route::get('/bookings/nuevo', BookingForm::class)->name('bookings.create');
        Route::get('/bookings/{booking}/editar', BookingForm::class)->whereNumber('booking')->name('bookings.edit');
        Route::get('/bookings/{booking}/generar', BillingGenerator::class)->whereNumber('booking')->name('bookings.generate');
        Route::get('/bookings/{booking}', BookingDetail::class)->whereNumber('booking')->name('bookings.show');
        Route::get('/bookings/{booking}/historial', BookingHistory::class)
            ->whereNumber('booking')->name('bookings.history');
        Route::get('/bookings/{booking}/confirmacion.pdf', BookingConfirmationController::class)
            ->whereNumber('booking')->name('bookings.pdf');
        Route::get('/bookings/{booking}/documento/{nombre}', BookingFileController::class)
            ->whereNumber('booking')->name('bookings.file');
    });

    /*
     * Lo que es solo del dueño del software: los usuarios (igual que el
     * `UserController` de Yii2), las solicitudes de demostración y los ajustes
     * de la instalación (qué mueve la empresa, si factura con CFDI…). Un
     * administrador normal recibe 403.
     */
    Route::middleware(EnsureUserIsSuperAdmin::class)->group(function () {
        Route::get('/usuarios', UserManager::class)->name('users');
        Route::get('/solicitudes-demo', DemoRequests::class)->name('demo-requests');
        Route::get('/ajustes', Settings::class)->name('settings');
    });

    /*
     * Clientes y proveedores. No son catálogos planos: llevan datos fiscales y de
     * facturación, y los del cliente son los que viajan al CFDI.
     */
    Route::middleware(EnsureUserIsAdmin::class)->prefix('terceros')->name('parties.')->group(function () {
        Route::get('/clientes', PartyManager::class)->defaults('mode', 'client')->name('clients');
        Route::get('/proveedores', PartyManager::class)->defaults('mode', 'provider')->name('providers');
        Route::get('/clientes/nuevo', PartyForm::class)->defaults('mode', 'client')->name('clients.create');
        Route::get('/clientes/{party}/editar', PartyForm::class)->defaults('mode', 'client')->whereNumber('party')->name('clients.edit');
        Route::get('/proveedores/nuevo', PartyForm::class)->defaults('mode', 'provider')->name('providers.create');
        Route::get('/proveedores/{party}/editar', PartyForm::class)->defaults('mode', 'provider')->whereNumber('party')->name('providers.edit');
        Route::get('/servicios', ServiceManager::class)->name('services');
        Route::get('/servicios/nuevo', ServiceForm::class)->name('services.create');
        Route::get('/servicios/{service}/editar', ServiceForm::class)->whereNumber('service')->name('services.edit');
        Route::get('/servicios/{service}/contrato', ServiceContractController::class)->whereNumber('service')->name('services.contract');
    });

    /*
     * Catálogos maestros. Una sola pantalla para los dieciséis: lo que cambia
     * entre ellos son los campos, y esos viven en `CatalogRegistry`.
     */
    Route::middleware(EnsureUserIsAdmin::class)
        ->get('/catalogos/{catalog}', CatalogManager::class)
        ->name('catalogs.show');

    /*
     * Tipos de cambio. Va aparte de los catálogos porque no es un catálogo: de
     * aquí sale con cuánto se valúa cada documento.
     */
    Route::middleware(EnsureUserIsAdmin::class)
        ->get('/tipos-de-cambio', ExchangeManager::class)
        ->name('exchange');

    /*
     * Reportes de cobros y pagos. Viven aparte de las transacciones porque su
     * origen es otro: la tabla de solicitudes de pago, no la de documentos.
     */
    Route::middleware(EnsureUserIsAdmin::class)->prefix('pagos')->name('payments.')->group(function () {
        Route::get('/solicitudes', PaymentRequestList::class)->name('requests');
        // Liquidaciones de operadores: solo tienen sentido con flota propia.
        Route::get('/liquidaciones', SettlementManager::class)->name('settlements');
        Route::get('/nomina', PayrollManager::class)->name('payroll');
        Route::get('/solicitudes/nueva', PaymentRequestForm::class)->name('requests.create');
        Route::get('/solicitudes/{request}/documento.pdf', PaymentRequestDocumentController::class)
            ->whereNumber('request')->name('requests.document');
        Route::get('/solicitudes/{request}', PaymentRequestDetail::class)
            ->whereNumber('request')->name('requests.show');
        Route::get('/reporte/clientes', PaymentsReport::class)->defaults('mode', 'customer')->name('report.customer');
        Route::get('/reporte/proveedores', PaymentsReport::class)->defaults('mode', 'vendor')->name('report.vendor');
        Route::get('/reporte/general', PaymentsReport::class)->defaults('mode', 'general')->name('report.general');
    });

    /*
     * El taller: mantenimiento de la flota y su almacén de refacciones. Solo con
     * flota propia; el menú no los enseña sin ella (`MARCA_TALLER`).
     */
    Route::middleware(EnsureUserIsAdmin::class)->prefix('taller')->name('workshop.')->group(function () {
        Route::get('/mantenimiento', MaintenanceManager::class)->name('maintenance');
        Route::get('/almacen', InventoryManager::class)->name('inventory');
    });

    /*
     * Módulo Transactions. Los cuatro listados son el mismo componente Livewire
     * con distinto filtro base, igual que las acciones invoice / bill / all /
     * index del TransactionController de Yii2.
     *
     * Solo administradores, igual que el AccessControl del controlador original:
     * la facturación no la ven clientes, proveedores ni operación.
     */
    Route::middleware(EnsureUserIsAdmin::class)->prefix('transacciones')->name('transactions.')->group(function () {
        Route::get('/', TransactionTable::class)->name('invoice');
        Route::get('/costos', TransactionTable::class)->defaults('screen', 'bill')->name('bill');
        Route::get('/todas', TransactionTable::class)->defaults('screen', 'all')->name('all');
        Route::get('/booking/{booking}', TransactionTable::class)->defaults('screen', 'booking')->name('booking');
        Route::get('/nueva', TransactionForm::class)->name('create');
        Route::get('/reporte/booking', BookingReport::class)->name('report.booking');
        Route::get('/exportar/{screen}', TransactionExportController::class)->name('export');
        Route::get('/{transaction}', TransactionDetail::class)->whereNumber('transaction')->name('show');
        Route::get('/{transaction}/editar', TransactionForm::class)->whereNumber('transaction')->name('edit');
        Route::get('/{transaction}/archivo/{kind}', TransactionFileController::class)
            ->whereNumber('transaction')->name('file');
    });
});
