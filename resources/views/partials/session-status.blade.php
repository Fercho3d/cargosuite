@if (session('status'))
    <div class="alert-ok mb-4">
        {{ session('status') }}

        {{-- El código con el que contestó el PAC: estorba en el mensaje y hace
             falta cuando hay que llamarles, así que va en letra chica. --}}
        @if (session('status_detail'))
            <span class="ml-1 text-xs text-ink-faint">({{ session('status_detail') }})</span>
        @endif
    </div>
@endif
