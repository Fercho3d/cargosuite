<?php

namespace App\Livewire\Payments;

use App\Actions\Payments\CreatePaymentRequest;
use App\Models\Core\Bank;
use App\Models\Core\Exchange;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Alta de una solicitud de pago a partir de las transacciones seleccionadas en
 * el listado.
 *
 * En Yii2 esto era un modal que recibía las casillas marcadas de la rejilla; el
 * flujo es el mismo, pero aquí la pantalla tiene dirección propia y los importes
 * se pueden ajustar renglón por renglón antes de guardar.
 */
class PaymentRequestForm extends Component
{
    use ChecksPaymentAmounts;

    /** @var int[] */
    public array $ids = [];

    public string $number = '';

    public string $date = '';

    public string $bankId = '';

    /**
     * «Tipo de cambio propio»: la solicitud se valúa con el TC capturado en vez
     * del registrado para su fecha. Es la casilla «Custom TC» del modal de Yii2.
     */
    public bool $customTc = false;

    /** El TC propio, con cuatro decimales. */
    public string $tcValue = '';

    /** @var array<int, string> transc_id => importe a aplicar */
    public array $amounts = [];

    /**
     * A dónde vuelve «Cancelar»: la pantalla de la que se llegó, con su filtro.
     *
     * Antes siempre caía en el listado de solicitudes, aunque se viniera de
     * Facturas o de Costos, y había que rehacer el filtro a mano.
     */
    public string $back = '';

    public function mount(): void
    {
        $this->ids = collect(explode(',', (string) request()->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($this->ids === []) {
            throw new NotFoundHttpException(__('No se seleccionó ninguna transacción.'));
        }

        $this->back = $this->safeBack((string) request()->query('volver', ''));
        $this->date = now()->toDateString();

        foreach ($this->transactions() as $transaccion) {
            $this->amounts[$transaccion->transc_id] = (string) round((float) $transaccion->left_to_pay, 2);
        }
    }

    /**
     * Las transacciones seleccionadas, con los mismos modos que usaba el
     * original al armar la solicitud: importes en su divisa y con signo.
     *
     * @return Collection<int, object>
     */
    public function transactions(): Collection
    {
        $filtros = TransactionFilters::make(['tran_in' => $this->ids]);
        $filtros->noExchange = true;
        $filtros->invoiceMode = true;
        $filtros->paymentMode = true;
        // Las canceladas se traen para rechazarlas con su motivo en el renglón;
        // si se filtraran aquí desaparecerían de la pantalla sin explicación.
        $filtros->showCancelled = 1;

        return TransactionQuery::make($filtros)->get();
    }

    /** Al crear, el tope es el saldo: lo que todavía se debe del documento. */
    protected function payableLimit(object $transaccion): float
    {
        return round((float) $transaccion->left_to_pay, 2);
    }

    /**
     * Solo se acepta una ruta INTERNA.
     *
     * El destino llega en la dirección, o sea de fuera: sin este filtro
     * bastaría con cambiar `volver` por otro sitio para que el botón
     * «Cancelar» de nuestra pantalla llevara a donde quisiera quien armara el
     * enlace. Se conserva únicamente la ruta y su cadena de consulta.
     */
    private function safeBack(string $destino): string
    {
        if ($destino === '' || ! str_starts_with($destino, '/') || str_starts_with($destino, '//')) {
            return '';
        }

        $partes = parse_url($destino);

        if ($partes === false || isset($partes['host']) || isset($partes['scheme'])) {
            return '';
        }

        return $partes['path'].(isset($partes['query']) ? '?'.$partes['query'] : '');
    }

    /** De dónde se vino, o el listado de solicitudes si no consta. */
    public function backUrl(): string
    {
        return $this->back !== '' ? $this->back : route('payments.requests', absolute: false);
    }

    public function isCollection(): bool
    {
        $primera = $this->transactions()->first();

        return $primera !== null && (int) $primera->tran_type === Transaction::TYPE_INVOICE;
    }

    public function total(): float
    {
        return round(collect($this->amounts)->sum(fn ($v) => (float) $v), 2);
    }

    /**
     * El TC registrado para la fecha y divisa elegidas, como referencia junto a
     * la casilla; si aún no está registrado se pide al guardar.
     */
    public function dayRate(): ?float
    {
        $primera = $this->transactions()->first();

        if ($primera === null || ! strtotime($this->date)) {
            return null;
        }

        $valor = Exchange::where('account', (int) $primera->account_id)
            ->whereDate('date_exchange', $this->date)
            ->value('exchange_value');

        return $valor === null ? null : (float) $valor;
    }

    public function save(CreatePaymentRequest $crear): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->validate([
            'number' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date'],
            'bankId' => ['required', 'exists:bank,bank_id'],
            'tcValue' => [Rule::requiredIf($this->customTc), 'nullable', 'numeric', 'gt:0'],
            'amounts.*' => ['required', 'numeric'],
        ], attributes: [
            'number' => __('número'),
            'date' => __('fecha'),
            'bankId' => __('banco'),
            'tcValue' => __('tipo de cambio'),
            'amounts.*' => __('importe'),
        ]);

        $transacciones = $this->transactions();

        // Saldadas y canceladas no entran a una solicitud nueva, diga lo que
        // diga el importe; el motivo sale en el renglón.
        foreach ($transacciones as $transaccion) {
            if ($motivo = CreatePaymentRequest::rejectionReason($transaccion)) {
                $this->addError('amounts.'.$transaccion->transc_id, $motivo);
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->assertAmountsFit($transacciones, 'left_to_pay');

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $importes = collect($this->amounts)
            ->mapWithKeys(fn ($valor, $id) => [(int) $id => round((float) $valor, 2)])
            ->all();

        $solicitud = $crear->handle(
            $transacciones,
            [
                'number' => $this->number,
                'date' => $this->date,
                'bank_id' => $this->bankId,
                'custom_tc' => $this->customTc,
                'tc_value' => $this->tcValue,
            ],
            $importes,
            auth()->user(),
        );

        session()->flash('status', __('Solicitud :folio creada.', ['folio' => str_pad((string) $solicitud->request_id, 4, '0', STR_PAD_LEFT)]));

        $this->redirectRoute('payments.requests', [
            'num' => $solicitud->number,
            'nueva' => $solicitud->request_id,
        ], navigate: true);
    }

    public function render()
    {
        return view('livewire.payments.payment-request-form', [
            'transacciones' => $this->transactions(),
            'banks' => Bank::options(),
        ])->layout('components.app-layout', [
            'title' => $this->isCollection() ? __(__('Nueva solicitud de cobro')) : __(__('Nueva solicitud de pago')),
        ]);
    }
}
