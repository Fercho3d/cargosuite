{{-- Captura de un precio de la ruta. Nuevo: todos los datos; cambio de uno
     vigente: solo el precio y desde cuándo vale (lo demás se conserva). --}}
<div class="grid items-end gap-3 {{ $nuevo ? 'sm:grid-cols-6' : 'sm:grid-cols-4' }}">
    @if ($nuevo)
        <label class="block sm:col-span-2">
            <span class="field-label">{{ __('Concepto') }}</span>
            <input type="text" wire:model="concepto" value="{{ $concepto }}" list="conceptos-ruta" class="field-input mt-1.5"
                   placeholder="{{ __('Flete, maniobras, casetas…') }}">
            <datalist id="conceptos-ruta">
                @foreach ([__('Flete'), __('Maniobras'), __('Casetas'), __('Viáticos'), __('Custodia'), __('Flete subcontratado')] as $sugerido)
                    <option value="{{ $sugerido }}"></option>
                @endforeach
            </datalist>
        </label>
        @if ($tipo === 'venta')
            <label class="block">
                <span class="field-label">{{ __('Cliente') }}</span>
                <select wire:model="cliente" class="field-input mt-1.5">
                    <option value="">{{ __('General (todos)') }}</option>
                    @foreach ($clientes as $id => $nombre)
                        <option value="{{ $id }}" @selected((string) $id === $cliente)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <label class="block">
                <span class="field-label">{{ __('Proveedor') }}</span>
                <select wire:model="proveedor" class="field-input mt-1.5">
                    <option value="">{{ $tipo === 'subcontrato' ? __('Elige…') : __('Ninguno') }}</option>
                    @foreach ($proveedores as $id => $nombre)
                        <option value="{{ $id }}" @selected((string) $id === $proveedor)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="block">
            <span class="field-label">{{ __('Tipo de cargo') }}</span>
            <select wire:model="tipoCargo" class="field-input mt-1.5">
                <option value="">{{ $tipo === 'costo' ? __('Ninguno') : __('Elige…') }}</option>
                @foreach ($tiposCargo as $id => $nombre)
                    <option value="{{ $id }}" @selected((string) $id === $tipoCargo)>{{ $nombre }}</option>
                @endforeach
            </select>
        </label>
    @else
        <p class="text-sm text-ink sm:col-span-2">
            {{ __('Precio nuevo de «:concepto»', ['concepto' => $concepto]) }}
            <span class="block text-xs text-ink-muted">{{ __('El actual queda en el historial.') }}</span>
        </p>
    @endif
    <label class="block">
        <span class="field-label">{{ __('Precio') }}</span>
        <input type="number" step="0.01" wire:model="precio" value="{{ $precio }}" class="field-input mt-1.5" placeholder="0.00">
    </label>
    <label class="block">
        <span class="field-label">{{ __('Vigente desde') }}</span>
        <input type="date" wire:model="vigenteDesde" value="{{ $vigenteDesde }}" class="field-input mt-1.5">
    </label>
</div>
<div class="mt-3 flex gap-2">
    <x-submit-button wire:click="agregarPrecio">{{ __('Guardar precio') }}</x-submit-button>
    <button type="button" wire:click="cancelar" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-panel">{{ __('Cancelar') }}</button>
</div>
