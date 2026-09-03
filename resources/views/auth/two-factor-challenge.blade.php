<x-guest-layout :title="__('Verificación en dos pasos')">
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-ink">{{ __('Verificación en dos pasos') }}</h1>
        <p class="mt-1 text-sm text-ink-muted" x-data x-show="true">
            {{ __('Ingresa el código de tu app de autenticación, o un código de recuperación.') }}
        </p>
    </div>

    @include('partials.validation-errors')

    <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-5" x-data="{ recovery: false }">
        @csrf

        <div x-show="!recovery">
            <label for="code" class="field-label">{{ __('Código de autenticación') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                   class="field-input mt-1.5 tracking-[0.5em] text-center" placeholder="000000" x-ref="code">
        </div>

        <div x-show="recovery" x-cloak>
            <label for="recovery_code" class="field-label">{{ __('Código de recuperación') }}</label>
            <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code"
                   class="field-input mt-1.5" placeholder="{{ __('xxxxxxxx-xxxxxxxx') }}" x-ref="recovery_code">
        </div>

        <x-submit-button class="w-full">{{ __('Verificar') }}</x-submit-button>

        <button type="button" class="block w-full text-center text-sm text-ink-muted hover:text-ink-soft"
                x-on:click="recovery = !recovery; $nextTick(() => (recovery ? $refs.recovery_code : $refs.code).focus())">
            <span x-show="!recovery">{{ __('Usar un código de recuperación') }}</span>
            <span x-show="recovery" x-cloak>{{ __('Usar un código de autenticación') }}</span>
        </button>
    </form>
</x-guest-layout>
