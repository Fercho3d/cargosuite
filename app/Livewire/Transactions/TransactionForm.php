<?php

namespace App\Livewire\Transactions;

use App\Actions\Transactions\SaveTransaction;
use App\Models\Core\Account;
use App\Models\Core\Booking;
use App\Models\Core\Client;
use App\Models\Core\Company;
use App\Models\Core\Provider;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use App\Support\TransactionFiles;
use App\Support\TransactionLock;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Alta y edición de una transacción.
 *
 * En Yii2 esto era un modal cargado por AJAX (`actionCreate` / `actionUpdate`);
 * aquí es una pantalla con dirección propia, para poder enlazarla y volver a ella.
 *
 * Cuando la transacción está bloqueada (timbrada, pagada o de un booking
 * cerrado), el formulario deja editar **solo la compañía emisora** — misma
 * excepción que hace el original, porque el emisor sí se corrige después.
 */
class TransactionForm extends Component
{
    use WithFileUploads;

    public ?int $transactionId = null;

    public int $bookingId;

    public int $tranType = Transaction::TYPE_INVOICE;

    // --- Campos del formulario ---
    public string $tranDate = '';

    public string $accountId = '';

    /*
     * Opcionales, y por eso anulables: los selectores vacíos llegan como cadena
     * vacía, que las reglas `exists` rechazarían. Se pasan a null en un solo
     * lugar antes de validar (`blanksToNull`).
     */
    public ?string $tranNumber = null;

    public ?string $companyId = null;

    public ?string $customerId = null;

    public ?string $vendorId = null;

    public ?string $invoiceType = null;

    public ?string $seal = null;

    public ?string $newSeal = null;

    /** Motivo por el que el formulario está bloqueado, o null si se puede editar. */
    public ?string $lockReason = null;

    /**
     * La fecha es la excepción a la excepción: aunque el documento esté
     * bloqueado, el super administrador puede corregirla mientras el booking siga
     * abierto. Es la acción `modify-date` del sistema original.
     */
    public bool $dateIsEditable = false;

    /*
     * Adjuntos. Los dos van a la MISMA subcarpeta `pdf` de la transacción, igual
     * que en el sistema original; la columna guarda solo el nombre del archivo.
     */
    public $pdfFile = null;

    public $xmlFile = null;

    public ?string $pdfAttached = null;

    public ?string $xmlAttached = null;

    public function mount(?int $transaction = null): void
    {
        if ($transaction !== null) {
            $this->mountForUpdate($transaction);

            return;
        }

        // El booking y el tipo llegan por cadena de consulta y se leen de la
        // petición: en un componente de página completa, Livewire solo inyecta
        // en `mount()` los parámetros de la ruta.
        $this->mountForCreate(
            request()->integer('booking') ?: null,
            request()->string('tipo', 'factura')->toString(),
        );
    }

    private function mountForCreate(?int $booking, string $tipo): void
    {
        if ($booking === null || ! Booking::whereKey($booking)->exists()) {
            throw new NotFoundHttpException(__('Falta el booking al que pertenece la transacción.'));
        }

        $this->bookingId = $booking;
        $this->tranType = $tipo === 'costo' ? Transaction::TYPE_BILL : Transaction::TYPE_INVOICE;
        $this->tranDate = now()->toDateString();
        $this->invoiceType = (string) Transaction::INVOICE_TYPE_NORMAL;
    }

    private function mountForUpdate(int $transaction): void
    {
        $modelo = Transaction::with('bookingModel')->findOrFail($transaction);

        $this->transactionId = $modelo->transc_id;
        $this->bookingId = (int) $modelo->booking;
        $this->tranType = (int) $modelo->tran_type;
        $this->tranDate = $modelo->tran_date?->toDateString() ?? '';
        $this->accountId = (string) $modelo->account;
        $this->tranNumber = $modelo->tran_number;
        $this->companyId = $this->asOption($modelo->company_id);
        $this->customerId = $this->asOption($modelo->customer);
        $this->vendorId = $this->asOption($modelo->vendor);
        $this->invoiceType = $this->asOption($modelo->invoice_type);
        $this->seal = $modelo->seal;
        $this->newSeal = $modelo->new_seal;
        $this->pdfAttached = $modelo->pdf_attach ?: null;
        $this->xmlAttached = $modelo->xml_attach ?: null;

        $this->lockReason = $this->lockFor($modelo)->reason;
        $this->dateIsEditable = TransactionLock::canChangeDate(
            (bool) ($modelo->bookingModel?->locked ?? false),
            auth()->user(),
        );
    }

    private function asOption(mixed $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    private function lockFor(Transaction $modelo): TransactionLock
    {
        $filtros = TransactionFilters::make(['tran_in' => [$modelo->transc_id], 'showCancelled' => 1]);
        $filtros->showQuatation = $modelo->bookingModel?->isQuotation() ?? false;

        $fila = TransactionQuery::make($filtros)->get(1)->first() ?? (object) [];

        return TransactionLock::evaluate($fila, (bool) ($modelo->bookingModel?->locked ?? false), auth()->user());
    }

    public function isInvoice(): bool
    {
        return $this->tranType === Transaction::TYPE_INVOICE;
    }

    public function isLocked(): bool
    {
        return $this->lockReason !== null;
    }

    /**
     * El número solo se captura a mano en los costos y en las facturas históricas;
     * en las demás lo asigna el consecutivo al guardar.
     */
    public function numberIsEditable(): bool
    {
        return ! $this->isInvoice()
            || (int) $this->invoiceType === Transaction::INVOICE_TYPE_HISTORY;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'tranDate' => ['required', 'date'],
            'accountId' => ['required', Rule::exists('account', 'account_id')],
            'companyId' => ['nullable', Rule::exists('company', 'company_id')],
            'tranNumber' => ['nullable', 'string', 'max:128'],
            'seal' => ['nullable', 'string', 'max:128'],
            'newSeal' => ['nullable', 'string', 'max:128'],
            'invoiceType' => [Rule::requiredIf($this->isInvoice()), 'nullable', 'integer'],
            'customerId' => [Rule::requiredIf($this->isInvoice()), 'nullable', Rule::exists('client', 'client_id')],
            'vendorId' => [Rule::requiredIf(! $this->isInvoice()), 'nullable', Rule::exists('provider', 'provider_id')],
            'pdfFile' => ['nullable', 'file', 'extensions:pdf', 'max:20480'],
            'xmlFile' => ['nullable', 'file', 'extensions:xml', 'max:20480'],
        ];
    }

    /** Deja en null los campos opcionales que llegaron vacíos desde un selector. */
    private function blanksToNull(): void
    {
        foreach (['tranNumber', 'companyId', 'customerId', 'vendorId', 'invoiceType', 'seal', 'newSeal'] as $campo) {
            if (trim((string) $this->{$campo}) === '') {
                $this->{$campo} = null;
            }
        }
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'tranDate' => 'fecha',
            'accountId' => 'moneda',
            'companyId' => __('compañía'),
            'tranNumber' => __('número'),
            'invoiceType' => __('tipo de factura'),
            'customerId' => 'cliente',
            'vendorId' => 'proveedor',
            'pdfFile' => 'archivo PDF',
            'xmlFile' => 'archivo XML',
        ];
    }

    /**
     * Un proveedor solo puede tener un costo por booking. Es `validateVendor()`
     * del modelo de Yii2: evita facturar dos veces el mismo servicio, y el super
     * administrador queda exento porque a veces hay que corregir a mano.
     */
    private function assertVendorIsFree(): void
    {
        if ($this->isInvoice() || auth()->user()?->isSuperAdmin()) {
            return;
        }

        $repetida = Transaction::where('vendor', $this->vendorId)
            ->where('booking', $this->bookingId)
            ->where('tran_type', '<>', Transaction::TYPE_INVOICE)
            ->when($this->transactionId, fn ($q) => $q->where('transc_id', '<>', $this->transactionId))
            ->first();

        if ($repetida !== null) {
            throw ValidationException::withMessages(['vendorId' => sprintf(
                __('Este proveedor ya está en la transacción %s: solo se permite un servicio por proveedor y booking.'),
                $repetida->tran_number ?: $repetida->transc_id,
            )]);
        }
    }

    public function save(SaveTransaction $guardar): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $modelo = $this->transactionId === null
            ? new Transaction
            : Transaction::with('bookingModel')->findOrFail($this->transactionId);

        // Bloqueada: solo se aceptan la compañía y, para el super administrador,
        // la fecha. Todo lo demás que venga en la petición se descarta.
        if ($this->isLocked()) {
            $modelo->company_id = $this->companyId === null ? null : (int) $this->companyId;

            if ($this->dateIsEditable) {
                $this->validateOnly('tranDate');
                $modelo->tran_date = Carbon::parse($this->tranDate)->toDateString();
            }

            $modelo->save();

            session()->flash('status', __('Se actualizó la transacción.'));
            $this->redirectRoute('transactions.show', $modelo->transc_id, navigate: true);

            return;
        }

        $this->blanksToNull();
        $this->validate();
        $this->assertVendorIsFree();

        $guardar->handle($modelo, $this->attributesForSave(), auth()->user());

        // Los adjuntos van después de guardar: hasta entonces no hay id con el
        // que nombrar su carpeta. Es el mismo orden que sigue el original.
        $this->storeAttachments($modelo);

        session()->flash('status', $this->transactionId === null ? __('Transacción creada.') : __('Transacción actualizada.'));
        $this->redirectRoute('transactions.show', $modelo->transc_id, navigate: true);
    }

    /** Sube los archivos elegidos y deja el nombre en la transacción. */
    private function storeAttachments(Transaction $modelo): void
    {
        $archivos = app(TransactionFiles::class);
        $columnas = [];

        if ($this->pdfFile !== null) {
            $columnas['pdf_attach'] = $archivos->store($modelo, $this->pdfFile);
        }

        if ($this->xmlFile !== null) {
            $columnas['xml_attach'] = $archivos->store($modelo, $this->xmlFile);
        }

        if ($columnas !== []) {
            $modelo->forceFill($columnas)->save();
            $this->reset(['pdfFile', 'xmlFile']);
        }
    }

    /** @return array<string, mixed> */
    private function attributesForSave(): array
    {
        $entero = fn (?string $valor) => $valor === null ? null : (int) $valor;

        return [
            'booking' => $this->bookingId,
            'tran_type' => $this->tranType,
            'tran_date' => $this->tranDate,
            'account' => (int) $this->accountId,
            'company_id' => $entero($this->companyId),
            'customer' => $this->isInvoice() ? $entero($this->customerId) : null,
            'vendor' => $this->isInvoice() ? null : $entero($this->vendorId),
            'invoice_type' => $this->isInvoice() ? $entero($this->invoiceType) : null,
            'tran_number' => $this->numberIsEditable() ? $this->tranNumber : null,
            'seal' => $this->seal,
            'new_seal' => $this->newSeal,
        ];
    }

    public function render()
    {
        return view('livewire.transactions.transaction-form', [
            'booking' => Booking::find($this->bookingId),
            'currencies' => Account::options(),
            'companies' => Company::options(),
            'clients' => $this->isInvoice() ? Client::options() : [],
            'providers' => $this->isInvoice() ? [] : Provider::options(),
        ])->layout('components.app-layout', ['title' => $this->title()]);
    }

    public function title(): string
    {
        // Se escribe entero y no armando la cadena por partes: «factura» es
        // femenino y «costo» masculino, y así no sale «Nueva costo».
        return match (true) {
            $this->transactionId !== null => $this->isInvoice() ? __('Editar factura') : __('Editar costo'),
            $this->isInvoice() => __('Nueva factura'),
            default => __('Nuevo costo'),
        };
    }
}
