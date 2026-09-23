<?php

namespace App\Support\License;

use SodiumException;

/**
 * Formato del token de licencia: dos partes en base64url separadas por un punto
 * —la carga (JSON) y su firma Ed25519—, con la misma idea que un JWT pero al
 * mínimo.
 *
 * Firmar necesita la llave privada (solo el proveedor la tiene); verificar solo
 * necesita la pública, que es la que vive en el servidor.
 */
final class LicenseToken
{
    /** @param  array<string, mixed>  $payload */
    public static function mint(array $payload, string $secretKeyBase64): string
    {
        $body = self::b64url((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signature = sodium_crypto_sign_detached($body, self::decode($secretKeyBase64));

        return $body.'.'.self::b64url($signature);
    }

    /**
     * Devuelve la carga si la firma es válida para la llave pública dada; null
     * en cualquier otro caso (token mal formado, firma inválida, llave mala).
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token, string $publicKeyBase64): ?array
    {
        if ($publicKeyBase64 === '' || ! str_contains($token, '.')) {
            return null;
        }

        [$body, $signature] = explode('.', trim($token), 2);

        try {
            $signatureRaw = self::decode($signature);
            $publicKey = self::decode($publicKeyBase64);

            if (strlen($signatureRaw) !== SODIUM_CRYPTO_SIGN_BYTES
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || ! sodium_crypto_sign_verify_detached($signatureRaw, $body, $publicKey)) {
                return null;
            }
        } catch (SodiumException) {
            return null;
        }

        $payload = json_decode(self::unb64url($body), true);

        return is_array($payload) ? $payload : null;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    /** Acepta base64 estándar y base64url para llaves y firmas. */
    private static function decode(string $value): string
    {
        return self::unb64url($value);
    }
}
