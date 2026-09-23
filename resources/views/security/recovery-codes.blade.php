<x-app-layout :title="__('Códigos de recuperación')">
    @php
        $user = auth()->user();
        // Solo hay códigos con el 2FA confirmado; si no, la pantalla de
        // seguridad es la que explica qué falta.
        $codigos = $user->two_factor_confirmed_at && $user->two_factor_recovery_codes
            ? json_decode(decrypt($user->two_factor_recovery_codes), true)
            : [];
    @endphp

    <div class="mx-auto max-w-3xl space-y-6">
        @include('partials.validation-errors')

        <section class="rounded-2xl border border-line bg-panel p-6">
            <h2 class="text-base font-semibold text-ink">{{ __('Códigos de recuperación') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('Guárdalos en un lugar seguro; te permiten entrar si pierdes tu dispositivo.') }}</p>

            @if ($codigos === [])
                <p class="mt-4 text-sm text-ink-faint">{{ __('Aún no hay códigos: activa y confirma el 2FA primero.') }}</p>
            @else
                <div class="mt-4 grid grid-cols-2 gap-1.5 rounded-lg border border-line bg-surface p-3 font-mono text-xs text-ink-muted sm:grid-cols-4">
                    @foreach ($codigos as $rc)
                        <span>{{ $rc }}</span>
                    @endforeach
                </div>
            @endif

            <div class="mt-4 flex flex-wrap gap-3">
                @if ($codigos !== [])
                    <form method="POST" action="/user/two-factor-recovery-codes">@csrf<button class="btn-ghost">{{ __('Regenerar códigos') }}</button></form>
                @endif
                <a href="{{ route('security.show') }}" wire:navigate class="btn-ghost">{{ __('Volver') }}</a>
            </div>
        </section>
    </div>
</x-app-layout>
