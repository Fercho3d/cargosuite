<?php

namespace Database\Seeders\Perfiles;

/**
 * Agente de carga: el negocio para el que nació el sistema.
 *
 * Es el perfil por omisión y los datos son los que ya traía `DemoSeeder`.
 */
class PerfilCarga extends PerfilDemo
{
    public function nombre(): string
    {
        return 'agente de carga';
    }

    public function prefijoExpediente(): string
    {
        return 'DEMO-';
    }

    public function companias(): array
    {
        return [
            ['nombre' => 'DEMO-INT', 'razon' => 'CargoSuite Demo Internacional'],
            ['nombre' => 'DEMO-MAR', 'razon' => 'CargoSuite Demo Marítima'],
        ];
    }

    public function bancos(): array
    {
        return ['Banco Demo MXN', 'Banco Demo USD', 'Banco Demo EUR'];
    }

    public function tiposDeCargo(): array
    {
        return [
            ['nombre' => 'Flete marítimo', 'iva' => 0.0, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Flete terrestre nacional', 'iva' => 0.16, 'retencion' => 0.04, 'no_deducible' => false],
            ['nombre' => 'Maniobras y handling', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Despacho aduanal', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Documentación', 'iva' => 0.0, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Pagos de terceros', 'iva' => 0.0, 'retencion' => 0.0, 'no_deducible' => true],
        ];
    }

    public function unidades(): array
    {
        return ["20'DRY", "40'DRY", "40'HC", "20'REEFER", "40'REEFER"];
    }

    public function origenes(): array
    {
        return ['Puerto Norte', 'Puerto Centro', 'Puerto Sur', 'Puerto Golfo'];
    }

    public function destinos(): array
    {
        return ['Rotterdam', 'Hamburgo', 'Shanghái', 'Long Beach', 'Algeciras', 'Santos'];
    }

    public function lugaresRecoleccion(): array
    {
        return ['Bodega Norte', 'Bodega Centro', 'Planta Sur', 'Empaque Golfo'];
    }

    public function coordenadas(): array
    {
        return [
            // Puertos mexicanos reales, con nombre inventado.
            'Puerto Norte' => [22.389, -97.925],
            'Puerto Centro' => [19.053, -104.315],
            'Puerto Sur' => [17.955, -102.196],
            'Puerto Golfo' => [19.203, -96.135],
            'Rotterdam' => [51.949, 4.143],
            'Hamburgo' => [53.548, 9.987],
            'Shanghái' => [31.231, 121.474],
            'Long Beach' => [33.754, -118.189],
            'Algeciras' => [36.133, -5.452],
            'Santos' => [-23.960, -46.333],
            'Bodega Norte' => [25.686, -100.316],
            'Bodega Centro' => [20.588, -100.389],
            'Planta Sur' => [19.432, -99.133],
            'Empaque Golfo' => [18.851, -97.098],
        ];
    }

    public function modalidades(): array
    {
        return ['CY - CY', 'CY - SD', 'SD - CY', 'SD - SD'];
    }

    public function medios(): array
    {
        return [];
    }

    public function prefijoMedio(): string
    {
        return 'MV DEMO ';
    }

    public function clientes(): array
    {
        return [
            'Agroexportadora del Valle', 'Frutas y Vegetales del Norte', 'Minerales Industriales',
            'Textiles Continentales', 'Bebidas y Conservas', 'Química Aplicada',
            'Automotriz Componentes', 'Alimentos Congelados del Pacífico',
        ];
    }

    public function proveedores(): array
    {
        return [
            ['nombre' => 'Naviera Global Lines', 'tipo' => 1],
            ['nombre' => 'Ocean Demo Carriers', 'tipo' => 1],
            ['nombre' => 'Blue Container Line', 'tipo' => 1],
            ['nombre' => 'Transportes Terrestres Demo', 'tipo' => 2],
            ['nombre' => 'Autotransportes del Centro', 'tipo' => 2],
            ['nombre' => 'Fletes Peninsulares', 'tipo' => 2],
            ['nombre' => 'Logística Terrestre Norte', 'tipo' => 2],
            ['nombre' => 'Agencia Aduanal Demo', 'tipo' => 3],
            ['nombre' => 'Despachos Aduanales Unidos', 'tipo' => 3],
            ['nombre' => 'Comercio Exterior Asesores', 'tipo' => 3],
        ];
    }

    public function trabajos(): array
    {
        return [
            'Aguacate fresco', 'Berries congelados', 'Concentrado mineral', 'Tela de algodón',
            'Conservas en lata', 'Resina industrial', 'Autopartes', 'Camarón congelado',
            'Café verde en grano', 'Tequila envasado',
        ];
    }

    public function documentos(): array
    {
        return [
            'entrusts_letter_file' => 'Carta encomienda',
            'guarranty_file' => 'Garantía',
            'payments_file' => 'Comprobante de pago',
            'empty_maneuver_file' => 'Maniobra vacío',
            'maneuver_full_file' => 'Maniobra lleno',
            'commercial_bills_file' => 'Factura comercial',
            'petition_file' => 'Pedimento',
            'swb_file_attach' => 'SWB / Draft',
        ];
    }

    public function conceptos(): array
    {
        return [
            'venta_principal' => ['nombre' => 'Flete marítimo', 'descripcion' => 'Flete marítimo puerto a puerto', 'cargo' => 1],
            'costo_principal' => ['nombre' => 'Costo flete marítimo', 'descripcion' => 'Flete marítimo puerto a puerto', 'cargo' => 1],
            'acarreo' => ['nombre' => 'Flete terrestre', 'descripcion' => 'Acarreo de bodega a puerto', 'cargo' => 2],
            'tramite' => ['nombre' => 'Despacho aduanal', 'descripcion' => 'Despacho aduanal de exportación', 'cargo' => 4],
            'maniobras' => ['nombre' => 'Maniobras en puerto', 'descripcion' => 'Maniobras de carga y estiba', 'cargo' => 3],
            'sueltos' => [
                ['nombre' => 'Documentación y BL', 'cargo' => 5, 'tipo' => 0, 'precio' => 950, 'divisa' => 2],
                ['nombre' => 'Certificados', 'cargo' => 5, 'tipo' => 0, 'precio' => 1250, 'divisa' => 1],
                ['nombre' => 'Almacenaje adicional', 'cargo' => 3, 'tipo' => 0, 'precio' => 780, 'divisa' => 1],
                ['nombre' => 'Monitoreo reefer', 'cargo' => 3, 'tipo' => 0, 'precio' => 1400, 'divisa' => 2],
            ],
            'terceros' => 'Pagos por cuenta de tercero',
        ];
    }
}
