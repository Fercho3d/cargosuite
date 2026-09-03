<x-app-layout :title="__('Seguridad')">
    @php
        $user = auth()->user();
        $twoFactorEnabled = ! is_null($user->two_factor_secret);
        $twoFactorConfirmed = ! is_null($user->two_factor_confirmed_at);
    @endphp

    <div class="mx-auto max-w-3xl space-y-6">
        @include('partials.validation-errors')

        {{-- Cambio de contraseña --}}
        <section class="rounded-2xl border border-line bg-panel p-6">
            <h2 class="text-base font-semibold text-ink">{{ __('Cambiar contraseña') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('Usa una contraseña larga y única para esta cuenta.') }}</p>

            <form method="POST" action="/user/password" class="mt-4 space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label for="current_password" class="field-label">{{ __('Contraseña actual') }}</label>
                    <x-password-input id="current_password" name="current_password" autocomplete="current-password" wrapper="mt-1.5" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="field-label">{{ __('Nueva contraseña') }}</label>
                        <x-password-input id="password" name="password" autocomplete="new-password" wrapper="mt-1.5" />
                    </div>
                    <div>
                        <label for="password_confirmation" class="field-label">{{ __('Confirmar') }}</label>
                        <x-password-input id="password_confirmation" name="password_confirmation" autocomplete="new-password" wrapper="mt-1.5" />
                    </div>
                </div>
                <x-submit-button>{{ __('Actualizar contraseña') }}</x-submit-button>
            </form>
        </section>

        {{-- Verificación en dos pasos --}}
        <section class="rounded-2xl border border-line bg-panel p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('Verificación en dos pasos (2FA)') }}</h2>
                    <p class="mt-1 text-sm text-ink-muted">{{ __('Añade una capa extra con una app de autenticación (Google Authenticator, Authy…).') }}</p>
                </div>
                <span class="badge shrink-0 px-3 py-1 {{ $twoFactorConfirmed ? 'badge-ok' : 'badge-neutral' }}">
                    {{ $twoFactorConfirmed ? __('Activada') : __('Inactiva') }}
                </span>
            </div>

            @if (! $twoFactorEnabled)
                <form method="POST" action="/user/two-factor-authentication" class="mt-4">
                    @csrf
                    <x-submit-button>{{ __('Activar 2FA') }}</x-submit-button>
                    <p class="mt-2 text-xs text-ink-faint">
                        {{ __('Te pediremos tu contraseña antes de activarlo y volverás aquí para terminar.') }}
                    </p>
                </form>
            @else
                @if (! $twoFactorConfirmed)
                    <div class="mt-4 space-y-4">
                        <p class="text-sm text-ink-muted">{{ __('1. Escanea este código QR con tu app de autenticación:') }}</p>
                        <div class="inline-block rounded-xl bg-white p-3">
                            {!! $user->twoFactorQrCodeSvg() !!}
                        </div>
                        <p class="text-xs text-ink-faint">
                            {{ __('¿No puedes escanear? Clave manual:') }}
                            <code class="rounded bg-raised px-1.5 py-0.5 text-ink-soft">{{ decrypt($user->two_factor_secret) }}</code>
                        </p>
                        <form method="POST" action="/user/confirmed-two-factor-authentication" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <div>
                                <label for="code" class="field-label">{{ __('2. Ingresa el código generado') }}</label>
                                <input id="code" name="code" type="text" inputmode="numeric" class="field-input mt-1.5 w-40 tracking-widest text-center" placeholder="000000">
                            </div>
                            <x-submit-button>{{ __('Confirmar') }}</x-submit-button>
                        </form>
                    </div>
                @else
                    <div class="mt-4 space-y-4">
                        <div>
                            <p class="text-sm font-medium text-ink-soft">{{ __('Códigos de recuperación') }}</p>
                            <p class="text-xs text-ink-faint">{{ __('Guárdalos en un lugar seguro; te permiten entrar si pierdes tu dispositivo.') }}</p>
                            <div class="mt-2 grid grid-cols-2 gap-1.5 rounded-lg border border-line bg-surface p-3 font-mono text-xs text-ink-muted sm:grid-cols-4">
                                @foreach (json_decode(decrypt($user->two_factor_recovery_codes), true) as $rc)
                                    <span>{{ $rc }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <form method="POST" action="/user/two-factor-recovery-codes">@csrf<button class="btn-ghost">{{ __('Regenerar códigos') }}</button></form>
                            <form method="POST" action="/user/two-factor-authentication">
                                @csrf @method('DELETE')
                                <button class="btn-ghost !border-accent-700 !text-brand">{{ __('Desactivar 2FA') }}</button>
                            </form>
                        </div>
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
