<?php

use App\Http\Controllers\Api\GpsController;
use Illuminate\Support\Facades\Route;

/*
 * API de posiciones GPS. Sin sesión ni CSRF: la autentica la clave GPS_TOKEN.
 * Un equipo reporta cada 10-60 s; el límite deja pasar una flota mediana por
 * una sola IP (la del servidor Traccar) y corta un abuso.
 */
Route::middleware('throttle:1200,1')->prefix('gps')->group(function () {
    Route::post('posiciones', [GpsController::class, 'traccar']);
    Route::match(['get', 'post'], 'osmand', [GpsController::class, 'osmand']);
    // La app Traccar Client agrega `?id=…` a la dirección: la clave va en la ruta.
    Route::match(['get', 'post'], 'osmand/{token}', [GpsController::class, 'osmand']);
});
