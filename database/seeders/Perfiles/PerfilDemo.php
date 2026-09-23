<?php

namespace Database\Seeders\Perfiles;

use InvalidArgumentException;

/**
 * Los datos de ejemplo de un tipo de negocio.
 *
 * `DemoSeeder` monta siempre la misma operación —expedientes con sus unidades,
 * sus hitos, su factura y sus costos—; lo que cambia de un negocio a otro son
 * los NOMBRES: quién es el cliente, qué se mueve, de dónde a dónde y qué
 * servicios se cobran. Eso vive aquí.
 *
 * Va de la mano del vocabulario (`MARCA_VOCABULARIO`): el perfil pone los datos
 * y el vocabulario las etiquetas de la interfaz. Un taller quiere los dos.
 *
 *   DEMO_PERFIL=servicios php artisan db:seed --class=DemoSeeder
 */
abstract class PerfilDemo
{
    public static function elegido(): self
    {
        return self::porNombre((string) config('demo.perfil'));
    }

    public static function porNombre(string $nombre): self
    {
        return match ($nombre) {
            '', 'carga' => new PerfilCarga,
            'servicios' => new PerfilServicios,
            'camiones' => new PerfilCamiones,
            default => throw new InvalidArgumentException(
                "No existe el perfil de demostración «{$nombre}». Hay: carga, servicios, camiones."
            ),
        };
    }

    /** Nombre corto del perfil, para el mensaje final del seeder. */
    abstract public function nombre(): string;

    /** Prefijo del folio del expediente, p. ej. `DEMO-01001`. */
    abstract public function prefijoExpediente(): string;

    /** @return list<array{nombre: string, razon: string}> dos compañías emisoras */
    abstract public function companias(): array;

    /** @return list<string> nombres de los bancos */
    abstract public function bancos(): array;

    /**
     * Tipos de cargo: nombre, IVA, retención, si es no deducible.
     *
     * @return list<array{nombre: string, iva: float, retencion: float, no_deducible: bool}>
     */
    abstract public function tiposDeCargo(): array;

    /** @return list<string> lo que se transporta o sobre lo que se trabaja */
    abstract public function unidades(): array;

    /** @return list<string> de dónde sale el trabajo */
    abstract public function origenes(): array;

    /** @return list<string> a dónde va */
    abstract public function destinos(): array;

    /** @return list<string> puntos de recolección o sucursales */
    abstract public function lugaresRecoleccion(): array;

    /**
     * Coordenadas de los lugares, para el mapa de rutas del panel.
     * Nombre del lugar => [latitud, longitud]. Lo que no esté aquí no se dibuja.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    abstract public function coordenadas(): array;

    /**
     * Operadores de flota propia. Vacío en los negocios que subcontratan.
     *
     * @return list<array{nombre: string, licencia: string, vence: string}>
     */
    public function operadores(): array
    {
        return [];
    }

    /**
     * Unidades propias. `tipo` es tractor o caja.
     *
     * @return list<array{numero: string, tipo: string, placas: string, marca: string, anio: string, seguro: string}>
     */
    public function unidadesFlota(): array
    {
        return [];
    }

    /**
     * La plantilla de oficina, para la nómina.
     *
     * Los operadores no van aquí: se dan de alta solos a partir de la flota y
     * ligados a ella, que es lo que hace que sus viajes entren a su recibo.
     *
     * @return list<array{nombre: string, puesto: string, salario: float}>
     */
    public function plantilla(): array
    {
        return [
            ['nombre' => 'Ana Beatriz Delgado', 'puesto' => 'Dirección', 'salario' => 2400],
            ['nombre' => 'Karla Jiménez', 'puesto' => 'Tráfico', 'salario' => 950],
            ['nombre' => 'Rodrigo Peña', 'puesto' => 'Facturación', 'salario' => 780],
            ['nombre' => 'Lucía Serrano', 'puesto' => 'Atención a clientes', 'salario' => 640],
        ];
    }

    /**
     * Las refacciones del almacén del taller. Vacío en quien no tiene flota.
     *
     * @return list<array{codigo: string, nombre: string, categoria: string, medida: string, minimo: float, costo: float}>
     */
    public function refacciones(): array
    {
        return [];
    }

    /**
     * Los hitos del expediente: los pasos que se siguen en este negocio.
     *
     * Son los que se marcan en el detalle del expediente y los que se capturan
     * en la rejilla de continuidad. Van por perfil porque un barco y un camión
     * no pasan por lo mismo: aquí están los quince del sistema de origen.
     *
     * · `columna` es la de `booking_continuity` que este hito refleja, para
     *   seguir alimentando el PDF de confirmación, los filtros del listado y el
     *   sistema Yii2 con el que convive la instalación original. `null` en los
     *   hitos nuevos, que solo viven en la tabla nueva.
     * · `dias` y `desde` colocan la fecha en la demostración: días respecto a la
     *   carga o al arribo.
     *
     * @return list<array{clave: string, etiqueta: string, columna: ?string, dias: int, desde?: string}>
     */
    public function hitos(): array
    {
        return [
            ['clave' => 'vacuum_maneuver', 'etiqueta' => 'Maniobra de vacío', 'columna' => 'vacuum_maneuver', 'dias' => -5],
            ['clave' => 'pickup_date', 'etiqueta' => 'Recolección', 'columna' => 'pickup_date', 'dias' => -4],
            ['clave' => 'insurance', 'etiqueta' => 'Seguro', 'columna' => 'insurance', 'dias' => -5],
            ['clave' => 'doc_cut_of', 'etiqueta' => 'Corte documental', 'columna' => 'doc_cut_of', 'dias' => -3],
            ['clave' => 'SI_date', 'etiqueta' => 'Instrucciones', 'columna' => 'SI_date', 'dias' => -2],
            ['clave' => 'vgm', 'etiqueta' => 'VGM', 'columna' => 'vgm', 'dias' => -2],
            ['clave' => 'draf_client', 'etiqueta' => 'Draft cliente', 'columna' => 'draf_client', 'dias' => -1],
            ['clave' => 'gated_IN', 'etiqueta' => 'Gate in', 'columna' => 'gated_IN', 'dias' => -1],
            ['clave' => 'cleared', 'etiqueta' => 'Despacho', 'columna' => 'cleared', 'dias' => 0],
            ['clave' => 'departure', 'etiqueta' => 'Zarpe', 'columna' => 'departure', 'dias' => 1],
            ['clave' => 'bl_payment', 'etiqueta' => 'Pago del BL', 'columna' => 'bl_payment', 'dias' => 3],
            ['clave' => 'swb', 'etiqueta' => 'SWB', 'columna' => 'swb', 'dias' => 5],
            ['clave' => 'corrected_draft', 'etiqueta' => 'Draft corregido', 'columna' => 'corrected_draft', 'dias' => 6],
            ['clave' => 'delivered', 'etiqueta' => 'Entregado', 'columna' => 'delivered', 'dias' => 0, 'desde' => 'arribo'],
            ['clave' => 'gated_out', 'etiqueta' => 'Gate out', 'columna' => 'gated_out', 'dias' => 2, 'desde' => 'arribo'],
        ];
    }

    /**
     * Si este expediente lo mueve la flota propia o un transportista externo.
     *
     * Una empresa de camiones subcontrata de vez en cuando —temporada alta, una
     * ruta que no cubre—, y un expediente subcontratado no lleva operador, ni
     * unidad, ni diésel: lleva la factura del que lo movió.
     */
    public function conFlotaPropia(int $n): bool
    {
        return true;
    }

    /**
     * Rutas que tienen sentido, por índice de catálogo, con sus kilómetros.
     *
     * Vacío —y es lo que hace un agente de carga— significa que el origen, el
     * destino y el punto de recolección se combinan libremente: cualquier puerto
     * con cualquier puerto. En autotransporte no: «Patio Monterrey → Monterrey»
     * es un viaje de cero kilómetros y en una demostración se ve como un error.
     *
     * @return list<array{origen: int, destino: int, recoleccion: int, km: int}>
     */
    public function rutas(): array
    {
        return [];
    }

    /**
     * Días entre la carga y el arribo.
     *
     * En un barco son semanas; en un camión, el kilometraje entre un día y tres.
     * Con el número marítimo, una demostración de autotransporte enseña viajes de
     * treinta días y pierde credibilidad en la primera pantalla.
     *
     * @param  array{puerto: int, destino: int, recoleccion: int, km: int, n: int}  $viaje
     */
    public function diasDeTransito(array $viaje): int
    {
        return mt_rand(18, 34);
    }

    /**
     * La factura al cliente: en qué divisa va y qué se le cobra.
     *
     * Vive en el perfil y no en el sembrador porque el dinero es lo que más
     * delata a una demostración: un flete marítimo se cotiza en dólares por
     * contenedor y uno terrestre en pesos por kilómetro.
     *
     * `divisa` es el índice de la cuenta: 1 la de moneda local, 2 la de dólares.
     *
     * @param  array{puerto: int, destino: int, recoleccion: int, km: int, n: int}  $viaje
     * @return array{divisa: int, lineas: list<array{0: string, 1: int, 2: float, 3: float}>}
     */
    public function facturaCliente(array $viaje): array
    {
        $c = $this->conceptos();

        return [
            'divisa' => 2,
            'lineas' => [
                [$c['venta_principal']['descripcion'], $c['venta_principal']['cargo'], 1, 1800 + $viaje['puerto'] * 40 + $viaje['destino'] * 65],
                [$c['maniobras']['descripcion'], $c['maniobras']['cargo'], 1, 260 + $viaje['puerto'] * 12],
                [$c['sueltos'][0]['nombre'], $c['sueltos'][0]['cargo'], 1, 95],
            ],
        ];
    }

    /**
     * Los costos del expediente, un documento por proveedor.
     *
     * `proveedor` nombra cuál de los tres selectores del expediente paga:
     * `naviera`, `transportista` o `agente`. El sembrador los traduce al que
     * quedó asignado en ese expediente.
     *
     * @param  array{puerto: int, destino: int, recoleccion: int, km: int, n: int}  $viaje
     * @return list<array{proveedor: string, divisa: int, lineas: list<array{0: string, 1: int, 2: float, 3: float}>}>
     */
    public function costosDelViaje(array $viaje): array
    {
        $c = $this->conceptos();

        return [
            ['proveedor' => 'naviera', 'divisa' => 2, 'lineas' => [
                [$c['costo_principal']['descripcion'], $c['costo_principal']['cargo'], 1, 1450 + $viaje['puerto'] * 35 + $viaje['destino'] * 50],
            ]],
            /*
             * 🐛 Los costos en pesos van con estos importes y no con los del
             * triple, que es como estaban: el acarreo y el despacho se comían la
             * venta entera y **cada expediente daba pérdida**.
             *
             * No se veía porque faltaba el renglón de tipo de cambio de la
             * moneda base y todo lo facturado en pesos se sumaba como cero. Al
             * arreglar aquello, el panel de la demostración amaneció con la
             * utilidad en rojo. De paso estos números se parecen más a los de
             * verdad: un acarreo de bodega a puerto no cuesta doce mil pesos.
             */
            ['proveedor' => 'transportista', 'divisa' => 1, 'lineas' => [
                [$c['acarreo']['descripcion'], $c['acarreo']['cargo'], 1, 3200 + $viaje['puerto'] * 180 + $viaje['recoleccion'] * 120],
            ]],
            ['proveedor' => 'agente', 'divisa' => 1, 'lineas' => [
                [$c['tramite']['descripcion'], $c['tramite']['cargo'], 1, 2400 + $viaje['puerto'] * 90],
                [$c['terceros'], 6, 1, 900],
            ]],
        ];
    }

    /** @return list<string> modalidades de servicio */
    abstract public function modalidades(): array;

    /** @return list<string> el equipo o vehículo que ejecuta: buque, máquina… */
    abstract public function medios(): array;

    /** Prefijo del nombre del medio: «MV DEMO 01», «MÁQUINA 01». */
    abstract public function prefijoMedio(): string;

    /** @return list<string> razones sociales de los clientes */
    abstract public function clientes(): array;

    /**
     * Proveedores con su tipo (1, 2 y 3, los tres selectores del expediente).
     *
     * @return list<array{nombre: string, tipo: int}>
     */
    abstract public function proveedores(): array;

    /** @return list<string> mercancía o trabajo de cada expediente */
    abstract public function trabajos(): array;

    /** @return list<string> documentos que se le piden al cliente */
    abstract public function documentos(): array;

    /**
     * Los conceptos que se facturan y se cuestan. `cargo` es el índice (1-6) del
     * tipo de cargo, y los importes salen de aquí.
     *
     * @return array{
     *     venta_principal: array{nombre: string, descripcion: string, cargo: int},
     *     costo_principal: array{nombre: string, descripcion: string, cargo: int},
     *     acarreo: array{nombre: string, descripcion: string, cargo: int},
     *     tramite: array{nombre: string, descripcion: string, cargo: int},
     *     maniobras: array{nombre: string, descripcion: string, cargo: int},
     *     sueltos: list<array{nombre: string, cargo: int, tipo: int, precio: float, divisa: int}>,
     *     terceros: string,
     * }
     */
    abstract public function conceptos(): array;
}
