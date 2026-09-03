<?php

namespace Database\Seeders\Perfiles;

/**
 * Taller / servicio técnico: órdenes de servicio sobre equipos.
 *
 * El esquema es el mismo —el expediente sigue teniendo origen, destino y medio—
 * porque los campos del expediente todavía no se han generalizado (punto 2 del
 * roadmap). Lo que cambia son los datos, y las etiquetas las cambia el
 * vocabulario:
 *
 *   MARCA_VOCABULARIO=servicios DEMO_PERFIL=servicios
 *
 * Los dos van juntos: sin el vocabulario la pantalla seguiría diciendo
 * «Bookings» y «Buque» encima de datos de taller.
 */
class PerfilServicios extends PerfilDemo
{
    public function nombre(): string
    {
        return 'órdenes de servicio';
    }

    public function prefijoExpediente(): string
    {
        return 'OS-';
    }

    public function companias(): array
    {
        return [
            ['nombre' => 'DEMO-SRV', 'razon' => 'Servicios Industriales Demo'],
            ['nombre' => 'DEMO-REF', 'razon' => 'Refacciones y Equipos Demo'],
        ];
    }

    public function bancos(): array
    {
        return ['Banco Demo MXN', 'Banco Demo USD', 'Caja Demo'];
    }

    public function tiposDeCargo(): array
    {
        return [
            ['nombre' => 'Mano de obra', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Servicio a domicilio', 'iva' => 0.16, 'retencion' => 0.04, 'no_deducible' => false],
            ['nombre' => 'Refacciones', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Diagnóstico', 'iva' => 0.16, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Garantía', 'iva' => 0.0, 'retencion' => 0.0, 'no_deducible' => false],
            ['nombre' => 'Gastos del cliente', 'iva' => 0.0, 'retencion' => 0.0, 'no_deducible' => true],
        ];
    }

    public function unidades(): array
    {
        return ['Compresor', 'Generador', 'Montacargas', 'Bomba industrial', 'Torno'];
    }

    public function origenes(): array
    {
        return ['Taller Norte', 'Taller Centro', 'Taller Sur', 'Taller Bajío'];
    }

    public function destinos(): array
    {
        return ['Planta Monterrey', 'Planta Querétaro', 'Planta Guadalajara', 'Mina Sonora', 'Puerto Manzanillo', 'Campo Tabasco'];
    }

    public function lugaresRecoleccion(): array
    {
        return ['Almacén Norte', 'Almacén Centro', 'Patio Sur', 'Recepción Bajío'];
    }

    public function coordenadas(): array
    {
        return [
            'Taller Norte' => [25.686, -100.316],
            'Taller Centro' => [19.432, -99.133],
            'Taller Sur' => [16.753, -93.115],
            'Taller Bajío' => [20.588, -100.389],
            'Planta Monterrey' => [25.790, -100.180],
            'Planta Querétaro' => [20.610, -100.180],
            'Planta Guadalajara' => [20.677, -103.347],
            'Mina Sonora' => [29.072, -110.956],
            'Puerto Manzanillo' => [19.053, -104.315],
            'Campo Tabasco' => [17.989, -92.928],
            'Almacén Norte' => [25.650, -100.290],
            'Almacén Centro' => [19.390, -99.170],
            'Patio Sur' => [16.700, -93.100],
            'Recepción Bajío' => [20.550, -100.400],
        ];
    }

    public function modalidades(): array
    {
        return ['Correctivo', 'Preventivo', 'Garantía', 'Instalación'];
    }

    public function medios(): array
    {
        return [];
    }

    public function prefijoMedio(): string
    {
        return 'MÁQUINA ';
    }

    public function clientes(): array
    {
        return [
            'Cementos del Bajío', 'Embotelladora Regional', 'Minera del Noroeste',
            'Agroindustrias del Valle', 'Papelera Continental', 'Plásticos Técnicos',
            'Refresquera Nacional', 'Frigoríficos del Pacífico',
        ];
    }

    public function proveedores(): array
    {
        return [
            ['nombre' => 'Refacciones Originales', 'tipo' => 1],
            ['nombre' => 'Importadora de Partes', 'tipo' => 1],
            ['nombre' => 'Distribuidora Industrial', 'tipo' => 1],
            ['nombre' => 'Taller Externo Norte', 'tipo' => 2],
            ['nombre' => 'Rectificaciones del Centro', 'tipo' => 2],
            ['nombre' => 'Servicios Hidráulicos', 'tipo' => 2],
            ['nombre' => 'Maniobras y Montajes', 'tipo' => 2],
            ['nombre' => 'Laboratorio de Calibración', 'tipo' => 3],
            ['nombre' => 'Certificaciones Industriales', 'tipo' => 3],
            ['nombre' => 'Ingeniería y Peritajes', 'tipo' => 3],
        ];
    }

    public function trabajos(): array
    {
        return [
            'Mantenimiento mayor', 'Cambio de rodamientos', 'Rectificación de cabeza',
            'Cambio de aceite y filtros', 'Reparación hidráulica', 'Ajuste de motor',
            'Calibración', 'Sustitución de bomba', 'Reparación eléctrica', 'Instalación en sitio',
        ];
    }

    public function documentos(): array
    {
        return [
            'entrusts_letter_file' => 'Orden de trabajo firmada',
            'guarranty_file' => 'Póliza de garantía',
            'payments_file' => 'Comprobante de pago',
            'empty_maneuver_file' => 'Entrada al taller',
            'maneuver_full_file' => 'Salida del taller',
            'commercial_bills_file' => 'Factura del cliente',
            'petition_file' => 'Reporte de diagnóstico',
            'swb_file_attach' => 'Acta de entrega',
        ];
    }

    public function conceptos(): array
    {
        return [
            'venta_principal' => ['nombre' => 'Mano de obra', 'descripcion' => 'Mano de obra especializada', 'cargo' => 1],
            'costo_principal' => ['nombre' => 'Costo refacciones', 'descripcion' => 'Refacciones del servicio', 'cargo' => 3],
            'acarreo' => ['nombre' => 'Traslado de equipo', 'descripcion' => 'Traslado del equipo al taller', 'cargo' => 2],
            'tramite' => ['nombre' => 'Peritaje', 'descripcion' => 'Peritaje y certificación', 'cargo' => 4],
            'maniobras' => ['nombre' => 'Maniobras de montaje', 'descripcion' => 'Montaje y desmontaje del equipo', 'cargo' => 3],
            'sueltos' => [
                ['nombre' => 'Diagnóstico', 'cargo' => 4, 'tipo' => 0, 'precio' => 950, 'divisa' => 1],
                ['nombre' => 'Calibración', 'cargo' => 1, 'tipo' => 0, 'precio' => 1250, 'divisa' => 1],
                ['nombre' => 'Almacenaje en taller', 'cargo' => 3, 'tipo' => 0, 'precio' => 780, 'divisa' => 1],
                ['nombre' => 'Servicio en sitio', 'cargo' => 2, 'tipo' => 0, 'precio' => 1400, 'divisa' => 1],
            ],
            'terceros' => 'Gastos por cuenta del cliente',
        ];
    }
}
