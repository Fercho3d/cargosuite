<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use App\Support\Theme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rules\Enum;

/**
 * Guarda el tema elegido. Para un usuario con sesión queda en sus preferencias
 * (lo verá en cualquier equipo); para un visitante, en una cookie, de modo que
 * la pantalla de acceso también recuerde su elección.
 */
class ThemeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validado = $request->validate([
            'theme' => ['required', new Enum(Theme::class)],
        ]);

        $tema = Theme::from($validado['theme']);

        if ($request->user() !== null) {
            UserPreference::updateOrCreate(
                ['usr_id' => $request->user()->usr_id],
                ['theme' => $tema],
            );
        }

        Cookie::queue(Theme::COOKIE, $tema->value, Theme::COOKIE_MINUTES);

        return response()->noContent();
    }
}
