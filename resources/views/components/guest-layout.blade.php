@props(['title' => __('Acceso')])
<!DOCTYPE html>
@php
    $tema = \App\Support\Theme::current();
    $temaResuelto = \App\Support\Theme::resolved();
@endphp
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
    <div class="relative flex min-h-full flex-col justify-center px-4 py-10 sm:px-6 sm:py-12 lg:px-8">
        <div class="absolute right-4 top-4 sm:right-6 sm:top-6">
            <x-theme-toggle />
        </div>

        <div class="mx-auto w-full max-w-md">
            <div class="flex flex-col items-center gap-2">
                <a href="/" class="text-3xl">@include('partials.logo', ['class' => 'text-3xl', 'alto' => 'h-11'])</a>
                @if ($lema = \App\Support\Marca::lema())
                    <p class="text-center text-xs font-semibold uppercase tracking-[0.25em] text-ink-faint">{{ $lema }}</p>
                @endif
            </div>

            <div class="card mt-8 p-6 shadow-lg sm:p-8">
                {{ $slot }}
            </div>

            <p class="mt-6 text-center text-xs text-ink-faint">
                &copy; {{ date('Y') }} {{ \App\Support\Marca::nombre() }}@if ($pie = \App\Support\Marca::pie()) · {{ $pie }} @endif
            </p>
        </div>
    </div>
    @livewireScripts
</body>
</html>
