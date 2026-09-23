@use('App\Models\User')
@use('App\Livewire\Users\UserManager')

@php
    // Todos los roles, internos y de portal, para el filtro del listado.
    $roles = User::allRoles();

    $accesos = [
        UserManager::ACCESS_INTERNAL => __('Interno'),
        UserManager::ACCESS_CLIENT => __('Portal de cliente'),
        UserManager::ACCESS_PROVIDER => __('Portal de proveedor'),
    ];

    // El select de rol depende del acceso elegido: `access` es `.live`, así que
    // al cambiarlo la pantalla se vuelve a pintar con la lista que toca.
    $rolesForm = $this->rolesAsignables();
@endphp

<div class="space-y-4">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Usuarios y accesos') }}</h2>
            <p class="text-sm text-ink-muted">
                {!! __('El <strong>rol</strong> dice qué puede hacer; el <strong>acceso</strong>, desde dónde entra.') !!}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" value="{{ $search }}"
                   class="field-input py-1.5 text-sm" placeholder="{{ __('Buscar…') }}">

            <select wire:model.live="role" class="field-input !w-auto py-1.5 text-sm">
                <option value="">{{ __('Todos los roles') }}</option>
                @foreach ($roles as $valor => $etiqueta)
                    <option value="{{ $valor }}" @selected((string) $valor === $role)>{{ $etiqueta }}</option>
                @endforeach
            </select>

            <select wire:model.live="status" class="field-input !w-auto py-1.5 text-sm">
                @foreach (['' => __('Todos'), '1' => __('Activos'), '0' => __('De baja')] as $valor => $etiqueta)
                    <option value="{{ $valor }}" @selected((string) $valor === $status)>{{ $etiqueta }}</option>
                @endforeach
            </select>

            <button type="button" wire:click="create" class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Agregar') }}</button>
        </div>
    </header>

    {{-- Formulario de alta y edición --}}
    @if ($editing !== null)
        <form wire:submit="save" class="card space-y-4 p-5">
            <p class="text-sm font-medium text-ink">{{ $editing === 0 ? __('Nuevo usuario') : __('Editar usuario') }}</p>

            @include('partials.validation-errors')

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block">
                    <span class="field-label">{{ __('Nombre') }}</span>
                    <input type="text" wire:model="name" value="{{ $name }}" class="field-input mt-1.5">
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Usuario') }}</span>
                    <input type="text" wire:model="username" value="{{ $username }}" class="field-input mt-1.5" required>
                    @error('username') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Correo') }}</span>
                    <input type="text" wire:model="email" value="{{ $email }}" class="field-input mt-1.5">
                    @error('email') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Rol') }}</span>
                    <select wire:model="userRole" class="field-input mt-1.5">
                        @foreach ($rolesForm as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected((string) $valor === $userRole)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Acceso') }}</span>
                    <select wire:model.live="access" class="field-input mt-1.5">
                        @foreach ($accesos as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected((string) $valor === $access)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($this->needsParty())
                    <label class="block">
                        <span class="field-label">{{ $access === (string) UserManager::ACCESS_CLIENT ? __('Cliente') : __('Proveedor') }}</span>
                        <select wire:model="partyId" class="field-input mt-1.5" required>
                            <option value="">{{ __('Selecciona') }}</option>
                            @foreach (($access === (string) UserManager::ACCESS_CLIENT ? $clientes : $proveedores) as $id => $nombre)
                                <option value="{{ $id }}" @selected((string) $id === $partyId)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                        @error('partyId') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>
                @endif

                <label class="block">
                    <span class="field-label">{{ __('Contraseña') }} {{ $editing === 0 ? '' : __('(dejar vacía para no cambiarla)') }}</span>
                    <x-password-input wire:model="password" wrapper="mt-1.5" autocomplete="new-password" />
                    @error('password') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Repetir contraseña') }}</span>
                    <x-password-input wire:model="passwordConfirmation" wrapper="mt-1.5" autocomplete="new-password" />
                </label>

                {{-- Nadie se da de baja a sí mismo, tampoco desde aquí. --}}
                @if ($editing !== auth()->id())
                    <label class="flex items-end gap-2 pb-2.5 text-sm text-ink-soft">
                        <input type="checkbox" wire:model="active" @checked($active)
                               class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                        {{ __('Activo') }}
                    </label>
                @endif
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
                <button type="button" wire:click="cancel" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-accent !px-3 !py-1.5 text-xs">
                    <x-spinner wire:loading wire:target="save" class="h-3.5 w-3.5" />
                    {{ __('Guardar') }}
                </button>
            </div>
        </form>
    @endif

    {{-- Cambio de contraseña --}}
    @if ($changingPassword !== null)
        <form wire:submit="changePassword" class="card space-y-4 p-5">
            <p class="text-sm font-medium text-ink">{{ __('Cambiar contraseña') }}</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="field-label">{{ __('Contraseña nueva') }}</span>
                    <x-password-input wire:model="password" wrapper="mt-1.5" autocomplete="new-password" required />
                    @error('password') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Repetir contraseña') }}</span>
                    <x-password-input wire:model="passwordConfirmation" wrapper="mt-1.5" autocomplete="new-password" required />
                </label>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
                <button type="button" wire:click="cancel" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</button>
                <button type="submit" class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Guardar contraseña') }}</button>
            </div>
        </form>
    @endif

    {{-- Listado --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" /> {{ __('Actualizando…') }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Usuario') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Nombre') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Correo') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Rol') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Acceso') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Estado') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Último ingreso') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Creado por / el') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Modificado por / el') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($usuarios as $usuario)
                        <tr class="transition hover:bg-raised {{ $usuario->status ? '' : 'opacity-60' }}">
                            <td class="max-w-[18rem] truncate px-4 py-2 text-ink" title="{{ $usuario->username }}">{{ $usuario->username ?: '—' }}</td>
                            <td class="max-w-[14rem] truncate px-4 py-2 text-ink-muted">{{ $usuario->name ?: '—' }}</td>
                            <td class="max-w-[16rem] truncate px-4 py-2 text-ink-muted" title="{{ $usuario->email }}">{{ $usuario->email ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $usuario->roleLabel() }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">
                                {{-- Una cuenta heredada sin `access` entra como interna, pero se
                                     señala para que alguien lo capture desde «Editar». --}}
                                @if ($usuario->sinAccesoDefinido())
                                    <span class="badge badge-warn" title="{{ __('Entra como interno hasta que se le asigne un acceso.') }}">{{ __('Sin acceso definido') }}</span>
                                @else
                                    {{ $accesos[(int) $usuario->access] ?? __('Interno') }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <span class="badge {{ $usuario->status ? 'badge-ok' : 'badge-neutral' }}">
                                    {{ $usuario->status ? __('Activo') : __('De baja') }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">
                                {{ $usuario->last_login ? $usuario->last_login->format('d/m/Y H:i') : '—' }}
                            </td>
                            {{-- Auditoría, como en el grid original: quién y cuándo. --}}
                            @foreach ([[$usuario->creador, $usuario->created_at], [$usuario->modificador, $usuario->modified_at]] as [$quien, $cuando])
                                <td class="whitespace-nowrap px-4 py-2 text-xs text-ink-muted">
                                    @if ($quien || $cuando)
                                        <span class="text-ink">{{ $quien?->username ?? '—' }}</span>
                                        <span class="text-ink-faint">{{ $cuando?->format('d/m/Y H:i') ?? '—' }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <div class="flex justify-end gap-3 text-xs">
                                    <button type="button" wire:click="edit({{ $usuario->usr_id }})" class="text-brand hover:underline">{{ __('Editar') }}</button>
                                    <button type="button" wire:click="startPasswordChange({{ $usuario->usr_id }})" class="text-ink-muted hover:text-ink">{{ __('Contraseña') }}</button>
                                    @if ($usuario->usr_id !== auth()->id())
                                        <button type="button" wire:click="toggleActive({{ $usuario->usr_id }})"
                                                wire:confirm="{{ $usuario->status ? '¿Dar de baja a este usuario?' : '¿Reactivar a este usuario?' }}"
                                                class="text-ink-muted transition hover:text-brand">
                                            {{ $usuario->status ? __('Baja') : __('Reactivar') }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-12 text-center text-ink-faint">{{ __('No hay usuarios con estos filtros.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $usuarios->links() }}</div>
</div>
