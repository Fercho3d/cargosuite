<?php

namespace App\Livewire\Payments;

use App\Actions\Payments\CreatePaymentRequest;
use App\Models\Core\Bank;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Collection;
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
    /** @var int[] */
    public array $ids = [];

    public string $number = '';

    public string $date = '';

    public string $bankId = '';

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

        return TransactionQuery::make($filtros)->get();
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

    public function save(CreatePaymentRequest $crear): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->validate([
            'number' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date'],
            'bankId' => ['required', 'exists:bank,bank_id'],
            'amounts.*' => ['required', 'numeric'],
        ], attributes: [
            'number' => __('número'),
            'date' => 'fecha',
            'bankId' => 'banco',
            'amounts.*' => 'importe',
        ]);

        $transacciones = $this->transactions();

        $this->assertAmountsFit($transacciones);

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $importes = collect($this->amounts)
            ->mapWithKeys(fn ($valor, $id) => [(int) $id => round((float) $valor, 2)])
            ->all();

        $solicitud = $crear->handle(
            $transacciones,
            ['number' => $this->number, 'date' => $this->date, 'bank_id' => $this->bankId],
            $importes,
            auth()->user(),
        );

        session()->flash('status', __('Solicitud ').str_pad((string) $solicitud->request_id, 4, '0', STR_PAD_LEFT).' creada.');

        $this->redirectRoute('payments.requests', [
            'num' => $solicitud->number,
            'nueva' => $solicitud->request_id,
        ], navigate: true);
    }

    /**
     * Ningún renglón puede aplicar más de lo que la transacción admite.
     *
     * Porta `Transaction::validateAmountToPay()` de Yii2, que allá vivía en un
     * campo virtual del modelo (`public $amount_to_pay`, que no es columna de la
     * tabla) y se validaba por AJAX al teclear en la rejilla. Aquí se comprueba
     * al guardar, que es cuando se escribe.
     *
     * ⚠️ El tope NO es el saldo sino `saldo + lo ya pagado`, o sea el total del
     * documento. En Yii2 eso lo hace la rama `modeOpen` de la validación, y esta
     * pantalla siempre la enciende: `views/payment-request/_transactions.php`
     * arma el editable con `'modeopen' => 1` fijo. La idea es que, dentro de una
     * solicitud, lo ya aplicado se puede volver a repartir; el efecto es que una
     * transacción saldada sigue admitiendo importe. Sin esto, un renglón con
     * saldo 0 quedaba trabado: el 0 lo rechaza la primera regla y cualquier otra
     * cifra la segunda.
     *
     * Las notas de crédito van al revés porque restan: su saldo es negativo y el
     * importe tiene que serlo también, sin pasarse por debajo. Ahí el 0 SÍ pasa
     * (la regla del «mayor que cero» solo existe en la otra rama); comprobado
     * corriendo la validación original contra la base real.
     *
     * @param  Collection<int, object>  $transacciones
     */
    private function assertAmountsFit(Collection $transacciones): void
    {
        foreach ($transacciones as $transaccion) {
            $importe = round((float) ($this->amounts[$transaccion->transc_id] ?? 0), 2);
            $tope = $this->payableLimit($transaccion);
            $campo = 'amounts.'.$transaccion->transc_id;

            // El renglón que se queda con el importe propuesto (su saldo) entra
            // tal cual. En Yii2 esta validación solo corre al TECLEAR en la
            // casilla, así que el valor por omisión nunca se comprueba y una
            // transacción ya saldada se manda con 0 sin protestar.
            if ($importe === round((float) $transaccion->left_to_pay, 2)) {
                continue;
            }

            if ((int) $transaccion->tran_type === Transaction::TYPE_CREDIT_BILL) {
                if ($importe > 0) {
                    $this->addError($campo, __('Una nota de crédito resta: el importe tiene que ser negativo.'));
                } elseif ($importe < $tope) {
                    $this->addError($campo, __('No puede ser menor que ').number_format($tope, 2).'.');
                }

                continue;
            }

            if ($importe === 0.0) {
                $this->addError($campo, __('El importe tiene que ser mayor que $0.00.'));
            } elseif ($importe > $tope) {
                $this->addError($campo, __('No puede ser mayor que ').number_format($tope, 2).'.');
            }
        }
    }

    /** Saldo + lo ya pagado: el `modeOpen` de `validateAmountToPay()` (ver arriba). */
    public function payableLimit(object $transaccion): float
    {
        return round((float) $transaccion->left_to_pay + (float) $transaccion->tran_paid_amount, 2);
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
