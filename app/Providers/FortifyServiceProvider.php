<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Autenticación por usuario O correo, solo cuentas activas.
        // La contraseña bcrypt heredada de Yii2 es compatible con Hash::check.
        Fortify::authenticateUsing(function (Request $request) {
            $login = (string) $request->input('login');

            $user = User::where('username', $login)
                ->orWhere('email', $login)
                ->first();

            if ($user && $user->isActive() && Hash::check($request->input('password'), $user->password)) {
                $user->forceFill(['last_login' => now()])->saveQuietly();

                return $user;
            }

            return null;
        });

        /*
         * Confirmación de contraseña para entrar a una zona segura (activar el
         * 2FA, por ejemplo).
         *
         * Hay que decirle a Fortify CÓMO comprobarla. Por omisión hace
         * `$guard->validate([Fortify::username() => $user->{Fortify::username()}, ...])`,
         * y aquí `Fortify::username()` es **`login`**: un campo virtual del
         * formulario de acceso —que admite usuario O correo— que NO existe en la
         * tabla. El resultado era `select * from users where login is null` y un
         * 500 al confirmar, así que el 2FA no se podía activar.
         */
        Fortify::confirmPasswordsUsing(
            fn (User $user, string $password) => Hash::check($password, $user->password)
        );

        // Vistas de autenticación (Blade, con el tema y la marca del sistema).
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
