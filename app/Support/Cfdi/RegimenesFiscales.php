<?php

namespace App\Support\Cfdi;

/**
 * Catálogo `c_RegimenFiscal` del SAT, tal como lo traía el sistema original
 * (`Client::getRegimenFiscalList()` en Yii2). Es una lista fija porque no tiene
 * tabla en la base: las de uso de CFDI, método y forma de pago sí la tienen.
 */
final class RegimenesFiscales
{
    /** @return array<string, string> `[clave => descripción]` */
    public static function all(): array
    {
        return [
            '601' => 'General de Ley Personas Morales',
            '603' => 'Personas Morales con Fines no Lucrativos',
            '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
            '606' => 'Arrendamiento',
            '607' => 'Régimen de Enajenación o Adquisición de Bienes',
            '608' => 'Demás ingresos',
            '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
            '611' => 'Ingresos por Dividendos (socios y accionistas)',
            '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
            '614' => 'Ingresos por intereses',
            '615' => 'Régimen de los ingresos por obtención de premios',
            '616' => 'Sin obligaciones fiscales',
            '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
            '621' => 'Incorporación Fiscal',
            '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
            '623' => 'Opcional para Grupos de Sociedades',
            '624' => 'Coordinados',
            '625' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
            '626' => 'Régimen Simplificado de Confianza',
        ];
    }

    /**
     * Opciones para un selector: «601 - General de Ley Personas Morales».
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::all())->map(fn ($nombre, $clave) => "{$clave} - {$nombre}")->all();
    }

    /**
     * RFC según el SAT: 3 letras (moral) o 4 (física), fecha `aammdd` y homoclave
     * de 3. Acepta `Ñ` y `&`, y los genéricos `XAXX010101000` y `XEXX010101000`.
     */
    public const RFC_REGEX = '/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/u';
}
