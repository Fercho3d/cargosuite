<?php

namespace App\Models;

use App\Support\Theme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Modelo de usuario mapeado sobre la tabla `users` heredada de Yii2.
 *
 * Convenciones especiales del esquema heredado:
 *  - Llave primaria: `usr_id` (no `id`).
 *  - Marca de actualización: `modified_at` (no `updated_at`).
 *  - Contraseñas bcrypt generadas por Yii2 → compatibles con Hash::check.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $primaryKey = 'usr_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'unit',
        'role',
        'access',
        'client_id',
        'provider_id',
        'status',
    ];

    protected $hidden = [
        'password',
        'auth_key',
        'remember_token',
        'password_reset_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'created_at' => 'datetime',
            'modified_at' => 'datetime',
            'last_login' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'status' => 'boolean',
        ];
    }

    /*
     * `access` dice DESDE DÓNDE entra la cuenta, y es distinto del rol, que dice
     * qué puede hacer. Las cuentas de portal pertenecen a un cliente o a un
     * proveedor y solo ven lo suyo.
     */

    /** Personal de la empresa. */
    public const ACCESS_INTERNAL = 9;

    /** Portal del cliente. */
    public const ACCESS_CLIENT = 10;

    /** Portal del proveedor. */
    public const ACCESS_PROVIDER = 11;

    /** Roles heredados de Yii2 (`User::ROLE_*`). */
    public const ROLE_USER = 9;

    public const ROLE_ADMIN = 10;

    public const ROLE_SUPER_ADMIN = 20;

    /**
     * Solo los usuarios activos pueden autenticarse.
     */
    public function isActive(): bool
    {
        return (bool) $this->status;
    }

    /**
     * Equivalente a `User::isUserAdmin()` de Yii2: administrador o super
     * administrador. Es la puerta de los módulos internos — el resto de los roles
     * (clientes, proveedores, operación) no debe ver facturación.
     */
    public function isAdmin(): bool
    {
        return in_array((int) $this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function isSuperAdmin(): bool
    {
        return (int) $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * ¿Es una cuenta de portal (cliente o proveedor)?
     *
     * Importa mucho: estas cuentas NO deben ver la operación ni los catálogos de
     * la empresa, solo lo suyo. En la base hay decenas de ellas.
     */
    public function isPortal(): bool
    {
        return in_array((int) $this->access, [self::ACCESS_CLIENT, self::ACCESS_PROVIDER], true);
    }

    /** Personal de la empresa. Las cuentas sin `access` se tratan como internas. */
    public function isInternal(): bool
    {
        return ! $this->isPortal();
    }

    /** Cliente al que pertenece la cuenta de portal, si aplica. */
    public function portalClientId(): ?int
    {
        return (int) $this->access === self::ACCESS_CLIENT && $this->client_id
            ? (int) $this->client_id
            : null;
    }

    /** Proveedor al que pertenece la cuenta de portal, si aplica. */
    public function portalProviderId(): ?int
    {
        return (int) $this->access === self::ACCESS_PROVIDER && $this->provider_id
            ? (int) $this->provider_id
            : null;
    }

    /**
     * Preferencias de interfaz (tema, densidad). Vive en una tabla propia de
     * Laravel para no alterar la tabla `users` heredada de Yii2.
     */
    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class, 'usr_id', 'usr_id');
    }

    /**
     * Tema elegido por el usuario. Se llama `themePreference` y no `theme` para
     * que Eloquent no lo confunda con una relación al resolver `$user->theme`.
     */
    public function themePreference(): Theme
    {
        return $this->preference?->theme ?? Theme::System;
    }
}
