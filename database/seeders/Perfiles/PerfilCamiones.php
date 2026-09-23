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

    /**
     * Los tres selectores del expediente, en su orden: el sembrador reparte los
     * tres primeros al primer selector, los cuatro siguientes al segundo y los
     * tres de después al tercero. Aquí eso es diésel, transportistas externos y
     * casetas, que es de quien recibe facturas una empresa de camiones.
     *
     * Del once en adelante no se asignan solos: están para que el catálogo se
     * vea como el de una empresa de verdad, con su taller y su aseguradora.
     */
    public function proveedores(): array
    {
        return [
            ['nombre' => 'Combustibles del Norte', 'tipo' => 1],
            ['nombre' => 'Diésel Express', 'tipo' => 1],
            ['nombre' => 'Estación de Servicio Bajío', 'tipo' => 1],
            ['nombre' => 'Fletes Asociados', 'tipo' => 2],
            ['nombre' => 'Transportes Complementarios', 'tipo' => 2],
            ['nombre' => 'Autolíneas del Bajío', 'tipo' => 2],
            ['nombre' => 'Fletera del Pacífico', 'tipo' => 2],
            ['nombre' => 'Casetas PASE', 'tipo' => 3],
            ['nombre' => 'Peajes IAVE', 'tipo' => 3],
            ['nombre' => 'Custodia y Monitoreo GPS', 'tipo' => 3],
            ['nombre' => 'Taller Diésel Integral', 'tipo' => 3],
            ['nombre' => 'Llantera Industrial', 'tipo' => 3],
            ['nombre' => 'Refacciones Pesadas', 'tipo' => 3],
            ['nombre' => 'Seguros de Carga', 'tipo' => 3],
            ['nombre' => 'Grúas y Auxilio Vial', 'tipo' => 3],
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

    /**
     * El almacén de un taller de tractocamiones.
     *
     * Con el mínimo puesto en lo que de verdad se controla: dos llantas y unos
     * filtros. Un almacén de ejemplo sin mínimos no enseña lo único que hace
     * falta —qué está por acabarse—, que es para lo que se lleva.
     */
    public function refacciones(): array
    {
        return [
            ['codigo' => 'LLA-1122', 'nombre' => 'Llanta 11R22.5 dirección', 'categoria' => 'Llantas', 'medida' => 'pza', 'minimo' => 4, 'costo' => 7850],
            ['codigo' => 'LLA-2952', 'nombre' => 'Llanta 295/75R22.5 tracción', 'categoria' => 'Llantas', 'medida' => 'pza', 'minimo' => 6, 'costo' => 8420],
            ['codigo' => 'FIL-ACE', 'nombre' => 'Filtro de aceite', 'categoria' => 'Filtros', 'medida' => 'pza', 'minimo' => 8, 'costo' => 385],
            ['codigo' => 'FIL-AIR', 'nombre' => 'Filtro de aire primario', 'categoria' => 'Filtros', 'medida' => 'pza', 'minimo' => 6, 'costo' => 940],
            ['codigo' => 'FIL-COM', 'nombre' => 'Filtro de combustible', 'categoria' => 'Filtros', 'medida' => 'pza', 'minimo' => 8, 'costo' => 520],
            ['codigo' => 'ACE-15W40', 'nombre' => 'Aceite 15W40 motor diésel', 'categoria' => 'Lubricantes', 'medida' => 'litro', 'minimo' => 60, 'costo' => 96],
            ['codigo' => 'ACE-85W140', 'nombre' => 'Aceite 85W140 diferencial', 'categoria' => 'Lubricantes', 'medida' => 'litro', 'minimo' => 20, 'costo' => 128],
            ['codigo' => 'BAL-DEL', 'nombre' => 'Juego de balatas delanteras', 'categoria' => 'Frenos', 'medida' => 'juego', 'minimo' => 2, 'costo' => 3450],
            ['codigo' => 'BAL-TRA', 'nombre' => 'Juego de balatas traseras', 'categoria' => 'Frenos', 'medida' => 'juego', 'minimo' => 2, 'costo' => 3980],
            ['codigo' => 'CAM-FRE', 'nombre' => 'Cámara de freno tipo 30/30', 'categoria' => 'Frenos', 'medida' => 'pza', 'minimo' => 2, 'costo' => 2150],
            ['codigo' => 'BAT-31T', 'nombre' => 'Batería 31T 950 CCA', 'categoria' => 'Eléctrico', 'medida' => 'pza', 'minimo' => 2, 'costo' => 4290],
            ['codigo' => 'FAR-LED', 'nombre' => 'Faro LED delantero', 'categoria' => 'Eléctrico', 'medida' => 'pza', 'minimo' => 2, 'costo' => 1180],
            ['codigo' => 'MAN-AIR', 'nombre' => 'Manguera de aire con conector', 'categoria' => 'Neumático', 'medida' => 'pza', 'minimo' => 3, 'costo' => 640],
            ['codigo' => 'CLU-KIT', 'nombre' => 'Kit de clutch 15.5"', 'categoria' => 'Transmisión', 'medida' => 'juego', 'minimo' => 1, 'costo' => 18700],
            ['codigo' => 'ANT-VER', 'nombre' => 'Anticongelante verde', 'categoria' => 'Lubricantes', 'medida' => 'litro', 'minimo' => 40, 'costo' => 74],
        ];
    }

    /**
     * Los pasos de un viaje por carretera.
     *
     * Nada de corte documental, VGM ni draft del BL: eso es de un embarque
     * marítimo y en la pantalla de una empresa de camiones no significa nada.
     * Aquí el viaje se asigna, se carga, se rueda, se entrega y se cobra.
     *
     * Cuatro de ellos siguen escribiendo su columna heredada —la que ya
     * significaba lo mismo— para que el porcentaje de avance del listado y los
     * avisos de tareas atrasadas sigan teniendo de dónde leer.
     */
    public function hitos(): array
    {
        return [
            ['clave' => 'asignado', 'etiqueta' => 'Unidad y operador asignados', 'columna' => null, 'dias' => -2],
            ['clave' => 'llegada_carga', 'etiqueta' => 'Llegada a carga', 'columna' => 'pickup_date', 'dias' => -1],
            ['clave' => 'cargado', 'etiqueta' => 'Cargado', 'columna' => 'gated_IN', 'dias' => 0],
            ['clave' => 'salida', 'etiqueta' => 'Salida del origen', 'columna' => 'departure', 'dias' => 0],
            ['clave' => 'llegada_destino', 'etiqueta' => 'Llegada a destino', 'columna' => null, 'dias' => 0, 'desde' => 'arribo'],
            ['clave' => 'descargado', 'etiqueta' => 'Descargado', 'columna' => 'gated_out', 'dias' => 0, 'desde' => 'arribo'],
            ['clave' => 'evidencia', 'etiqueta' => 'Evidencia de entrega', 'columna' => 'delivered', 'dias' => 1, 'desde' => 'arribo'],
            ['clave' => 'facturado', 'etiqueta' => 'Facturado al cliente', 'columna' => null, 'dias' => 3, 'desde' => 'arribo'],
            ['clave' => 'liquidado', 'etiqueta' => 'Liquidado al operador', 'columna' => null, 'dias' => 6, 'desde' => 'arribo'],
        ];
    }

    /**
     * Las rutas que de verdad se corren, con sus kilómetros por carretera.
     *
     * Los índices son los del catálogo: origen sobre `origenes()`, destino sobre
     * `destinos()` y punto de carga sobre `lugaresRecoleccion()`. Van declaradas
     * y no combinadas porque «Patio Monterrey → Monterrey» es un viaje de cero
     * kilómetros, y de los kilómetros cuelga TODO lo demás: los días de tránsito,
     * la tarifa, el diésel y las casetas.
     */
    public function rutas(): array
    {
        return [
            ['origen' => 1, 'destino' => 1, 'recoleccion' => 1, 'km' => 915],   // Monterrey → CDMX
            ['origen' => 1, 'destino' => 3, 'recoleccion' => 1, 'km' => 785],   // Monterrey → Guadalajara
            ['origen' => 1, 'destino' => 5, 'recoleccion' => 1, 'km' => 1010],  // Monterrey → Veracruz
            ['origen' => 2, 'destino' => 1, 'recoleccion' => 2, 'km' => 540],   // Guadalajara → CDMX
            ['origen' => 2, 'destino' => 4, 'recoleccion' => 2, 'km' => 2285],  // Guadalajara → Tijuana
            ['origen' => 2, 'destino' => 2, 'recoleccion' => 2, 'km' => 785],   // Guadalajara → Monterrey
            ['origen' => 3, 'destino' => 1, 'recoleccion' => 3, 'km' => 215],   // Querétaro → CDMX
            ['origen' => 3, 'destino' => 6, 'recoleccion' => 3, 'km' => 1660],  // Querétaro → Mérida
            ['origen' => 3, 'destino' => 2, 'recoleccion' => 3, 'km' => 715],   // Querétaro → Monterrey
            ['origen' => 4, 'destino' => 1, 'recoleccion' => 4, 'km' => 1135],  // Laredo → CDMX
            ['origen' => 4, 'destino' => 2, 'recoleccion' => 4, 'km' => 225],   // Laredo → Monterrey
            ['origen' => 4, 'destino' => 3, 'recoleccion' => 4, 'km' => 1010],  // Laredo → Guadalajara
        ];
    }

    /**
     * Un día por cada 650 kilómetros, y nunca menos de uno.
     *
     * Son los kilómetros que hace un operador solo respetando las horas de
     * conducción: no es la velocidad del camión, es la jornada.
     */
    public function diasDeTransito(array $viaje): int
    {
        return max(1, (int) ceil($viaje['km'] / 650));
    }

    /** Uno de cada seis viajes se subcontrata: temporada alta o ruta que no se cubre. */
    public function conFlotaPropia(int $n): bool
    {
        return $n % 6 !== 0;
    }

    /**
     * Lo que se le cobra al cliente, en pesos y por kilómetro.
     *
     * El flete lleva **IVA del 16 % y retención del 4 %**, que es lo que hace el
     * autotransporte de carga en México cuando el cliente es persona moral. Sin
     * esa retención, cualquiera que facture fletes ve la factura y sabe que la
     * demostración no es de aquí.
     */
    public function facturaCliente(array $viaje): array
    {
        $c = $this->conceptos();
        $km = max(1, $viaje['km']);

        $lineas = [
            // Tarifa por kilómetro con un mínimo por viaje: un tramo corto no se
            // cobra a kilómetro, se cobra el viaje.
            [$c['venta_principal']['descripcion'], $c['venta_principal']['cargo'], 1, max(6500, round($km * 28 / 50) * 50)],
            [$c['maniobras']['descripcion'], $c['maniobras']['cargo'], 1, 650 + ($viaje['n'] % 4) * 100],
        ];

        // Estadía solo cuando la hubo: cobrarla en todos los viajes la volvería
        // parte de la tarifa y dejaría de significar nada.
        if ($viaje['n'] % 5 === 0) {
            $lineas[] = [$c['sueltos'][0]['nombre'], $c['sueltos'][0]['cargo'], 1, 1800];
        }

        return ['divisa' => 1, 'lineas' => $lineas];
    }

    /**
     * Lo que cuesta moverlo: diésel, casetas y, cuando se subcontrata, el flete
     * del que lo movió.
     *
     * El diésel sale de los mismos kilómetros que el gasto de viaje —2.2 km por
     * litro cargado, a 25.40 el litro— para que el módulo de gastos y la
     * contabilidad cuenten lo mismo.
     */
    public function costosDelViaje(array $viaje): array
    {
        $c = $this->conceptos();
        $km = max(1, $viaje['km']);
        $costos = [];

        if ($this->conFlotaPropia($viaje['n'])) {
            $costos[] = ['proveedor' => 'naviera', 'divisa' => 1, 'lineas' => [
                [$c['costo_principal']['descripcion'], $c['costo_principal']['cargo'], round($km / 2.2, 1), 25.4],
            ]];
        } else {
            // Subcontratado: se paga el flete del tercero y no hay diésel que
            // pagar, porque el camión no era nuestro.
            $costos[] = ['proveedor' => 'transportista', 'divisa' => 1, 'lineas' => [
                [$c['acarreo']['descripcion'], $c['acarreo']['cargo'], 1, round($km * 26 / 50) * 50],
            ]];
        }

        $casetas = [[$c['tramite']['descripcion'], $c['tramite']['cargo'], 1, round($km * 2.9, 2)]];

        // Custodia en las rutas largas, que es donde se contrata.
        if ($km > 900) {
            $casetas[] = [$c['sueltos'][1]['nombre'], $c['sueltos'][1]['cargo'], 1, 3500];
        }

        $casetas[] = [$c['terceros'], 6, 1, 600 + ($viaje['n'] % 6) * 150];

        $costos[] = ['proveedor' => 'agente', 'divisa' => 1, 'lineas' => $casetas];

        return $costos;
    }

    public function conceptos(): array
    {
        return [
            'venta_principal' => ['nombre' => 'Flete', 'descripcion' => 'Flete de origen a destino', 'cargo' => 1],
            'costo_principal' => ['nombre' => 'Diésel del viaje', 'descripcion' => 'Diésel del viaje', 'cargo' => 3],
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
