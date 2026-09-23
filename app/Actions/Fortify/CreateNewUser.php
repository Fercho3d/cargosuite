<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use LogicException;

/**
 * Registro público de Fortify. **No se usa**: las cuentas las da de alta el
 * super administrador desde /usuarios, con usuario, rol y acceso.
 *
 * Si alguien enciende `Features::registration()` sin pensarlo, esta acción
 * crearía cuentas sin `username`, `role` ni `access`, que no podrían entrar
 * ni se sabría de quién son. Mejor que reviente con un mensaje claro.
 */
class CreateNewUser implements CreatesNewUsers
{
    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        throw new LogicException(
            'El registro público está deshabilitado: las cuentas se crean desde /usuarios '
            .'con usuario, rol y acceso. No actives Features::registration() sin dar de alta esos datos aquí.'
        );
    }
}
