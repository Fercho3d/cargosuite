<?php

namespace Tests\Feature;

use Database\Seeders\Perfiles\PerfilDemo;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Los perfiles de demostración: los datos de ejemplo de cada tipo de negocio.
 *
 * Lo que de verdad vigila esta prueba son los **largos**. El esquema heredado
 * tiene columnas cortísimas —`charge_type_name` admite 25 caracteres y
 * `service.name` solo 20— y un nombre que no cabe **no falla al escribirlo, se
 * trunca o revienta al sembrar**, que es cuando ya estás enseñándole el sistema
 * a alguien. Pasó con «Gastos por cuenta del cliente», de 29.
 */
class PerfilDemoTest extends TestCase
{
    /**
     * Largos reales del esquema. Si alguno cambia en una migración, aquí hay
     * que cambiarlo también: `CatalogRegistryTest` compara las definiciones de
     * catálogo contra la base real, pero los perfiles no pasan por ahí.
     */
    private const LARGOS = [
        'charge_type_name' => 25,
        'service_name' => 20,
        'container_name' => 25,
        'modality_name' => 15,
        'file_label' => 25,
        'file_field' => 25,
    ];

    /** @return list<PerfilDemo> */
    private function perfiles(): array
    {
        return [PerfilDemo::porNombre('carga'), PerfilDemo::porNombre('servicios')];
    }

    public function test_los_nombres_caben_en_sus_columnas(): void
    {
        foreach ($this->perfiles() as $perfil) {
            $donde = $perfil->nombre();

            foreach ($perfil->tiposDeCargo() as $tipo) {
                $this->assertLessThanOrEqual(
                    self::LARGOS['charge_type_name'],
                    mb_strlen($tipo['nombre']),
                    "«{$tipo['nombre']}» no cabe en charge_type_name ({$donde})."
                );
            }

            foreach ($perfil->unidades() as $unidad) {
                $this->assertLessThanOrEqual(self::LARGOS['container_name'], mb_strlen($unidad), "«{$unidad}» ({$donde})");
            }

            foreach ($perfil->modalidades() as $modalidad) {
                $this->assertLessThanOrEqual(self::LARGOS['modality_name'], mb_strlen($modalidad), "«{$modalidad}» ({$donde})");
            }

            foreach ($perfil->documentos() as $campo => $etiqueta) {
                $this->assertLessThanOrEqual(self::LARGOS['file_field'], mb_strlen((string) $campo), "«{$campo}» ({$donde})");
                $this->assertLessThanOrEqual(self::LARGOS['file_label'], mb_strlen($etiqueta), "«{$etiqueta}» ({$donde})");
            }

            foreach ($this->nombresDeServicio($perfil) as $nombre) {
                $this->assertLessThanOrEqual(
                    self::LARGOS['service_name'],
                    mb_strlen($nombre),
                    "«{$nombre}» no cabe en service.name ({$donde})."
                );
            }
        }
    }

    /** @return list<string> */
    private function nombresDeServicio(PerfilDemo $perfil): array
    {
        $conceptos = $perfil->conceptos();

        $nombres = array_map(
            fn (array $c) => $c['nombre'],
            [$conceptos['venta_principal'], $conceptos['costo_principal'], $conceptos['acarreo'],
                $conceptos['tramite'], $conceptos['maniobras']],
        );

        return array_merge($nombres, array_column($conceptos['sueltos'], 'nombre'));
    }

    public function test_cada_perfil_trae_lo_que_el_seeder_necesita(): void
    {
        foreach ($this->perfiles() as $perfil) {
            $this->assertCount(2, $perfil->companias(), $perfil->nombre());
            $this->assertCount(6, $perfil->tiposDeCargo(), 'El seeder referencia los tipos de cargo por número, del 1 al 6.');
            $this->assertCount(4, $perfil->origenes(), 'El seeder recorre cuatro orígenes.');
            $this->assertCount(6, $perfil->destinos(), 'El seeder recorre seis destinos.');
            $this->assertCount(4, $perfil->lugaresRecoleccion());
            $this->assertCount(8, $perfil->clientes());
            $this->assertCount(10, $perfil->proveedores());
            $this->assertCount(4, $perfil->conceptos()['sueltos']);
            $this->assertNotSame('', $perfil->prefijoExpediente());
        }
    }

    /** Los tres selectores de proveedor del expediente tienen que estar cubiertos. */
    public function test_hay_proveedores_de_los_tres_tipos(): void
    {
        foreach ($this->perfiles() as $perfil) {
            $tipos = array_unique(array_column($perfil->proveedores(), 'tipo'));
            sort($tipos);

            $this->assertSame([1, 2, 3], $tipos, $perfil->nombre());
        }
    }

    public function test_un_perfil_inventado_avisa_en_vez_de_sembrar_a_medias(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PerfilDemo::porNombre('no-existe');
    }

    public function test_sin_configurar_se_usa_el_de_carga(): void
    {
        $this->assertSame('agente de carga', PerfilDemo::porNombre('')->nombre());
    }
}
