<x-guest-layout :title="__('Confirmar contraseña')">
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-ink">{{ __('Confirma tu contraseña') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('Esta es una zona segura. Confirma tu contraseña para continuar.') }}</p>
    </div>

    @include('partials.validation-errors')

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
        @csrf
        <div>
            <label for="password" class="field-label">{{ __('Contraseña') }}</label>
            <x-password-input id="password" name="password" required autocomplete="current-password"
                              autofocus wrapper="mt-1.5" placeholder="••••••••" />
        </div>
        <x-submit-button class="w-full">{{ __('Confirmar') }}</x-submit-button>
    </form>
</x-guest-layout>
