<?php

namespace App\Models;

use App\Support\Theme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

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
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $primaryKey = 'usr_id';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'modified_at';

    /*
     * Rol, acceso, estado y el cliente o proveedor ligado NO son asignables en
     * masa: deciden qué ve la cuenta, y se escriben siempre con `forceFill` y
     * campos explícitos (`UserManager::save()`).
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'unit',
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

    /*
     * Roles heredados de Yii2 (`User::getInternalRoles()`, `getClientRoles()` y
     * `getProviderRoles()`). Cada acceso tiene los suyos: los internos mandan
     * dentro del sistema y los de portal solo matizan qué ve la cuenta del
     * cliente o del proveedor. En la base hay decenas de cuentas de cada uno.
     */

    /** Personal: usuario de operación. */
    public const ROLE_USER = 9;

    /** Personal: administrador. */
    public const ROLE_ADMIN = 10;

    /** Personal: super administrador, el dueño del software. */
    public const ROLE_SUPER_ADMIN = 20;

    /** Portal de cliente: solo consulta. */
    public const ROLE_CLIENT_READONLY = 12;

    /** Portal de cliente: editor o asociado. */
    public const ROLE_CLIENT_EDITOR = 13;

    /** Portal de cliente: agente aduanal del cliente. */
    public const ROLE_CLIENT_CUSTOMS_BROKER = 16;

    /** Portal de proveedor: agente aduanal. */
    public const ROLE_PROVIDER_CUSTOMS_BROKER = 14;

    /** Portal de proveedor: transportista. */
    public const ROLE_PROVIDER_CARRIER = 15;

    /**
     * Roles del personal de la empresa, con su etiqueta.
     *
     * @return array<int, string>
     */
    public static function internalRoles(): array
    {
        return [
            self::ROLE_USER => __('Usuario de operación'),
            self::ROLE_ADMIN => __('Administrador'),
            self::ROLE_SUPER_ADMIN => __('Super administrador'),
        ];
    }

    /**
     * Roles de las cuentas del portal de cliente.
     *
     * @return array<int, string>
     */
    public static function clientRoles(): array
    {
        return [
            self::ROLE_CLIENT_READONLY => __('Cliente solo lectura'),
            self::ROLE_CLIENT_EDITOR => __('Editor o asociado'),
            self::ROLE_CLIENT_CUSTOMS_BROKER => __('Agente aduanal del cliente'),
        ];
    }

    /**
     * Roles de las cuentas del portal de proveedor.
     *
     * @return array<int, string>
     */
    public static function providerRoles(): array
    {
        return [
            self::ROLE_PROVIDER_CUSTOMS_BROKER => __('Agente aduanal'),
            self::ROLE_PROVIDER_CARRIER => __('Transportista'),
        ];
    }

    /**
     * Todos los roles, para filtros y listados.
     *
     * @return array<int, string>
     */
    public static function allRoles(): array
    {
        return self::internalRoles() + self::clientRoles() + self::providerRoles();
    }

    /**
     * Roles que admite un acceso: los de portal no pueden llevar rol interno ni
     * al revés, porque `isAdmin()` mira solo el rol.
     *
     * @return array<int, string>
     */
    public static function rolesForAccess(int $access): array
    {
        return match ($access) {
            self::ACCESS_CLIENT => self::clientRoles(),
            self::ACCESS_PROVIDER => self::providerRoles(),
            default => self::internalRoles(),
        };
    }

    /** Etiqueta del rol; un guion si el valor no es de los conocidos. */
    public function roleLabel(): string
    {
        return self::allRoles()[(int) $this->role] ?? '—';
    }

    /**
     * Solo los usuarios activos pueden autenticarse y seguir dentro.
     *
     * `users.status` es `tinyint(1) NULL DEFAULT 1` en la tabla heredada: una
     * cuenta sin el valor cargado (o construida en memoria, como en las
     * pruebas) cuenta como activa, igual que la trataría la base al insertarla.
     * Solo el 0 explícito es una baja.
     */
    public function isActive(): bool
    {
        return (bool) ($this->status ?? true);
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

    /**
     * Personal de la empresa. Las cuentas sin `access` se tratan como internas.
     *
     * Yii2 no las dejaba entrar (`findByUsername` exigía `access = 9`), pero hay
     * una cuenta real así que hoy trabaja, y no se bloquea a nadie por un dato
     * que nunca se capturó: el grid de usuarios la señala para corregirla.
     */
    public function isInternal(): bool
    {
        return ! $this->isPortal();
    }

    /** Cuenta heredada a la que nunca se le capturó desde dónde entra. */
    public function sinAccesoDefinido(): bool
    {
        return $this->access === null;
    }

    /**
     * Una cuenta dada de baja no recibe el enlace de recuperación. Fortify
     * responde lo mismo que si se hubiera enviado, así que no se delata su
     * estado; y si el enlace ya existía, `ResetUserPassword` lo rechaza.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        if ($this->isActive()) {
            parent::sendPasswordResetNotification($token);
        }
    }

    /** Quién dio de alta la cuenta (`created_by`, como en Yii2). */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by', 'usr_id');
    }

    /** Quién la tocó por última vez (`modified_by`). */
    public function modificador(): BelongsTo
    {
        return $this->belongsTo(self::class, 'modified_by', 'usr_id');
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
