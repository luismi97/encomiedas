<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <p class="text-gray-500 dark:text-gray-400">Administradores y repartidores del sistema.</p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">➕ Nuevo usuario</x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar usuario' : 'Nuevo usuario' }}</h2>
            <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Nombre completo</label>
                    <input type="text" wire:model="name" class="input">
                    @error('name') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Usuario (para iniciar sesión)</label>
                    <input type="text" wire:model="username" class="input" placeholder="jrepartidor">
                    @error('username') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Correo electrónico</label>
                    <input type="email" wire:model="email" class="input">
                    @error('email') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Contraseña {{ $editingId ? '(dejar en blanco para no cambiar)' : '' }}</label>
                    <input type="password" wire:model="password" class="input">
                    @error('password') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Rol</label>
                    <select wire:model="role" class="input">
                        <option value="admin">Administrador</option>
                        <option value="repartidor">Repartidor</option>
                    </select>
                </div>
                <div>
                    <label class="label">Sucursal base</label>
                    <select wire:model="branch_id" class="input">
                        <option value="">— Sin asignar —</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Teléfono</label>
                    <input type="text" wire:model="phone" class="input">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="is_active" class="rounded"> Activo</label>
                </div>

                <div class="sm:col-span-2 flex gap-3 pt-2">
                    <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando...">Guardar</x-action-button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card">
        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Usuario / Correo</th>
                        <th class="py-2">Rol</th>
                        <th class="py-2">Sucursal</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 font-medium">{{ $user->name }}</td>
                            <td class="py-3 text-sm">
                                {{ $user->username ? '@' . $user->username : '—' }}
                                <div class="text-gray-500">{{ $user->email }}</div>
                            </td>
                            <td class="py-3">
                                <span class="badge {{ $user->isAdmin() ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' }}">
                                    {{ $user->isAdmin() ? 'Administrador' : 'Repartidor' }}
                                </span>
                            </td>
                            <td class="py-3 text-sm">{{ $user->branch?->name ?? '—' }}</td>
                            <td class="py-3">
                                <x-action-button action="toggleActive({{ $user->id }})" variant="link" loadingText="..."
                                    class="badge {{ $user->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                    {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                                </x-action-button>
                            </td>
                            <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                <x-action-button action="edit({{ $user->id }})" variant="link">Editar</x-action-button>
                                <x-action-button action="delete({{ $user->id }})" variant="link-danger" confirm="¿Eliminar este usuario?">Eliminar</x-action-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @foreach ($users as $user)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold">{{ $user->name }}</div>
                            <div class="text-sm text-gray-500">{{ $user->username ? '@' . $user->username . ' · ' : '' }}{{ $user->email }}</div>
                        </div>
                        <x-action-button action="toggleActive({{ $user->id }})" variant="link" loadingText="..."
                            class="badge shrink-0 {{ $user->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                        </x-action-button>
                    </div>
                    <div class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Rol</span><span>{{ $user->isAdmin() ? 'Administrador' : 'Repartidor' }}</span></div>
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Sucursal</span><span>{{ $user->branch?->name ?? '—' }}</span></div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex gap-4">
                        <x-action-button action="edit({{ $user->id }})" variant="link">Editar</x-action-button>
                        <x-action-button action="delete({{ $user->id }})" variant="link-danger" confirm="¿Eliminar este usuario?">Eliminar</x-action-button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $users->links() }}</div>
    </div>
</div>
