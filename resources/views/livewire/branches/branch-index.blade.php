<div class="space-y-6">
    @if ($feedback)
        <div class="flex items-start gap-3 p-4 rounded-lg border text-base
            {{ $feedbackType === 'error'
                ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200'
                : 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200' }}">
            <x-icon name="{{ $feedbackType === 'error' ? 'warning' : 'check-circle' }}" class="w-5 h-5 mt-0.5" />
            <span class="flex-1">{{ $feedback }}</span>
            <button type="button" wire:click="dismissFeedback" class="opacity-60 hover:opacity-100" aria-label="Cerrar aviso">
                <x-icon name="x" class="w-4 h-4" />
            </button>
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <p class="text-gray-500 dark:text-gray-400">Puntos de recogida y entrega de encomiendas a nivel nacional.</p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo..."><x-icon name="plus" class="w-4 h-4" /> Nueva sucursal</x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar sucursal' : 'Nueva sucursal' }}</h2>

            @if ($codesLocked)
                <div class="mb-4 flex items-start gap-3 p-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 text-sm">
                    <x-icon name="warning" class="w-5 h-5 mt-0.5" />
                    <span>Esta sucursal ya emitió comprobantes electrónicos. Los códigos de Hacienda quedan fijos para no romper el consecutivo.</span>
                </div>
            @endif

            @error('is_active')
                <div class="mb-4 flex items-start gap-3 p-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm">
                    <x-icon name="warning" class="w-5 h-5 mt-0.5" />
                    <span>{{ $message }}</span>
                </div>
            @enderror

            <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Nombre</label>
                    <input type="text" wire:model="name" class="input @error('name') input-error @enderror">
                    @error('name') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Prefijo del código guía</label>
                    <input type="text" wire:model="prefix" maxlength="4" placeholder="SJ"
                           class="input uppercase @error('prefix') input-error @enderror">
                    @error('prefix') <p class="error-text">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Aparece en el código: <span class="font-mono">SJ-LIM-00005</span></p>
                </div>
                <div>
                    <label class="label">Código de sucursal (Hacienda)</label>
                    <input type="text" wire:model="sucursal_code" maxlength="3" inputmode="numeric" @disabled($codesLocked) class="input @error('sucursal_code') input-error @enderror">
                    @error('sucursal_code') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Código de terminal (Hacienda)</label>
                    <input type="text" wire:model="terminal_code" maxlength="5" inputmode="numeric" @disabled($codesLocked) class="input @error('terminal_code') input-error @enderror">
                    @error('terminal_code') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Dirección</label>
                    <input type="text" wire:model="address" class="input @error('address') input-error @enderror">
                </div>
                <div>
                    <label class="label">Provincia (1 dígito)</label>
                    <input type="text" wire:model="province" maxlength="1" inputmode="numeric" class="input @error('province') input-error @enderror">
                    @error('province') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Cantón (2 dígitos)</label>
                    <input type="text" wire:model="canton" maxlength="2" inputmode="numeric" class="input @error('canton') input-error @enderror">
                    @error('canton') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Distrito (2 dígitos)</label>
                    <input type="text" wire:model="district" maxlength="2" inputmode="numeric" class="input @error('district') input-error @enderror">
                    @error('district') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Teléfono</label>
                    <input type="text" wire:model="phone" class="input @error('phone') input-error @enderror">
                </div>

                <div class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" wire:model="is_active" class="checkbox">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Sucursal activa</span>
                    </label>
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
                        <th class="py-2">Prefijo</th>
                        <th class="py-2">Código Hacienda</th>
                        <th class="py-2">Dirección</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $branch)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 font-medium">{{ $branch->name }}</td>
                            <td class="py-3"><span class="badge bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-200 font-mono">{{ $branch->prefixLabel() }}</span></td>
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
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No hay sucursales registradas.</td></tr>
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
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Prefijo</span><span class="font-mono">{{ $branch->prefixLabel() }}</span></div>
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Código Hacienda</span><span>{{ $branch->sucursal_code }}/{{ $branch->terminal_code }}</span></div>
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
