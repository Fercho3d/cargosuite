<?php

/*
 * Los mensajes de autenticación de Laravel (los emite Fortify).
 *
 * Sin este archivo, `trans('auth.failed')` devuelve la LLAVE: al fallar el
 * acceso, la pantalla de entrada enseñaba «auth.failed» tal cual. Va por grupo y
 * no en el JSON porque quien las pide es el framework, con su propia llave.
 */
return [
    'failed' => 'El usuario o la contraseña no son correctos.',
    'password' => 'La contraseña no es correcta.',
    'throttle' => 'Demasiados intentos. Vuelve a probar en :seconds segundos.',
];
