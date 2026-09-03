@props(['title' => 'Portal'])
@php
    $tema = \App\Support\Theme::current();
    $temaResuelto = \App\Support\Theme::resolved();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="h-full {{ $temaResuelto === \App\Support\Theme::Dark ? 'dark' : '' }}"
      data-theme="{{ $tema->value }}"
      style="color-scheme: {{ $temaResuelto->value }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ \App\Support\Marca::nombre() }}</title>
    @include('partials.theme-script')
    <style>[x-cloak]{display:none!important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @include('partials.marca-colores')
</head>
<body class="h-full antialiased">
{{--
    El portal no lleva el menú del sistema interno: quien entra aquí es un
    cliente o un proveedor y no tiene por qué ver la operación de la empresa.
--}}
<div class="flex min-h-full flex-col">
    <header class="sticky top-0 z-20 border-b border-line bg-surface/85 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-5xl items-center gap-3 px-4 sm:px-6">
            @include('partials.logo', ['class' => 'text-xl', 'alto' => 'h-8'])
            <span class="text-[10px] font-semibold uppercase tracking-widest text-ink-faint">Portal</span>

            <div class="ml-auto flex items-center gap-3">
                <div class="hidden sm:block"><x-theme-toggle /></div>

                <div class="relative" x-data="{ open: false }">
                    <button x-on:click="open = !open" :aria-expanded="open"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-ink-soft transition hover:bg-raised">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-500 text-xs font-bold text-white">
                            {{ strtoupper(mb_substr(auth()->user()->name ?? auth()->user()->username ?? 'U', 0, 1)) }}
                        </span>
                        <span class="hidden max-w-[12rem] truncate md:inline">{{ auth()->user()->name ?? auth()->user()->username }}</span>
                    </button>

                    <div x-show="open" x-cloak x-on:click.outside="open = false"
                         class="absolute right-0 mt-2 w-56 rounded-xl border border-line bg-panel p-1.5 shadow-xl">
                        <div class="flex items-center justify-between gap-2 px-3 py-2 sm:hidden">
                            <span class="text-sm text-ink-muted">Tema</span>
                            <x-theme-toggle />
                        </div>
                        <a href="{{ route('security.show') }}" wire:navigate
                           class="block rounded-lg px-3 py-2 text-sm text-ink-muted transition hover:bg-raised hover:text-ink">
                            Seguridad y 2FA
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-brand transition hover:bg-raised">
                                Cerrar sesión
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-5xl flex-1 p-4 sm:p-6">
        @include('partials.session-status')
        {{ $slot }}
    </main>

    <footer class="border-t border-line py-4 text-center text-xs text-ink-faint">
        &copy; {{ date('Y') }} {{ \App\Support\Marca::nombre() }}
    </footer>
</div>
@livewireScripts
</body>
</html>
