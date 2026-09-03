@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginación" class="flex items-center justify-between gap-3">
        <p class="hidden text-xs text-ink-faint sm:block">
            Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            de {{ number_format($paginator->total()) }}
        </p>

        <div class="flex flex-1 items-center justify-between gap-2 sm:flex-none sm:justify-end">
            @if ($paginator->onFirstPage())
                <span class="btn-ghost cursor-not-allowed px-3 py-1.5 text-xs opacity-40">Anterior</span>
            @else
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                        class="btn-ghost px-3 py-1.5 text-xs">Anterior</button>
            @endif

            <span class="text-xs text-ink-muted">
                Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                        class="btn-ghost px-3 py-1.5 text-xs">Siguiente</button>
            @else
                <span class="btn-ghost cursor-not-allowed px-3 py-1.5 text-xs opacity-40">Siguiente</span>
            @endif
        </div>
    </nav>
@endif
