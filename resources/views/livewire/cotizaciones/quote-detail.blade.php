@php
    $dinero = fn ($v) => $v === null ? '—' : '$'.number_format((float) $v, 2);
    $editable = $c->estado === 'borrador';
    $etiquetas = [
        'borrador' => [__('Borrador'), 'badge-neutral'],
        'enviada' => [__('Enviada'), 'badge-warn'],
        'vencida' => [__('Vencida'), 'badge-danger'],
        'aceptada' => [__('Aceptada'), 'badge-ok'],
        'rechazada' => [__('Rechazada'), 'badge-neutral'],
    ];
    [$textoEstado, $claseEstado] = $etiquetas[$estado];
@endphp

<div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('cotizaciones.index') }}" wire:navigate class="text-xs text-brand hover:underline">← {{ __('Cotizaciones') }}</a>
            <h1 class="mt-1 text-lg font-semibold text-ink">
                {{ $c->numero }}
                <span class="{{ $claseEstado }} ml-2 rounded px-2 py-0.5 align-middle text-xs font-normal">{{ $textoEstado }}</span>
            </h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ $destinatario }} @if (! $c->client_id) <span class="text-ink-faint">({{ __('prospecto') }})</span> @endif
                · <a href="{{ route('rutas.show', $ruta->ruta_id) }}" wire:navigate class="hover:text-brand">{{ $ruta->origen }} → {{ $ruta->destino }}</a>
                · {{ number_format((float) $ruta->km) }} km
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('cotizaciones.pdf', $c->cotizacion_id) }}" target="_blank"
               class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">{{ __('Ver PDF') }}</a>
            @if ($editable)
                <button wire:click="enviar" wire:confirm="{{ __('¿Mandar la cotización por correo? Después ya no se podrá modificar.') }}"
                        class="rounded-lg bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-600">{{ __('Enviar al cliente') }}</button>
            @endif
            @if (in_array($c->estado, ['borrador', 'enviada'], true))
                <button wire:click="responder('aceptada')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">{{ __('Marcar aceptada') }}</button>
                <button wire:click="responder('rechazada')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">{{ __('Marcar rechazada') }}</button>
            @endif
            @if ($c->estado === 'aceptada')
                <button wire:click="convertir" class="rounded-lg bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-600">{{ __('Convertir en viaje') }}</button>
            @endif
            @if (! $editable && $viajes->isEmpty())
                <button wire:click="reabrir" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">{{ __('Regresar a borrador') }}</button>
            @endif
        </div>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($viajes->isNotEmpty())
        <div class="rounded-xl border border-line bg-panel px-4 py-2.5 text-sm">
            {{ __('Viajes de esta cotización:') }}
            @foreach ($viajes as $v)
                <a href="{{ route('operations.bookings.show', $v->booking_id) }}" wire:navigate class="ml-2 font-medium text-brand hover:underline">{{ trim((string) $v->booking_number) ?: '#'.$v->booking_id }}</a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[1fr_18rem]">
        <div class="space-y-4">
            {{-- Renglones --}}
            <section class="overflow-x-auto rounded-2xl border border-line bg-panel">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                    <h2 class="text-sm font-semibold text-ink">{{ __('Conceptos') }}</h2>
                    @if ($editable)
                        <button wire:click="preciosDeRuta" wire:confirm="{{ __('¿Volver a tomar los precios vigentes de la ruta? Los conceptos que agregaste a mano se quedan.') }}"
                                class="text-xs text-brand hover:underline">{{ __('Actualizar con precios de la ruta') }}</button>
                    @endif
                </div>
                <table class="w-full min-w-[40rem] table-fixed text-sm">
                    <colgroup><col><col class="w-32"><col class="w-24"><col class="w-36"><col class="w-36"><col class="w-12"></colgroup>
                    <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th class="px-4 py-2 text-left font-semibold">{{ __('Concepto') }}</th>
                            <th class="px-4 py-2 text-left font-semibold">{{ __('Tipo de cargo') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Cantidad') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Precio') }}</th>
                            <th class="px-4 py-2 text-right font-semibold">{{ __('Importe') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($lineas as $l)
                            <tr>
                                <td class="truncate px-4 py-2 text-ink">{{ $l->concepto }}</td>
                                <td class="truncate px-4 py-2 text-xs text-ink-muted">{{ $l->charge_type_name ?? '—' }}</td>
                                @if ($editable)
                                    <td class="px-2 py-1.5"><input type="number" step="0.01" wire:model="renglones.{{ $l->renglon_id }}.cantidad" value="{{ $renglones[$l->renglon_id]['cantidad'] ?? '' }}" class="field-input !py-1 text-right text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="number" step="0.01" wire:model="renglones.{{ $l->renglon_id }}.precio" value="{{ $renglones[$l->renglon_id]['precio'] ?? '' }}" class="field-input !py-1 text-right text-sm"></td>
                                @else
                                    <td class="px-4 py-2 text-right tabular-nums">{{ (float) $l->cantidad }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $dinero($l->precio) }}</td>
                                @endif
                                <td class="px-4 py-2 text-right font-semibold tabular-nums text-ink">{{ $dinero($l->cantidad * $l->precio) }}</td>
                                <td class="px-2 py-2 text-right">
                                    @if ($editable)
                                        <button wire:click="quitarRenglon({{ $l->renglon_id }})" title="{{ __('Quitar') }}" class="text-ink-faint hover:text-brand">×</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-4 text-sm text-ink-faint">{{ __('Sin conceptos: la ruta no tiene precios de venta con tipo de cargo.') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="border-t border-line text-sm">
                        @foreach ([[__('Subtotal'), $dinero($totales['subtotal'])], [__('IVA'), $dinero($totales['iva'])], [__('Retención'), '−'.$dinero($totales['retencion'])]] as [$et, $val])
                            <tr><td colspan="4" class="px-4 pt-2 text-right text-ink-muted">{{ $et }}</td><td class="px-4 pt-2 text-right tabular-nums text-ink-soft">{{ $val }}</td><td></td></tr>
                        @endforeach
                        <tr><td colspan="4" class="px-4 py-2 text-right font-semibold text-ink">{{ __('Total') }}</td><td class="px-4 py-2 text-right text-base font-semibold tabular-nums text-ink">{{ $dinero($totales['total']) }}</td><td></td></tr>
                    </tfoot>
                </table>

                @if ($editable)
                    <div class="grid items-end gap-2 border-t border-line bg-raised px-4 py-3 sm:grid-cols-6">
                        <label class="block sm:col-span-2">
                            <span class="field-label">{{ __('Otro concepto') }}</span>
                            <input type="text" wire:model="concepto" value="{{ $concepto }}" class="field-input mt-1.5" placeholder="{{ __('Estadía, custodia, maniobras…') }}">
                        </label>
                        <label class="block">
                            <span class="field-label">{{ __('Tipo de cargo') }}</span>
                            <select wire:model="tipoCargo" class="field-input mt-1.5">
                                <option value="">{{ __('Elige…') }}</option>
                                @foreach ($tiposCargo as $id => $nombre)
                                    <option value="{{ $id }}" @selected((string) $id === $tipoCargo)>{{ $nombre }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="field-label">{{ __('Cantidad') }}</span>
                            <input type="number" step="0.01" wire:model="cantidad" value="{{ $cantidad }}" class="field-input mt-1.5">
                        </label>
                        <label class="block">
                            <span class="field-label">{{ __('Precio') }}</span>
                            <input type="number" step="0.01" wire:model="precio" value="{{ $precio }}" class="field-input mt-1.5" placeholder="0.00">
                        </label>
                        <button wire:click="agregarRenglon" class="rounded-lg border border-line bg-panel px-3 py-2 text-sm text-ink-soft transition hover:bg-surface">+ {{ __('Agregar') }}</button>
                    </div>
                @endif
            </section>

            {{-- Datos de la cotización --}}
            <section class="rounded-2xl border border-line bg-panel p-4">
                <div class="grid gap-3 sm:grid-cols-4">
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('Correo') }}</span>
                        <input type="email" wire:model="correo" value="{{ $correo }}" @disabled(! $editable) class="field-input mt-1.5">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('Fecha de carga') }}</span>
                        <input type="date" wire:model="fechaCarga" value="{{ $fechaCarga }}" @disabled(! $editable) class="field-input mt-1.5">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('Vigencia') }}</span>
                        <input type="date" wire:model="vigencia" value="{{ $vigencia }}" @disabled(! $editable) class="field-input mt-1.5">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('Tipo de unidad') }}</span>
                        <input type="text" wire:model="tipoUnidad" value="{{ $tipoUnidad }}" @disabled(! $editable) class="field-input mt-1.5" placeholder="{{ __('Caja seca 53 pies, refrigerada, plataforma…') }}">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('Notas internas') }}</span>
                        <input type="text" wire:model="notas" value="{{ $notas }}" @disabled(! $editable) class="field-input mt-1.5">
                    </label>
                    <label class="block sm:col-span-4">
                        <span class="field-label">{{ __('Condiciones (salen en el PDF)') }}</span>
                        <textarea wire:model="condiciones" rows="3" @disabled(! $editable) class="field-input mt-1.5">{{ $condiciones }}</textarea>
                    </label>
                </div>
                @if ($editable)
                    <div class="mt-3">
                        <x-submit-button wire:click="guardar">{{ __('Guardar') }}</x-submit-button>
                    </div>
                @endif
            </section>
        </div>

        {{-- Margen: solo para quien cotiza, no sale en el PDF --}}
        <aside class="h-fit rounded-2xl border border-line bg-panel p-4">
            <p class="text-sm font-semibold text-ink">{{ __('Margen estimado') }}</p>
            <p class="text-xs text-ink-faint">{{ __('Interno: no sale en el PDF. Sin impuestos.') }}</p>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-ink-muted">{{ __('Cotizado') }}</dt><dd class="tabular-nums text-ink">{{ $dinero($margen['venta']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-muted">{{ __('Costo con unidad propia') }}</dt><dd class="tabular-nums text-ink">{{ $dinero($margen['propio']) }}</dd></div>
                <div class="flex justify-between font-semibold {{ ($margen['margenPropio'] ?? 0) < 0 ? 'text-(--danger-ink)' : 'text-ink' }}"><dt>{{ __('Margen') }}</dt><dd>{{ $margen['margenPropio'] === null ? '—' : number_format($margen['margenPropio'], 1).' %' }}</dd></div>
                <div class="flex justify-between border-t border-line pt-2"><dt class="text-ink-muted">{{ __('Subcontrato más barato') }}</dt><dd class="tabular-nums text-ink">{{ $dinero($margen['subcontrato']) }}</dd></div>
                <div class="flex justify-between font-semibold {{ ($margen['margenSubcontrato'] ?? 0) < 0 ? 'text-(--danger-ink)' : 'text-ink' }}"><dt>{{ __('Margen') }}</dt><dd>{{ $margen['margenSubcontrato'] === null ? '—' : number_format($margen['margenSubcontrato'], 1).' %' }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-ink-faint">{{ __('El costo propio usa el diésel del día de carga y no incluye sueldo del operador ni desgaste de la unidad.') }}</p>
        </aside>
    </div>
</div>
