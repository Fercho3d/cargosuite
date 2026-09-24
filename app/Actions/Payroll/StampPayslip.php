<?php

namespace App\Actions\Payroll;

use App\Models\Core\Company;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\NominaLayout;
use App\Support\Cfdi\PacClient;
use App\Support\Payroll\Impuestos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Timbra los recibos de nómina (CFDI 4.0 + Nómina 1.2) ante el PAC.
 *
 * Solo una nómina **pagada**: sus importes ya no se mueven y la fecha de pago
 * del recibo es la del pago. Cada recibo se timbra por su cuenta; si uno falla
 * (le falta la CURP, el PAC lo rechaza) los demás siguen y el error queda
 * guardado en su renglón para enseñarlo.
 */
class StampPayslip
{
    public function __construct(private PacClient $pac) {}

    /**
     * Timbra lo que falte de la nómina.
     *
     * @return array{timbrados: int, errores: int}
     */
    public function handle(int $nominaId, ?int $companyId = null): array
    {
        if (! config('timbrado.habilitado')) {
            throw new CfdiException(__('Esta instalación no factura con CFDI.'));
        }

        $nomina = DB::table('nomina')->where('nomina_id', $nominaId)->first();

        if ($nomina === null || $nomina->estado !== 'pagada') {
            throw new CfdiException(__('Solo se timbra una nómina ya pagada.'));
        }

        $emisor = Company::find($nomina->company_id ?? $companyId);

        if ($emisor === null) {
            throw new CfdiException(__('Elige la compañía que timbra la nómina.'));
        }

        if (($aviso = $emisor->fiscalWarning()) !== null) {
            throw new CfdiException($aviso);
        }

        // La compañía se fija con el primer timbrado: todos los recibos de una
        // nómina salen del mismo RFC.
        DB::table('nomina')->where('nomina_id', $nominaId)->update(['company_id' => $emisor->company_id]);

        $hechos = DB::table('nomina_recibo')->where('nomina_id', $nominaId)->where('estado', 'timbrado')->pluck('empleado_id');
        $renglones = DB::table('nomina_renglon')->where('nomina_id', $nominaId)->where('tipo', '!=', 'patronal')->get()->groupBy('empleado_id');
        $cuenta = ['timbrados' => 0, 'errores' => 0];

        $empleados = DB::table('empleado')->whereIn('empleado_id', $renglones->keys())
            ->whereIn('regimen', ['sueldos', 'asimilados'])->whereNotIn('empleado_id', $hechos)->get();

        foreach ($empleados as $empleado) {
            try {
                $this->timbra($nomina, $empleado, $emisor, $renglones[$empleado->empleado_id]);
                $cuenta['timbrados']++;
            } catch (CfdiException $e) {
                $this->guarda($nomina, $empleado, ['estado' => 'error', 'mensaje' => mb_substr($e->getMessage(), 0, 500)]);
                $cuenta['errores']++;
            }
        }

        return $cuenta;
    }

    /** Carpeta de los XML y PDF de una nómina en el disco de documentos. */
    public static function carpeta(object $nomina): string
    {
        return 'nomina/'.$nomina->numero;
    }

    /** @param  Collection<int, object>  $renglones */
    private function timbra(object $nomina, object $empleado, Company $emisor, Collection $renglones): void
    {
        $faltan = NominaLayout::faltantes($empleado, $emisor);

        if ($faltan !== []) {
            throw new CfdiException(__('Falta: :campos', ['campos' => implode(', ', $faltan)]));
        }

        $asimilado = $empleado->regimen === 'asimilados';
        $renglones = $renglones->map(function (object $r) use ($asimilado) {
            $r->clave_sat = $r->tipo === 'percepcion'
                ? ($asimilado ? '046' : self::clavePercepcion($r))
                : self::claveDeduccion($r->concepto);

            return $r;
        });

        $dias = (int) Carbon::parse($nomina->desde)->diffInDays(Carbon::parse($nomina->hasta)) + 1;
        $gravado = (float) $renglones->where('tipo', 'percepcion')->sum('importe');

        $layout = (new NominaLayout(
            nomina: $nomina,
            empleado: $empleado,
            emisor: $emisor,
            renglones: $renglones,
            folio: $nomina->nomina_id.'-'.$empleado->empleado_id,
            fechaPago: Carbon::parse($nomina->pagada_en ?? now())->toDateString(),
            salarioIntegrado: $asimilado ? 0 : Impuestos::salarioIntegrado($empleado, $nomina->hasta),
            subsidioCausado: $asimilado ? 0 : Impuestos::subsidioCausado($gravado, $dias, $nomina->hasta),
        ))->build();

        $comprobante = $this->pac->stamp($layout);

        $disco = Storage::disk('documentos');
        $disco->put(self::carpeta($nomina)."/{$comprobante->uuid}.xml", $comprobante->xml);

        if ($comprobante->pdf !== null) {
            $disco->put(self::carpeta($nomina)."/{$comprobante->uuid}.pdf", $comprobante->pdf);
        }

        $this->guarda($nomina, $empleado, [
            'uuid' => $comprobante->uuid, 'rfc_emisor' => $emisor->rfc, 'estado' => 'timbrado',
            'mensaje' => null, 'timbrado_en' => now(), 'cancelado_en' => null,
        ]);
    }

    private function guarda(object $nomina, object $empleado, array $datos): void
    {
        DB::table('nomina_recibo')->updateOrInsert(
            ['nomina_id' => $nomina->nomina_id, 'empleado_id' => $empleado->empleado_id],
            $datos,
        );
    }

    /** Sueldo → 001; lo demás (viajes, bonos) → 038, otros ingresos por salarios. */
    private static function clavePercepcion(object $renglon): string
    {
        return $renglon->liquidacion_id === null && self::empiezaCon($renglon->concepto, 'Sueldo') ? '001' : '038';
    }

    /** ISR → 002, IMSS → 001, crédito de vivienda → 010; lo capturado a mano → 004. */
    private static function claveDeduccion(string $concepto): string
    {
        foreach (['ISR' => '002', 'IMSS obrero' => '001', 'Crédito INFONAVIT' => '010'] as $llave => $clave) {
            if (self::empiezaCon($concepto, $llave)) {
                return $clave;
            }
        }

        return '004';
    }

    /**
     * El renglón guarda el concepto en el idioma de quien corrió la nómina, así
     * que se compara contra los dos.
     */
    private static function empiezaCon(string $concepto, string $llave): bool
    {
        foreach (['es', 'en'] as $idioma) {
            $texto = __($llave, [], $idioma);

            if ($concepto === $texto || str_starts_with($concepto, $texto.' ')) {
                return true;
            }
        }

        return false;
    }
}
