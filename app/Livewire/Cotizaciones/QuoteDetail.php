<?php

namespace App\Livewire\Cotizaciones;

use App\Mail\QuoteMail;
use App\Support\Cotizaciones\Cotizaciones;
use App\Support\Expediente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;
use Throwable;

/**
 * La ficha de una cotización.
 *
 * En borrador se edita todo; enviada queda fija (lo que el cliente recibió es
 * lo que vale) y solo se acepta, se rechaza o se regresa a borrador. Aceptada se
 * convierte en viaje, que se factura con lo cotizado.
 */
class QuoteDetail extends Component
{
    public int $cotizacionId;

    public string $correo = '';

    public string $vigencia = '';

    public string $fechaCarga = '';

    public string $tipoUnidad = '';

    public string $condiciones = '';

    public string $notas = '';

    /** @var array<int, array{cantidad: string, precio: string}> Renglones editables, por id. */
    public array $renglones = [];

    // --- Renglón nuevo ---
    public string $concepto = '';

    public string $tipoCargo = '';

    public string $cantidad = '1';

    public string $precio = '';

    public function mount(int $cotizacion): void
    {
        abort_unless(Expediente::usa('terrestre'), 404);
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->cotizacionId = $cotizacion;
        $this->carga();
    }

    private function cotizacion(): object
    {
        return DB::table('cotizacion')->where('cotizacion_id', $this->cotizacionId)->first() ?? abort(404);
    }

    private function carga(): void
    {
        $c = $this->cotizacion();

        $this->correo = (string) $c->correo;
        $this->vigencia = (string) $c->vigencia;
        $this->fechaCarga = (string) $c->fecha_carga;
        $this->tipoUnidad = (string) $c->tipo_unidad;
        $this->condiciones = (string) $c->condiciones;
        $this->notas = (string) $c->notas;
        $this->renglones = DB::table('cotizacion_renglon')->where('cotizacion_id', $this->cotizacionId)->orderBy('renglon_id')->get()
            ->mapWithKeys(fn ($r) => [$r->renglon_id => ['cantidad' => (string) (float) $r->cantidad, 'precio' => (string) (float) $r->precio]])
            ->all();
    }

    /** Solo el borrador se edita: lo enviado es lo que el cliente tiene en la mano. */
    private function assertBorrador(): object
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        $c = $this->cotizacion();
        abort_unless($c->estado === 'borrador', 422, __('Solo se modifica una cotización en borrador.'));

        return $c;
    }

    public function guardar(): void
    {
        $this->assertBorrador();

        $this->validate([
            'correo' => ['nullable', 'email', 'max:150'],
            'vigencia' => ['required', 'date'],
            'fechaCarga' => ['nullable', 'date'],
            'tipoUnidad' => ['nullable', 'string', 'max:60'],
            'condiciones' => ['nullable', 'string', 'max:2000'],
            'notas' => ['nullable', 'string', 'max:500'],
            'renglones.*.cantidad' => ['required', 'numeric', 'min:0.01'],
            'renglones.*.precio' => ['required', 'numeric', 'min:0'],
        ], attributes: ['vigencia' => mb_strtolower(__('Vigencia')), 'correo' => mb_strtolower(__('Correo'))]);

        DB::transaction(function () {
            DB::table('cotizacion')->where('cotizacion_id', $this->cotizacionId)->update([
                'correo' => $this->correo ?: null,
                'vigencia' => $this->vigencia,
                'fecha_carga' => $this->fechaCarga ?: null,
                'tipo_unidad' => $this->tipoUnidad ?: null,
                'condiciones' => $this->condiciones ?: null,
                'notas' => $this->notas ?: null,
            ]);

            foreach ($this->renglones as $id => $r) {
                DB::table('cotizacion_renglon')->where('renglon_id', $id)->where('cotizacion_id', $this->cotizacionId)
                    ->update(['cantidad' => (float) $r['cantidad'], 'precio' => (float) $r['precio']]);
            }
        });

        session()->flash('status', __('Cotización guardada.'));
    }

    public function agregarRenglon(): void
    {
        $this->assertBorrador();

        $this->validate([
            'concepto' => ['required', 'string', 'max:100'],
            'tipoCargo' => ['required', 'integer', 'exists:charge_type,charge_type_id'],
            'cantidad' => ['required', 'numeric', 'min:0.01'],
            'precio' => ['required', 'numeric', 'min:0'],
        ], attributes: ['concepto' => mb_strtolower(__('Concepto')), 'tipoCargo' => mb_strtolower(__('Tipo de cargo')), 'precio' => mb_strtolower(__('Precio'))]);

        DB::table('cotizacion_renglon')->insert([
            'cotizacion_id' => $this->cotizacionId, 'concepto' => trim($this->concepto),
            'charge_type_id' => (int) $this->tipoCargo, 'cantidad' => (float) $this->cantidad, 'precio' => (float) $this->precio,
        ]);

        $this->reset(['concepto', 'tipoCargo', 'precio']);
        $this->cantidad = '1';
        $this->carga();
    }

    public function quitarRenglon(int $renglon): void
    {
        $this->assertBorrador();

        DB::table('cotizacion_renglon')->where('renglon_id', $renglon)->where('cotizacion_id', $this->cotizacionId)->delete();
        $this->carga();
    }

    /** Vuelve a tomar los precios vigentes de la ruta (por si cambiaron desde que se armó). */
    public function preciosDeRuta(): void
    {
        $c = $this->assertBorrador();

        DB::transaction(function () use ($c) {
            DB::table('cotizacion_renglon')->where('cotizacion_id', $this->cotizacionId)->whereNotNull('tarifa_id')->delete();

            foreach (Cotizaciones::renglonesDeRuta((int) $c->ruta_id, $this->fechaCarga ?: null, $c->client_id ? (int) $c->client_id : null) as $renglon) {
                DB::table('cotizacion_renglon')->insert($renglon + ['cotizacion_id' => $this->cotizacionId]);
            }
        });

        $this->carga();
        session()->flash('status', __('Precios actualizados con los vigentes de la ruta.'));
    }

    /**
     * Manda el PDF al correo de la cotización y la marca enviada. Si el correo
     * falla, no se marca: el cliente no la recibió.
     */
    public function enviar(): void
    {
        $this->guardar();

        $c = $this->cotizacion();

        if (blank($c->correo)) {
            $this->addError('correo', __('Captura el correo al que se manda la cotización.'));

            return;
        }

        if (DB::table('cotizacion_renglon')->where('cotizacion_id', $this->cotizacionId)->doesntExist()) {
            $this->addError('renglones', __('La cotización no tiene renglones.'));

            return;
        }

        try {
            Mail::to($c->correo)->send(new QuoteMail($this->cotizacionId));
        } catch (Throwable $e) {
            Log::warning('No salió el correo de la cotización', ['cotizacion' => $this->cotizacionId, 'error' => $e->getMessage()]);
            $this->addError('correo', __('No se pudo mandar el correo: :error', ['error' => $e->getMessage()]));

            return;
        }

        DB::table('cotizacion')->where('cotizacion_id', $this->cotizacionId)->update(['estado' => 'enviada', 'enviada_en' => now()]);
        session()->flash('status', __('Cotización enviada a :correo.', ['correo' => $c->correo]));
    }

    /** Marca la respuesta del cliente. Una vencida se puede aceptar igual si el cliente la respeta. */
    public function responder(string $respuesta): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        abort_unless(in_array($respuesta, ['aceptada', 'rechazada'], true), 404);
        abort_unless(in_array($this->cotizacion()->estado, ['enviada', 'borrador'], true), 422);

        DB::table('cotizacion')->where('cotizacion_id', $this->cotizacionId)->update(['estado' => $respuesta, 'respondida_en' => now()]);
        session()->flash('status', $respuesta === 'aceptada' ? __('Cotización aceptada. Ya se puede convertir en viaje.') : __('Cotización marcada como rechazada.'));
    }

    /** Regresa a borrador para corregirla. Una aceptada con viajes ya no. */
    public function reabrir(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        abort_if(DB::table('booking')->where('cotizacion_id', $this->cotizacionId)->exists(), 422,
            __('Esta cotización ya tiene viajes: no se puede modificar.'));

        DB::table('cotizacion')->where('cotizacion_id', $this->cotizacionId)
            ->update(['estado' => 'borrador', 'enviada_en' => null, 'respondida_en' => null]);
        $this->carga();
    }

    public function convertir()
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
        abort_unless($this->cotizacion()->estado === 'aceptada', 422, __('Solo una cotización aceptada se convierte en viaje.'));

        return $this->redirectRoute('operations.bookings.create', ['cotizacion' => $this->cotizacionId], navigate: true);
    }

    public function render()
    {
        $c = $this->cotizacion();
        $ruta = DB::table('ruta as r')
            ->join('loading_ports as o', 'o.port_id', '=', 'r.origen_id')
            ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'r.destino_id')
            ->where('r.ruta_id', $c->ruta_id)
            ->first(['r.*', 'o.port_name as origen', 'd.name as destino']);
        $renglones = Cotizaciones::renglones($this->cotizacionId);
        $totales = Cotizaciones::totales($renglones);

        return view('livewire.cotizaciones.quote-detail', [
            'c' => $c,
            'estado' => Cotizaciones::estado($c),
            'destinatario' => Cotizaciones::destinatario($c),
            'ruta' => $ruta,
            'lineas' => $renglones,
            'totales' => $totales,
            'margen' => Cotizaciones::margen($c, $totales['subtotal']),
            'viajes' => DB::table('booking')->where('cotizacion_id', $this->cotizacionId)->orderBy('booking_id')->get(['booking_id', 'booking_number']),
            'tiposCargo' => DB::table('charge_type')->where(fn ($q) => $q->whereNull('deleted')->orWhere('deleted', 0))
                ->orderBy('charge_type_name')->pluck('charge_type_name', 'charge_type_id')->all(),
        ])->layout('components.app-layout', ['title' => __('Cotización').' '.$c->numero]);
    }
}
