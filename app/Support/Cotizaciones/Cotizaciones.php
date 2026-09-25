<?php

namespace App\Support\Cotizaciones;

use App\Support\Rutas\Tarifario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se calcula de una cotización: su número, los renglones que propone la
 * ruta, los totales con impuestos, su estado real y el margen estimado.
 */
class Cotizaciones
{
    /** Días que vale una cotización si nadie dice otra cosa. */
    public const VIGENCIA_DIAS = 15;

    public static function siguienteNumero(): string
    {
        return 'COT-'.str_pad((string) ((int) DB::table('cotizacion')->max('cotizacion_id') + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Los renglones que propone la ruta para el cliente en la fecha: su venta
     * vigente, con la tarifa especial del cliente si la tiene.
     *
     * @return list<array{concepto: string, charge_type_id: ?int, cantidad: float, precio: float, tarifa_id: int}>
     */
    public static function renglonesDeRuta(int $ruta, ?string $fecha, ?int $cliente): array
    {
        return array_map(fn (object $t) => [
            'concepto' => $t->concepto,
            'charge_type_id' => $t->charge_type_id === null ? null : (int) $t->charge_type_id,
            'cantidad' => 1.0,
            'precio' => (float) $t->precio,
            'tarifa_id' => (int) $t->tarifa_id,
        ], Tarifario::ventaPorConcepto(Tarifario::vigentes($ruta, $fecha), $cliente));
    }

    /** @return Collection<int, object> */
    public static function renglones(int $cotizacion): Collection
    {
        return DB::table('cotizacion_renglon as r')
            ->leftJoin('charge_type as ct', 'ct.charge_type_id', '=', 'r.charge_type_id')
            ->where('r.cotizacion_id', $cotizacion)
            ->orderBy('r.renglon_id')
            ->get(['r.*', 'ct.charge_type_name', 'ct.tax_rate', 'ct.tax_retention']);
    }

    /**
     * Subtotal, IVA, retención y total. Cada renglón se redondea antes de sumar,
     * igual que la factura: así el total cotizado es el que luego se timbra.
     *
     * @return array{subtotal: float, iva: float, retencion: float, total: float}
     */
    public static function totales(Collection $renglones): array
    {
        $subtotal = $iva = $retencion = 0.0;

        foreach ($renglones as $r) {
            $importe = round((float) $r->cantidad * (float) $r->precio, 2);
            $subtotal += $importe;
            $iva += round($importe * (float) ($r->tax_rate ?? 0), 2);
            $retencion += round($importe * (float) ($r->tax_retention ?? 0), 2);
        }

        return [
            'subtotal' => round($subtotal, 2),
            'iva' => round($iva, 2),
            'retencion' => round($retencion, 2),
            'total' => round($subtotal + $iva - $retencion, 2),
        ];
    }

    /** El estado que se enseña: una enviada que pasó su vigencia está vencida. */
    public static function estado(object $cotizacion): string
    {
        return $cotizacion->estado === 'enviada' && $cotizacion->vigencia < now()->toDateString()
            ? 'vencida'
            : $cotizacion->estado;
    }

    /**
     * Lo que costaría hacer el viaje contra lo cotizado (sin impuestos), con
     * unidad propia y subcontratado. Es interno: no sale en el PDF.
     *
     * @return array{venta: float, propio: ?float, subcontrato: ?float, margenPropio: ?float, margenSubcontrato: ?float}
     */
    public static function margen(object $cotizacion, float $subtotal): array
    {
        $ruta = DB::table('ruta')->where('ruta_id', $cotizacion->ruta_id)->first();
        $resumen = $ruta === null ? null : Tarifario::resumen($ruta, $cotizacion->fecha_carga);
        $margen = fn (?float $costo) => $costo === null || $subtotal <= 0 ? null : round(($subtotal - $costo) / $subtotal * 100, 1);

        return [
            'venta' => $subtotal,
            'propio' => $resumen['propio'] ?? null,
            'subcontrato' => $resumen['subcontrato'] ?? null,
            'margenPropio' => $margen($resumen['propio'] ?? null),
            'margenSubcontrato' => $margen($resumen['subcontrato'] ?? null),
        ];
    }

    /** Nombre de a quién va: el cliente del catálogo o el prospecto. */
    public static function destinatario(object $cotizacion): string
    {
        return $cotizacion->client_id
            ? (string) DB::table('client')->where('client_id', $cotizacion->client_id)->value('fullName')
            : (string) $cotizacion->prospecto;
    }
}
