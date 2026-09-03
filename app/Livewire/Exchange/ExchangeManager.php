<?php

namespace App\Livewire\Exchange;

use App\Models\Core\Account;
use App\Models\Core\Exchange;
use App\Support\ExchangeRates;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tipos de cambio.
 *
 * De aquí sale con cuánto se valúa cada documento, así que es la pantalla que
 * más cuidado pide: **cambiar un tipo de cambio mueve cifras ya emitidas**.
 *
 * Dos cosas heredadas que conviene tener presentes:
 *  1. La convención del DOF: la fila que «aplica el día X» contiene el tipo de
 *     cambio publicado el día hábil ANTERIOR. Así lo hace el sistema desde
 *     siempre y así se conserva.
 *  2. El alta automática solo trae el **dólar** (indicador 158 del DOF). El euro
 *     no está cableado: hay que capturarlo a mano.
 */
class ExchangeManager extends Component
{
    use WithPagination;

    #[Url(as: 'moneda', except: '')]
    public string $accountId = '';

    public ?int $editing = null;

    public string $date = '';

    public string $value = '';

    public string $account = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function updatedAccountId(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->date = now()->toDateString();
        $this->value = '';
        $this->account = $this->accountId;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $tipo = Exchange::findOrFail($id);

        $this->editing = $id;
        $this->date = $tipo->date_exchange?->toDateString() ?? '';
        $this->value = (string) $tipo->exchange_value;
        $this->account = (string) $tipo->account;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'date', 'value', 'account']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->validate([
            'date' => ['required', 'date'],
            'value' => ['required', 'numeric', 'gt:0'],
            'account' => ['required', Rule::exists('account', 'account_id')],
        ], attributes: ['date' => 'fecha', 'value' => __('tipo de cambio'), 'account' => 'moneda']);

        // El índice único de la tabla es (fecha, moneda): no puede haber dos.
        $repetido = Exchange::whereDate('date_exchange', $this->date)
            ->where('account', (int) $this->account)
            ->when($this->editing, fn ($q) => $q->where('exchange_id', '<>', $this->editing))
            ->exists();

        if ($repetido) {
            $this->addError('date', __('Ya hay un tipo de cambio para esa moneda en esa fecha.'));

            return;
        }

        $valores = [
            'date_exchange' => $this->date,
            'exchange_value' => (float) $this->value,
            'account' => (int) $this->account,
            'modified_by' => auth()->id(),
        ];

        $this->editing === 0
            ? Exchange::create($valores + ['created_by' => auth()->id(), 'taken_date' => $this->date])
            : Exchange::findOrFail($this->editing)->forceFill($valores)->save();

        session()->flash('status', __('Tipo de cambio guardado.'));
        $this->cancel();
    }

    /** Trae del DOF el dólar del día, si aún no está. */
    public function fetchToday(ExchangeRates $tipos): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $tipos->ensureFor(Carbon::today())
            ? session()->flash('status', __('Tipo de cambio del día registrado.'))
            : $this->addError('fetch', __('El DOF no devolvió un tipo de cambio para hoy. Captúralo a mano si ya lo publicaron.'));
    }

    public function render()
    {
        $tipos = Exchange::query()
            ->when($this->accountId !== '', fn ($q) => $q->where('account', (int) $this->accountId))
            ->orderByDesc('date_exchange')
            ->orderBy('account')
            ->paginate(30, ['*'], 'page', $this->getPage());

        return view('livewire.exchange.exchange-manager', [
            'tipos' => $tipos,
            'monedas' => Account::options(),
        ])->layout('components.app-layout', ['title' => __('Tipos de cambio')]);
    }
}
