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
        return self::porNombre((string) env('DEMO_PERFIL', 'carga'));
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
