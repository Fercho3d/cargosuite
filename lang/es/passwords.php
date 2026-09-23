<?php

/*
 * Mensajes del restablecimiento de contraseña (Fortify y el broker de
 * Laravel). El framework solo trae los de inglés: sin este archivo la
 * pantalla en español enseñaba la clave tal cual («passwords.sent»).
 */
return [
    'reset' => 'Tu contraseña se restableció.',
    'sent' => 'Si el correo corresponde a una cuenta, te enviamos el enlace para restablecer la contraseña.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token' => 'El enlace para restablecer la contraseña no es válido o ya venció.',
    'user' => 'No encontramos una cuenta con ese correo.',
];
