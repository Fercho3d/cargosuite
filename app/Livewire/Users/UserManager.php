<?php

namespace App\Livewire\Users;

use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Altas y accesos de usuarios.
 *
 * Equivale al `UserController` de Yii2 y, como él, **solo deja entrar al super
 * administrador**: la ruta lleva `EnsureUserIsSuperAdmin` y cada acción vuelve
 * a comprobarlo, porque las llamadas de Livewire no pasan por esa ruta.
 *
 * Hay dos conceptos que conviene no confundir:
 *  - **rol**: qué puede hacer dentro del sistema (9 usuario, 10 administrador,
 *    20 super administrador) o qué ve en el portal (12, 13 y 16 para clientes;
 *    14 y 15 para proveedores). Ver `User::rolesForAccess()`.
 *  - **acceso**: desde dónde entra (9 interno, 10 portal de cliente, 11 portal
 *    de proveedor). Los dos últimos van ligados a un cliente o a un proveedor.
 */
class UserManager extends Component
{
    use WithPagination;

    /*
     * Los valores de `access` viven en el modelo; aquí solo se reexportan para
     * que la vista no tenga que conocer dos clases.
     */
    public const ACCESS_INTERNAL = User::ACCESS_INTERNAL;

    public const ACCESS_CLIENT = User::ACCESS_CLIENT;

    public const ACCESS_PROVIDER = User::ACCESS_PROVIDER;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'rol', except: '')]
    public string $role = '';

    #[Url(as: 'estado', except: '')]
    public string $status = '';

    /** Id en edición, 0 para uno nuevo, null si no hay formulario. */
    public ?int $editing = null;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $userRole = '9';

    public string $access = '9';

    public string $partyId = '';

    public bool $active = true;

    public string $password = '';

    public string $passwordConfirmation = '';

    /** Usuario al que se le está cambiando la contraseña. */
    public ?int $changingPassword = null;

    /** La pantalla entera es del super administrador, no solo sus acciones. */
    public function mount(): void
    {
        $this->assertCanManageUsers();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Cada acceso tiene sus propios roles, así que al cambiarlo en el formulario
     * el rol elegido deja de valer y se pasa al primero de la nueva lista. El
     * cliente o proveedor ligado tampoco sirve para el otro acceso.
     */
    public function updatedAccess(): void
    {
        $roles = $this->rolesAsignables();

        if (! array_key_exists((int) $this->userRole, $roles)) {
            $this->userRole = (string) array_key_first($roles);
        }

        $this->partyId = '';
    }

    // ------------------------------------------------------------ Edición

    public function create(): void
    {
        $this->assertCanManageUsers();

        $this->reset(['name', 'username', 'email', 'partyId', 'password', 'passwordConfirmation']);
        $this->userRole = (string) User::ROLE_USER;
        $this->access = (string) self::ACCESS_INTERNAL;
        $this->active = true;
        $this->editing = 0;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->assertCanManageUsers();

        $usuario = User::findOrFail($id);

        $this->editing = $id;
        $this->name = (string) $usuario->name;
        $this->username = (string) $usuario->username;
        $this->email = (string) $usuario->email;
        $this->userRole = (string) $usuario->role;
        $this->access = (string) ($usuario->access ?: self::ACCESS_INTERNAL);
        $this->partyId = (string) ($usuario->client_id ?: $usuario->provider_id ?: '');
        $this->active = (bool) $usuario->status;
        $this->reset(['password', 'passwordConfirmation']);
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'changingPassword', 'password', 'passwordConfirmation']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->assertCanManageUsers();

        $esNuevo = $this->editing === 0;

        $objetivo = $esNuevo ? new User : User::findOrFail($this->editing);

        // Uno mismo no se da de baja ni se cambia el rol desde el formulario:
        // es la misma regla que ya protege el botón de baja del listado.
        $esMismo = ! $esNuevo && (int) $objetivo->usr_id === (int) auth()->id();

        // Sin un super administrador activo nadie podría volver a entrar aquí.
        $esUltimoSuperAdmin = ! $esNuevo && $this->esUltimoSuperAdminActivo($objetivo);

        $this->validate([
            'name' => ['nullable', 'string', 'max:100'],
            // `username` y `email` son únicos en la tabla heredada. El correo es
            // obligatorio porque por ahí va la recuperación de contraseña; la
            // forma solo se exige al capturarlo, porque la base heredada guarda
            // ahí decenas de valores que no son correos y no se puede impedir
            // editar esas cuentas.
            'username' => ['required', 'string', 'max:45', Rule::unique('users', 'username')->ignore($this->editing, 'usr_id')],
            'email' => [
                'required', 'string', 'max:45',
                Rule::when($esNuevo || $this->email !== (string) $objetivo->email, ['email']),
                Rule::unique('users', 'email')->ignore($this->editing, 'usr_id'),
            ],
            // Los roles asignables dependen del acceso elegido.
            'userRole' => [
                'required', Rule::in(array_keys($this->rolesAsignables())),
                function (string $attribute, mixed $value, Closure $fail) use ($esMismo, $esUltimoSuperAdmin, $objetivo) {
                    if ($esMismo && (int) $value !== (int) $objetivo->role) {
                        $fail(__('No puedes cambiar tu propio rol.'));
                    } elseif ($esUltimoSuperAdmin && (int) $value !== User::ROLE_SUPER_ADMIN) {
                        $fail(__('Es el único super administrador activo: nombra a otro antes de cambiarle el rol.'));
                    }
                },
            ],
            'access' => ['required', Rule::in([self::ACCESS_INTERNAL, self::ACCESS_CLIENT, self::ACCESS_PROVIDER])],
            // El cliente o proveedor ligado tiene que existir en su catálogo.
            'partyId' => [
                Rule::requiredIf($this->needsParty()), 'nullable',
                Rule::when($this->access === (string) self::ACCESS_CLIENT, ['exists:client,client_id']),
                Rule::when($this->access === (string) self::ACCESS_PROVIDER, ['exists:provider,provider_id']),
            ],
            'password' => [Rule::requiredIf($esNuevo), 'nullable', 'string', 'min:8', 'same:passwordConfirmation'],
            'active' => [
                function (string $attribute, mixed $value, Closure $fail) use ($esMismo, $esUltimoSuperAdmin) {
                    if ($esMismo && ! $value) {
                        $fail(__('No puedes darte de baja a ti mismo.'));
                    } elseif ($esUltimoSuperAdmin && ! $value) {
                        $fail(__('Es el único super administrador activo: nombra a otro antes de darlo de baja.'));
                    }
                },
            ],
        ], attributes: [
            'username' => __('usuario'),
            'email' => __('correo'),
            'userRole' => __('rol'),
            'access' => __('acceso'),
            'partyId' => $this->access === (string) self::ACCESS_CLIENT ? __('cliente') : __('proveedor'),
            'password' => __('contraseña'),
        ]);

        $usuario = $objetivo;

        // Auditoría, como la llevaba `User::beforeSave()` en Yii2: quién creó la
        // cuenta y quién la tocó por última vez. El grid las enseña.
        $usuario->forceFill([
            'created_by' => $esNuevo ? auth()->id() : $usuario->created_by,
            'modified_by' => auth()->id(),
            'name' => $this->name ?: null,
            'username' => $this->username,
            'email' => $this->email ?: null,
            'role' => (int) $this->userRole,
            'access' => (int) $this->access,
            'client_id' => $this->access === (string) self::ACCESS_CLIENT ? (int) $this->partyId : null,
            'provider_id' => $this->access === (string) self::ACCESS_PROVIDER ? (int) $this->partyId : null,
            'status' => $this->active ? 1 : 0,
        ]);

        if (! $this->active) {
            $usuario->remember_token = null;
        }

        if (filled($this->password)) {
            $usuario->password = $this->password;
        }

        $usuario->save();

        session()->flash('status', $esNuevo ? __('Usuario creado.') : __('Usuario actualizado.'));
        $this->cancel();
    }

    public function needsParty(): bool
    {
        return in_array((int) $this->access, [self::ACCESS_CLIENT, self::ACCESS_PROVIDER], true);
    }

    // ------------------------------------------------------- Contraseña

    public function startPasswordChange(int $id): void
    {
        $this->assertCanManageUsers();

        User::findOrFail($id);

        $this->changingPassword = $id;
        $this->reset(['password', 'passwordConfirmation']);
        $this->resetErrorBag();
    }

    public function changePassword(): void
    {
        $this->assertCanManageUsers();

        $objetivo = User::findOrFail($this->changingPassword);

        $this->validate([
            'password' => ['required', 'string', 'min:8', 'same:passwordConfirmation'],
        ], attributes: ['password' => __('contraseña')]);

        $objetivo->forceFill(['password' => $this->password, 'modified_by' => auth()->id()])->save();

        session()->flash('status', __('Contraseña actualizada.'));
        $this->cancel();
    }

    /**
     * Da de baja o reactiva un usuario. No se borra: la tabla la referencian las
     * columnas de auditoría de medio sistema.
     */
    public function toggleActive(int $id): void
    {
        $this->assertCanManageUsers();

        abort_if($id === auth()->id(), 422, __('No puedes darte de baja a ti mismo.'));

        $usuario = User::findOrFail($id);

        abort_if($usuario->status && $this->esUltimoSuperAdminActivo($usuario), 422,
            __('Es el único super administrador activo: nombra a otro antes de darlo de baja.'));

        // Al dar de baja se borra también el token de «recordarme»: si no, la
        // cookie reconstruiría la sesión aunque `EnsureUserIsActive` la cierre.
        $usuario->forceFill(($usuario->status
            ? ['status' => 0, 'remember_token' => null]
            : ['status' => 1]) + ['modified_by' => auth()->id()])->save();

        session()->flash('status', $usuario->status ? __('Usuario reactivado.') : __('Usuario dado de baja.'));
    }

    /**
     * Solo el super administrador maneja usuarios, igual que en Yii2. Un
     * administrador normal da de alta a su gente pidiéndoselo al dueño.
     */
    private function assertCanManageUsers(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403, __('Esta sección es solo para el super administrador'));
    }

    /**
     * ¿Es la única cuenta activa con rol de super administrador? `status` nulo
     * cuenta como activo, igual que en `User::isActive()`.
     */
    private function esUltimoSuperAdminActivo(User $usuario): bool
    {
        if (! $usuario->isSuperAdmin() || ! $usuario->isActive()) {
            return false;
        }

        return ! User::query()
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 0))
            ->whereKeyNot($usuario->usr_id)
            ->exists();
    }

    /**
     * Roles que se pueden asignar con el acceso elegido en el formulario.
     *
     * @return array<int, string>
     */
    public function rolesAsignables(): array
    {
        return User::rolesForAccess((int) $this->access);
    }

    // --------------------------------------------------------- Pintado

    public function render()
    {
        $usuarios = User::query()
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    foreach (['name', 'username', 'email'] as $columna) {
                        $w->orWhere($columna, 'like', '%'.$this->search.'%');
                    }
                });
            })
            ->when($this->role !== '', fn ($q) => $q->where('role', (int) $this->role))
            ->when($this->status !== '', fn ($q) => $q->where('status', (int) $this->status))
            ->with(['creador', 'modificador'])
            ->orderByDesc('status')
            ->orderBy('username')
            ->paginate(25, ['*'], 'page', $this->getPage());

        return view('livewire.users.user-manager', [
            'usuarios' => $usuarios,
            'clientes' => $this->access === (string) self::ACCESS_CLIENT ? Client::options() : [],
            'proveedores' => $this->access === (string) self::ACCESS_PROVIDER ? Provider::options() : [],
        ])->layout('components.app-layout', ['title' => __('Usuarios y accesos')]);
    }
}
