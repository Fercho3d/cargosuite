<?php

namespace App\Livewire\Portal;

use App\Actions\Portal\UploadVendorInvoice;
use App\Models\Core\Charge;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Un documento del portal, con sus conceptos.
 *
 * Para el CLIENTE es de solo lectura —su factura— con el botón de darla por
 * recibida. Para el PROVEEDOR es además donde sube su comprobante: el portal
 * viejo existía sobre todo para esto.
 *
 * Los importes salen del motor del sistema base, el mismo que usa la pantalla
 * interna, para que el proveedor y la empresa vean el mismo número.
 */
class PortalDocument extends Component
{
    use WithFileUploads;

    public int $transactionId;

    public string $tranNumber = '';

    public string $tranDate = '';

    public $pdf = null;

    public $xml = null;

    private ?object $headerCache = null;

    public function mount(int $transaction): void
    {
        $this->transactionId = $transaction;

        $fila = $this->header();

        $this->tranNumber = (string) ($fila->tran_number ?? '');
        $this->tranDate = $fila->tran_date ? substr((string) $fila->tran_date, 0, 10) : '';
    }

    /**
     * El documento, ya acotado a quien entra.
     *
     * El filtro por cliente o proveedor sale de la SESIÓN. Pedir el documento de
     * otro devuelve 404 —no 403— porque un 403 confirmaría que existe.
     */
    private function header(): object
    {
        if ($this->headerCache !== null) {
            return $this->headerCache;
        }

        $usuario = auth()->user();

        $filtros = TransactionFilters::make([
            'tran_in' => [$this->transactionId],
            'showCancelled' => 1,
        ]);

        if (($clienteId = $usuario->portalClientId()) !== null) {
            $filtros->customer = $clienteId;
            $filtros->type = [0];
        } else {
            $filtros->vendor = $usuario->portalProviderId();
            $filtros->type = [1, 2];
            $filtros->paymentMode = true;
        }

        $fila = TransactionQuery::make($filtros)->get(1)->first();

        if ($fila === null) {
            throw new NotFoundHttpException('No existe el documento.');
        }

        return $this->headerCache = $fila;
    }

    public function isClient(): bool
    {
        return auth()->user()?->portalClientId() !== null;
    }

    /** ¿El proveedor todavía puede tocar este documento? */
    public function isUploadable(): bool
    {
        $fila = $this->header();

        return ! $this->isClient()
            && (int) $fila->cancelled !== 1
            && (int) ($fila->payment_request ?? 0) !== 1;
    }

    /** @return Collection<int, Charge> */
    private function charges(): Collection
    {
        return Charge::with('chargeType')
            ->where('transaction', $this->transactionId)
            ->orderBy('charge_id')
            ->get();
    }

    public function save(UploadVendorInvoice $subir): void
    {
        abort_if($this->isClient(), 403);

        $this->validate([
            'tranNumber' => ['required', 'string', 'max:50'],
            'tranDate' => ['required', 'date'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'xml' => ['nullable', 'file', 'mimetypes:text/xml,application/xml', 'max:10240'],
        ], attributes: [
            'tranNumber' => __('número'),
            'tranDate' => __('fecha'),
        ]);

        $subir->handle(
            Transaction::findOrFail($this->transactionId),
            (int) auth()->user()->portalProviderId(),
            ['tran_number' => $this->tranNumber, 'tran_date' => $this->tranDate],
            $this->pdf,
            $this->xml,
        );

        $this->reset(['pdf', 'xml']);
        $this->headerCache = null;

        session()->flash('status', __('Tu factura quedó guardada.'));
    }

    public function render()
    {
        return view('livewire.portal.portal-document', [
            'documento' => $this->header(),
            'conceptos' => $this->charges(),
        ])->layout('components.portal-layout', [
            'title' => __('Documento').' '.($this->header()->tran_number ?: ''),
        ]);
    }
}
