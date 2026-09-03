<?php

namespace Database\Seeders\Perfiles;

/**
 * Autotransporte de carga con flota propia.
 *
 * A diferencia del agente de carga —que subcontrata y solo conoce proveedores—,
 * aquí la empresa mueve con **su gente y su equipo**: por eso este perfil es el
 * único que siembra operadores y unidades.
 *
 * Va con `MARCA_VOCABULARIO=camiones`:
 *
 *   MARCA_VOCABULARIO=camiones DEMO_PERFIL=camiones php artisan db:seed --class=DemoSeeder
 *
 * Y en el `.env` de una instalación de camiones:
 *
 *   MARCA_EXPEDIENTE_OCULTOS=vesselId
 *
 * El buque **no existe** aquí: el medio es el tractor, y ese vive en `unidad`
 * con sus placas, su seguro y su verificación. Reciclar el catálogo de buques
 * como «camiones» obligaría a mantener la flota en dos lugares.
 */
class PerfilCamiones extends PerfilDemo
{
    public function nombre(): string
    {
        return 'autotransporte de carga';
    }

    public function prefijoExpediente(): string
    {
        return 'VJ-';
    }

    public function companias(): array
    {
        return [
            ['nombre' => 'DEMO-AUT', 'razon' => 'Autotransportes Demo del Norte'],
            ['nombre' => 'DEMO-LOG', 'razon' => 'Logística Demo Integral'],
        ];
    }

    public function bancos(): array
    {
        return ['Banco Demo MXN', 'Banco Demo USD', 'Caja chica'];
    }

    public function tiposDeCargo(): array
    {
        return [
            ['nombre' => 'Flete', 'iva' => 0.16, 'retencion' => 0.04, 'no_deducible' => false],
            ['nombre' => 'Maniobras', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Combustible', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Casetas', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Estadías', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Gastos de viaje', 'iva' => 0.0, 'retencion' => 0.0, 'no_deducible' => true],
        ];
    }

    /** Tipos de unidad de carga que maneja (no la flota). */
    public function unidades(): array
    {
        return ['Caja seca 53', 'Caja refrigerada', 'Plataforma', 'Tolva', 'Tanque'];
    }

    public function unidadesFlota(): array
    {
        return [
            ['numero' => 'T-101', 'tipo' => 'tractor', 'placas' => 'AB-123-CD', 'marca' => 'Kenworth', 'anio' => '2021', 'seguro' => '+8 months'],
            ['numero' => 'T-102', 'tipo' => 'tractor', 'placas' => 'AB-124-CD', 'marca' => 'Freightliner', 'anio' => '2020', 'seguro' => '+3 months'],
            ['numero' => 'T-103', 'tipo' => 'tractor', 'placas' => 'AB-125-CD', 'marca' => 'International', 'anio' => '2022', 'seguro' => '+11 months'],
            ['numero' => 'T-104', 'tipo' => 'tractor', 'placas' => 'AB-126-CD', 'marca' => 'Kenworth', 'anio' => '2019', 'seguro' => '+20 days'],
            ['numero' => 'C-201', 'tipo' => 'caja', 'placas' => 'XY-901-ZW', 'marca' => 'Utility', 'anio' => '2020', 'seguro' => '+6 months'],
            ['numero' => 'C-202', 'tipo' => 'caja', 'placas' => 'XY-902-ZW', 'marca' => 'Great Dane', 'anio' => '2021', 'seguro' => '+9 months'],
            ['numero' => 'C-203', 'tipo' => 'caja', 'placas' => 'XY-903-ZW', 'marca' => 'Hyundai', 'anio' => '2018', 'seguro' => '+2 months'],
            ['numero' => 'C-204', 'tipo' => 'caja', 'placas' => 'XY-904-ZW', 'marca' => 'Utility', 'anio' => '2023', 'seguro' => '+14 months'],
        ];
    }

    public function operadores(): array
    {
        return [
            // Uno con la licencia por vencer a propósito: es lo que hace útil el
            // catálogo, y lo que se enseña en una demostración.
            ['nombre' => 'Miguel Ángel Ramírez', 'licencia' => 'FED-4471', 'vence' => '+18 days'],
            ['nombre' => 'José Luis Hernández', 'licencia' => 'FED-4482', 'vence' => '+7 months'],
            ['nombre' => 'Ricardo Molina', 'licencia' => 'FED-4498', 'vence' => '+14 months'],
            ['nombre' => 'Fernando Ibarra', 'licencia' => 'FED-4503', 'vence' => '+2 months'],
            ['nombre' => 'Sergio Cárdenas', 'licencia' => 'FED-4517', 'vence' => '+9 months'],
            ['nombre' => 'Alberto Quintero', 'licencia' => 'FED-4529', 'vence' => '+21 months'],
        ];
    }

    public function origenes(): array
    {
        return ['Patio Monterrey', 'Patio Guadalajara', 'Patio Querétaro', 'Patio Laredo'];
    }

    public function destinos(): array
    {
        return ['Ciudad de México', 'Monterrey', 'Guadalajara', 'Tijuana', 'Veracruz', 'Mérida'];
    }

    public function coordenadas(): array
    {
        return [
            'Patio Monterrey' => [25.686, -100.316],
            'Patio Guadalajara' => [20.677, -103.347],
            'Patio Querétaro' => [20.588, -100.389],
            'Patio Laredo' => [27.506, -99.507],
            'Ciudad de México' => [19.432, -99.133],
            'Monterrey' => [25.686, -100.316],
            'Guadalajara' => [20.677, -103.347],
            'Tijuana' => [32.514, -117.038],
            'Veracruz' => [19.203, -96.135],
            'Mérida' => [20.967, -89.624],
            'Bodega Norte' => [25.740, -100.250],
            'CEDIS Occidente' => [20.700, -103.300],
            'Planta Bajío' => [20.600, -100.400],
            'Cruce Laredo' => [27.560, -99.510],
        ];
    }

    public function lugaresRecoleccion(): array
    {
        return ['Bodega Norte', 'CEDIS Occidente', 'Planta Bajío', 'Cruce Laredo'];
    }

    public function modalidades(): array
    {
        return ['Sencillo', 'Full', 'Sencillo 3.5', 'Cruce'];
    }

    public function medios(): array
    {
        return [];
    }

    public function prefijoMedio(): string
    {
        return 'RUTA ';
    }

    public function clientes(): array
    {
        return [
            'Cementos del Bajío', 'Embotelladora Regional', 'Acero y Perfiles',
            'Agroindustrias del Valle', 'Papelera Continental', 'Vidrio Templado',
            'Refresquera Nacional', 'Alimentos del Norte',
        ];
    }

    public function proveedores(): array
    {
        return [
            ['nombre' => 'Combustibles del Norte', 'tipo' => 1],
            ['nombre' => 'Diésel Express', 'tipo' => 1],
            ['nombre' => 'Estación de Servicio Bajío', 'tipo' => 1],
            ['nombre' => 'Fletes Asociados', 'tipo' => 2],
            ['nombre' => 'Transportes Complementarios', 'tipo' => 2],
            ['nombre' => 'Grúas y Auxilio Vial', 'tipo' => 2],
            ['nombre' => 'Taller Diésel Integral', 'tipo' => 2],
            ['nombre' => 'Llantera Industrial', 'tipo' => 3],
            ['nombre' => 'Refacciones Pesadas', 'tipo' => 3],
            ['nombre' => 'Seguros de Carga', 'tipo' => 3],
        ];
    }

    public function trabajos(): array
    {
        return [
            'Cemento en sacos', 'Refresco en tarima', 'Perfil de acero', 'Grano a granel',
            'Papel en bobina', 'Vidrio plano', 'Bebida embotellada', 'Alimento procesado',
            'Material de construcción', 'Producto terminado',
        ];
    }

    public function documentos(): array
    {
        return [
            'entrusts_letter_file' => 'Carta porte',
            'guarranty_file' => 'Póliza de seguro',
            'payments_file' => 'Comprobante de pago',
            'empty_maneuver_file' => 'Entrada a patio',
            'maneuver_full_file' => 'Salida de patio',
            'commercial_bills_file' => 'Factura del cliente',
            'petition_file' => 'Evidencia de entrega',
            'swb_file_attach' => 'Acuse firmado',
        ];
    }

    public function conceptos(): array
    {
        return [
            'venta_principal' => ['nombre' => 'Flete', 'descripcion' => 'Flete de origen a destino', 'cargo' => 1],
            'costo_principal' => ['nombre' => 'Costo combustible', 'descripcion' => 'Combustible del viaje', 'cargo' => 3],
            'acarreo' => ['nombre' => 'Flete subcontratado', 'descripcion' => 'Flete con transportista externo', 'cargo' => 1],
            'tramite' => ['nombre' => 'Casetas', 'descripcion' => 'Casetas de la ruta', 'cargo' => 4],
            'maniobras' => ['nombre' => 'Maniobras', 'descripcion' => 'Maniobras de carga y descarga', 'cargo' => 2],
            'sueltos' => [
                ['nombre' => 'Estadía', 'cargo' => 5, 'tipo' => 0, 'precio' => 1800, 'divisa' => 1],
                ['nombre' => 'Custodia', 'cargo' => 2, 'tipo' => 0, 'precio' => 3500, 'divisa' => 1],
                ['nombre' => 'Falso flete', 'cargo' => 1, 'tipo' => 0, 'precio' => 2200, 'divisa' => 1],
                ['nombre' => 'Reexpedición', 'cargo' => 2, 'tipo' => 0, 'precio' => 1500, 'divisa' => 1],
            ],
            'terceros' => 'Gastos de viaje del operador',
        ];
    }
}
