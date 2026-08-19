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
        <p class="text-gray-500 dark:text-gray-400">
            Cada caja lleva su propio turno y su propio arqueo. Dos cajeros cobrando a la vez en la
            misma sede necesitan una caja cada uno, o el faltante de uno aparece en el conteo del otro.
        </p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">
            <x-icon name="plus" class="w-4 h-4" /> Nueva caja
        </x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar caja' : 'Nueva caja' }}</h2>

            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Sede</label>
                        <select wire:model.live="branch_id" class="input @error('branch_id') input-error @enderror">
                            <option value="">Elegir sede...</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->prefixLabel() }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Nombre</label>
                        <input type="text" wire:model="name" placeholder="Ej. Mostrador 2"
                               class="input @error('name') input-error @enderror">
                        @error('name') <p class="error-text">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-500 mt-1">Es lo que el cajero elige al abrir el turno.</p>
                    </div>
                </div>

                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="is_active" class="checkbox">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Caja activa</span>
                </label>

                <div class="flex gap-3 pt-2">
                    <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando...">Guardar</x-action-button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    @forelse ($sedes as $sede)
        <div class="card">
            <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
                <h2 class="text-lg font-semibold">
                    {{ $sede->name }}
                    <span class="text-sm font-mono font-normal text-gray-500">{{ $sede->prefixLabel() }}</span>
                </h2>
                <span class="text-sm text-gray-500">
                    {{ $sede->cashRegisters->count() }}
                    {{ $sede->cashRegisters->count() === 1 ? 'caja' : 'cajas' }}
                </span>
            </div>

            <div class="data-table-wrap">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2">Caja</th>
                            <th class="py-2">Turno</th>
                            <th class="py-2">Estado</th>
                            <th class="py-2 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sede->cashRegisters as $caja)
                            <tr wire:key="caja-{{ $caja->id }}" class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="py-3 font-medium">{{ $caja->name }}</td>
                                <td class="py-3 text-sm">
                                    @if ($caja->estaAbierta())
                                        <span class="badge bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200">Abierto</span>
                                    @else
                                        <span class="text-gray-500">Cerrado</span>
                                    @endif
                                </td>
                                <td class="py-3">
                                    <x-action-button action="toggleActive({{ $caja->id }})" variant="link" loadingText="..."
                                        class="badge {{ $caja->is_active
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                        {{ $caja->is_active ? 'Activa' : 'Inactiva' }}
                                    </x-action-button>
                                </td>
                                <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                    <x-action-button action="edit({{ $caja->id }})" variant="link">Editar</x-action-button>
                                    <x-action-button action="delete({{ $caja->id }})" variant="link-danger"
                                        confirm="¿Eliminar «{{ $caja->name }}»?">Eliminar</x-action-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 text-center text-gray-500">
                                    Esta sede no tiene ninguna caja: no puede cobrar de contado.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="md:hidden space-y-3">
                @foreach ($sede->cashRegisters as $caja)
                    <div wire:key="caja-movil-{{ $caja->id }}" class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="font-semibold">{{ $caja->name }}</div>
                            <span class="badge {{ $caja->is_active
                                ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                {{ $caja->is_active ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>
                        <div class="mt-2 text-sm text-gray-500">
                            Turno {{ $caja->estaAbierta() ? 'abierto' : 'cerrado' }}
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex gap-4">
                            <x-action-button action="edit({{ $caja->id }})" variant="link">Editar</x-action-button>
                            <x-action-button action="delete({{ $caja->id }})" variant="link-danger"
                                confirm="¿Eliminar «{{ $caja->name }}»?">Eliminar</x-action-button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="card text-center text-gray-500 py-8">
            No hay sucursales registradas todavía.
        </div>
    @endforelse
</div>
