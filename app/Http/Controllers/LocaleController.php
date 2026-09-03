<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rules\Enum;

/**
 * Guarda el idioma elegido, igual que el tema: en las preferencias si hay sesión
 * —para que viaje con la cuenta— y en una cookie siempre, para que la pantalla
 * de acceso también lo recuerde.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validado = $request->validate([
            'locale' => ['required', new Enum(Locale::class)],
        ]);

        $idioma = Locale::from($validado['locale']);

        if ($request->user() !== null) {
            UserPreference::updateOrCreate(
                ['usr_id' => $request->user()->usr_id],
                ['locale' => $idioma],
            );
        }

        Cookie::queue(Locale::COOKIE, $idioma->value, Locale::COOKIE_MINUTES);

        return response()->noContent();
    }
}
