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
            Es lo que el cajero elige al recibir cada bulto. El orden acá es el orden del
            desplegable: poné arriba los que más se reciben.
        </p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">
            <x-icon name="plus" class="w-4 h-4" /> Nuevo tipo
        </x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar tipo' : 'Nuevo tipo de bulto' }}</h2>

            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="label">Nombre</label>
                        <input type="text" wire:model="name" placeholder="Ej. Llanta"
                               class="input @error('name') input-error @enderror">
                        @error('name') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Orden</label>
                        <input type="number" wire:model="sort_order" min="0"
                               class="input @error('sort_order') input-error @enderror">
                        @error('sort_order') <p class="error-text">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-500 mt-1">Menor aparece primero</p>
                    </div>
                </div>

                <label class="inline-flex items-start gap-2">
                    <input type="checkbox" wire:model="is_fragile" class="checkbox mt-0.5">
                    <span class="text-sm text-gray-700 dark:text-gray-300">
                        <span class="font-medium">Marcar como frágil</span>
                        <span class="block text-xs text-gray-500">
                            La etiqueta del paquete sale con el aviso FRÁGIL para quien lo carga.
                        </span>
                    </span>
                </label>

                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="is_active" class="checkbox">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Se ofrece al crear guías</span>
                </label>

                <div class="flex gap-3 pt-2">
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
                        <th class="py-2">Orden</th>
                        <th class="py-2">Tipo</th>
                        <th class="py-2">Bultos registrados</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tipos as $tipo)
                        <tr wire:key="tipo-{{ $tipo->id }}" class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 text-sm text-gray-500 tabular-nums">{{ $tipo->sort_order }}</td>
                            <td class="py-3 font-medium">
                                {{ $tipo->name }}
                                @if ($tipo->is_fragile)
                                    <span class="badge bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Frágil</span>
                                @endif
                            </td>
                            <td class="py-3 text-sm text-gray-500 tabular-nums">{{ $tipo->items_count }}</td>
                            <td class="py-3">
                                <x-action-button action="toggleActive({{ $tipo->id }})" variant="link" loadingText="..."
                                    class="badge {{ $tipo->is_active
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                        : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                    {{ $tipo->is_active ? 'Activo' : 'Inactivo' }}
                                </x-action-button>
                            </td>
                            <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                <x-action-button action="edit({{ $tipo->id }})" variant="link">Editar</x-action-button>
                                <x-action-button action="delete({{ $tipo->id }})" variant="link-danger"
                                    confirm="¿Eliminar «{{ $tipo->name }}»?">Eliminar</x-action-button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">No hay tipos de bulto configurados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @foreach ($tipos as $tipo)
                <div wire:key="tipo-movil-{{ $tipo->id }}" class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="font-semibold">
                            {{ $tipo->name }}
                            @if ($tipo->is_fragile)
                                <span class="badge bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Frágil</span>
                            @endif
                        </div>
                        <span class="badge {{ $tipo->is_active
                            ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $tipo->is_active ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>
                    <div class="mt-2 text-sm text-gray-500">{{ $tipo->items_count }} bultos registrados</div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex gap-4">
                        <x-action-button action="edit({{ $tipo->id }})" variant="link">Editar</x-action-button>
                        <x-action-button action="delete({{ $tipo->id }})" variant="link-danger"
                            confirm="¿Eliminar «{{ $tipo->name }}»?">Eliminar</x-action-button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
