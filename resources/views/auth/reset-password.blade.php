<x-guest-layout :title="__('Restablecer contraseña')">
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-ink">{{ __('Nueva contraseña') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('Define una contraseña segura para tu cuenta.') }}</p>
    </div>

    @include('partials.validation-errors')

    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label for="email" class="field-label">{{ __('Correo') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required
                   autocomplete="email" class="field-input mt-1.5">
        </div>
        <div>
            <label for="password" class="field-label">{{ __('Nueva contraseña') }}</label>
            <x-password-input id="password" name="password" required autocomplete="new-password"
                              wrapper="mt-1.5" placeholder="••••••••" />
        </div>
        <div>
            <label for="password_confirmation" class="field-label">{{ __('Confirmar contraseña') }}</label>
            <x-password-input id="password_confirmation" name="password_confirmation" required
                              autocomplete="new-password" wrapper="mt-1.5" placeholder="••••••••" />
        </div>

        <x-submit-button class="w-full">{{ __('Restablecer contraseña') }}</x-submit-button>
    </form>
</x-guest-layout>
