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
        <p class="text-gray-500 dark:text-gray-400 max-w-2xl">
            Los pares de sedes que se repiten todos los días. El cajero elige la ruta y el
            formulario rellena origen y destino, sin tocar dos desplegables por encomienda.
        </p>
        <div class="flex items-center gap-2">
            <x-action-button action="create" variant="primary" loadingText="Abriendo..."
                data-ayuda="rutas-nueva">
                <x-icon name="plus" class="w-4 h-4" /> Nueva ruta
            </x-action-button>
            <x-ayuda posicion="izquierda">Define un par origen–destino con nombre propio. Después aparece como una sola opción al crear guías y cierres.</x-ayuda>
        </div>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar ruta' : 'Nueva ruta' }}</h2>

            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="label">Nombre</label>
                        <input type="text" wire:model="name" placeholder="Ej. Limón directo"
                               class="input @error('name') input-error @enderror">
                        @error('name') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Sede de origen</label>
                        <select wire:model="origin_branch_id" class="input @error('origin_branch_id') input-error @enderror">
                            <option value="">Seleccione...</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('origin_branch_id') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Sede de destino</label>
                        <select wire:model="destination_branch_id" class="input @error('destination_branch_id') input-error @enderror">
                            <option value="">Seleccione...</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('destination_branch_id') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">
                            Días de tránsito
                            <x-ayuda>Cuánto tarda normalmente. Con esto se le promete una fecha al cliente y el control nocturno sabe cuándo una guía de esta ruta va tarde de verdad.</x-ayuda>
                        </label>
                        <input type="number" wire:model="transit_days" min="1" max="60" placeholder="Opcional"
                               class="input @error('transit_days') input-error @enderror">
                        @error('transit_days') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                </div>

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
                        <th class="py-2">Ruta</th>
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Tránsito</th>
                        <th class="py-2">Guías</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody data-test="rutas">
                    @forelse ($rutas as $ruta)
                        <tr wire:key="ruta-{{ $ruta->id }}" class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 font-mono">{{ $ruta->rutaLabel() }}</td>
                            <td class="py-3 font-medium">{{ $ruta->name }}</td>
                            <td class="py-3 text-sm text-gray-500">{{ $ruta->transitoLabel() ?? '—' }}</td>
                            <td class="py-3 text-sm text-gray-500 tabular-nums">{{ $ruta->invoices_count }}</td>
                            <td class="py-3">
                                <x-action-button action="toggleActive({{ $ruta->id }})" variant="link" loadingText="..."
                                    class="badge {{ $ruta->is_active
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                        : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                    {{ $ruta->is_active ? 'Activa' : 'Inactiva' }}
                                </x-action-button>
                            </td>
                            <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                <x-action-button action="edit({{ $ruta->id }})" variant="link">Editar</x-action-button>
                                <x-action-button action="delete({{ $ruta->id }})" variant="link-danger"
                                    confirm="¿Eliminar la ruta «{{ $ruta->name }}»?">Eliminar</x-action-button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">
                            Todavía no hay rutas. Creá las que más se repiten y el mostrador deja de elegir dos sedes por encomienda.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @foreach ($rutas as $ruta)
                <div wire:key="ruta-movil-{{ $ruta->id }}" class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-mono text-sm text-gray-500">{{ $ruta->rutaLabel() }}</div>
                            <div class="font-semibold">{{ $ruta->name }}</div>
                        </div>
                        <span class="badge {{ $ruta->is_active
                            ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $ruta->is_active ? 'Activa' : 'Inactiva' }}
                        </span>
                    </div>
                    <div class="mt-2 text-sm text-gray-500">
                        {{ $ruta->transitoLabel() ? 'Tránsito de ' . $ruta->transitoLabel() : 'Sin tránsito definido' }}
                        · {{ $ruta->invoices_count }} guía(s)
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex gap-4">
                        <x-action-button action="edit({{ $ruta->id }})" variant="link">Editar</x-action-button>
                        <x-action-button action="delete({{ $ruta->id }})" variant="link-danger"
                            confirm="¿Eliminar la ruta «{{ $ruta->name }}»?">Eliminar</x-action-button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
