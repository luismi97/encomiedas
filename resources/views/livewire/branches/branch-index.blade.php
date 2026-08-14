<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <p class="text-gray-500 dark:text-gray-400">Puntos de recogida y entrega de encomiendas a nivel nacional.</p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">➕ Nueva sucursal</x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar sucursal' : 'Nueva sucursal' }}</h2>
            <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="label">Nombre</label>
                    <input type="text" wire:model="name" class="input">
                    @error('name') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Código de sucursal (Hacienda)</label>
                    <input type="text" wire:model="sucursal_code" maxlength="3" class="input">
                    @error('sucursal_code') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Código de terminal (Hacienda)</label>
                    <input type="text" wire:model="terminal_code" maxlength="5" class="input">
                    @error('terminal_code') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Dirección</label>
                    <input type="text" wire:model="address" class="input">
                </div>
                <div>
                    <label class="label">Provincia (1 dígito)</label>
                    <input type="text" wire:model="province" maxlength="1" class="input">
                </div>
                <div>
                    <label class="label">Cantón (2 dígitos)</label>
                    <input type="text" wire:model="canton" maxlength="2" class="input">
                </div>
                <div>
                    <label class="label">Distrito (2 dígitos)</label>
                    <input type="text" wire:model="district" maxlength="2" class="input">
                </div>
                <div>
                    <label class="label">Teléfono</label>
                    <input type="text" wire:model="phone" class="input">
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
                        <th class="py-2">Código</th>
                        <th class="py-2">Dirección</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $branch)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 font-medium">{{ $branch->name }}</td>
                            <td class="py-3 text-sm">{{ $branch->sucursal_code }}/{{ $branch->terminal_code }}</td>
                            <td class="py-3 text-sm">{{ $branch->address }}</td>
                            <td class="py-3">
                                <x-action-button action="toggleActive({{ $branch->id }})" variant="link" loadingText="..."
                                    class="badge {{ $branch->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                    {{ $branch->is_active ? 'Activa' : 'Inactiva' }}
                                </x-action-button>
                            </td>
                            <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                <x-action-button action="edit({{ $branch->id }})" variant="link">Editar</x-action-button>
                                <x-action-button action="delete({{ $branch->id }})" variant="link-danger" confirm="¿Eliminar esta sucursal?">Eliminar</x-action-button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">No hay sucursales registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Tarjetas en móvil: evita tablas apelotadas en pantallas pequeñas -->
        <div class="md:hidden space-y-3">
            @forelse ($branches as $branch)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="font-semibold">{{ $branch->name }}</div>
                        <x-action-button action="toggleActive({{ $branch->id }})" variant="link" loadingText="..."
                            class="badge shrink-0 {{ $branch->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $branch->is_active ? 'Activa' : 'Inactiva' }}
                        </x-action-button>
                    </div>
                    <div class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Código</span><span>{{ $branch->sucursal_code }}/{{ $branch->terminal_code }}</span></div>
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Dirección</span><span class="text-right">{{ $branch->address ?: '—' }}</span></div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex gap-4">
                        <x-action-button action="edit({{ $branch->id }})" variant="link">Editar</x-action-button>
                        <x-action-button action="delete({{ $branch->id }})" variant="link-danger" confirm="¿Eliminar esta sucursal?">Eliminar</x-action-button>
                    </div>
                </div>
            @empty
                <div class="text-center text-gray-500 py-6">No hay sucursales registradas.</div>
            @endforelse
        </div>

        <div class="mt-4">{{ $branches->links() }}</div>
    </div>
</div>
