<?php

namespace App\Livewire\Users;

use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Altas y accesos de usuarios.
 *
 * Equivale al `UserController` de Yii2, que —como aquí— **solo deja entrar al
 * super administrador**.
 *
 * Hay dos conceptos que conviene no confundir:
 *  - **rol**: qué puede hacer dentro del sistema (9 usuario, 10 administrador,
 *    20 super administrador).
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
        $this->assertSuperAdmin();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // ------------------------------------------------------------ Edición

    public function create(): void
    {
        $this->assertSuperAdmin();

        $this->reset(['name', 'username', 'email', 'partyId', 'password', 'passwordConfirmation']);
        $this->userRole = (string) User::ROLE_USER;
        $this->access = (string) self::ACCESS_INTERNAL;
        $this->active = true;
        $this->editing = 0;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->assertSuperAdmin();

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
        $this->assertSuperAdmin();

        $esNuevo = $this->editing === 0;

        $this->validate([
            'name' => ['nullable', 'string', 'max:100'],
            // `username` y `email` son únicos en la tabla heredada.
            'username' => ['required', 'string', 'max:45', Rule::unique('users', 'username')->ignore($this->editing, 'usr_id')],
            'email' => ['nullable', 'string', 'max:45', Rule::unique('users', 'email')->ignore($this->editing, 'usr_id')],
            'userRole' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])],
            'access' => ['required', Rule::in([self::ACCESS_INTERNAL, self::ACCESS_CLIENT, self::ACCESS_PROVIDER])],
            'partyId' => [Rule::requiredIf($this->needsParty()), 'nullable'],
            'password' => [Rule::requiredIf($esNuevo), 'nullable', 'string', 'min:8', 'same:passwordConfirmation'],
        ], attributes: [
            'username' => 'usuario',
            'email' => 'correo',
            'userRole' => 'rol',
            'access' => 'acceso',
            'partyId' => $this->access === (string) self::ACCESS_CLIENT ? 'cliente' : 'proveedor',
            'password' => __('contraseña'),
        ]);

        $usuario = $esNuevo ? new User : User::findOrFail($this->editing);

        $usuario->forceFill([
            'name' => $this->name ?: null,
            'username' => $this->username,
            'email' => $this->email ?: null,
            'role' => (int) $this->userRole,
            'access' => (int) $this->access,
            'client_id' => $this->access === (string) self::ACCESS_CLIENT ? (int) $this->partyId : null,
            'provider_id' => $this->access === (string) self::ACCESS_PROVIDER ? (int) $this->partyId : null,
            'status' => $this->active ? 1 : 0,
        ]);

        if (filled($this->password)) {
            $usuario->password = $this->password;
        }

        $usuario->save();

        session()->flash('status', $esNuevo ? 'Usuario creado.' : 'Usuario actualizado.');
        $this->cancel();
    }

    public function needsParty(): bool
    {
        return in_array((int) $this->access, [self::ACCESS_CLIENT, self::ACCESS_PROVIDER], true);
    }

    // ------------------------------------------------------- Contraseña

    public function startPasswordChange(int $id): void
    {
        $this->assertSuperAdmin();

        User::findOrFail($id);

        $this->changingPassword = $id;
        $this->reset(['password', 'passwordConfirmation']);
        $this->resetErrorBag();
    }

    public function changePassword(): void
    {
        $this->assertSuperAdmin();

        $this->validate([
            'password' => ['required', 'string', 'min:8', 'same:passwordConfirmation'],
        ], attributes: ['password' => __('contraseña')]);

        User::findOrFail($this->changingPassword)
            ->forceFill(['password' => $this->password])
            ->save();

        session()->flash('status', __('Contraseña actualizada.'));
        $this->cancel();
    }

    /**
     * Da de baja o reactiva un usuario. No se borra: la tabla la referencian las
     * columnas de auditoría de medio sistema.
     */
    public function toggleActive(int $id): void
    {
        $this->assertSuperAdmin();

        abort_if($id === auth()->id(), 422, __('No puedes darte de baja a ti mismo.'));

        $usuario = User::findOrFail($id);
        $usuario->forceFill(['status' => $usuario->status ? 0 : 1])->save();

        session()->flash('status', $usuario->status ? 'Usuario reactivado.' : __('Usuario dado de baja.'));
    }

    private function assertSuperAdmin(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);
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
