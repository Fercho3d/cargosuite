@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Paginación') }}" class="flex items-center justify-between gap-3">
        <p class="hidden text-xs text-ink-faint sm:block">
            {{ __('Mostrando :desde–:hasta de :total', [
                'desde' => $paginator->firstItem(),
                'hasta' => $paginator->lastItem(),
                'total' => number_format($paginator->total()),
            ]) }}
        </p>

        <div class="flex flex-1 items-center justify-between gap-2 sm:flex-none sm:justify-end">
            @if ($paginator->onFirstPage())
                <span class="btn-ghost cursor-not-allowed px-3 py-1.5 text-xs opacity-40">{{ __('Anterior') }}</span>
            @else
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                        class="btn-ghost px-3 py-1.5 text-xs">{{ __('Anterior') }}</button>
            @endif

            <span class="text-xs text-ink-muted">
                {{ __('Página :actual de :ultima', ['actual' => $paginator->currentPage(), 'ultima' => $paginator->lastPage()]) }}
            </span>

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                        class="btn-ghost px-3 py-1.5 text-xs">{{ __('Siguiente') }}</button>
            @else
                <span class="btn-ghost cursor-not-allowed px-3 py-1.5 text-xs opacity-40">{{ __('Siguiente') }}</span>
            @endif
        </div>
    </nav>
@endif
