<div class="space-y-6">
    <x-flash />

    <div class="flex items-center justify-between flex-wrap gap-3">
        <p class="text-gray-500 dark:text-gray-400">Impuestos aplicables a las facturas de encomienda (ej. IVA 13%).</p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo..."><x-icon name="plus" class="w-4 h-4" /> Nuevo impuesto</x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar impuesto' : 'Nuevo impuesto' }}</h2>
            <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Nombre</label>
                    <input type="text" wire:model="name" class="input" placeholder="IVA general">
                    @error('name') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Porcentaje (%)</label>
                    <input type="number" step="0.01" wire:model="percent" class="input">
                    @error('percent') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Código de tarifa Hacienda</label>
                    <select wire:model="hacienda_code" class="input">
                        <option value="01">01 - 0% (Tarifa 0%)</option>
                        <option value="02">02 - 1%</option>
                        <option value="03">03 - 2%</option>
                        <option value="04">04 - 4%</option>
                        <option value="08">08 - 13% (General)</option>
                        <option value="09">09 - 0.5%</option>
                        <option value="10">10 - Exento</option>
                    </select>
                </div>
                <div class="flex items-end gap-4">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="is_default" class="rounded"> Predeterminado</label>
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
                        <th class="py-2">%</th>
                        <th class="py-2">Código</th>
                        <th class="py-2">Predeterminado</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($taxes as $tax)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 font-medium">{{ $tax->name }}</td>
                            <td class="py-3">{{ number_format($tax->percent, 2) }}%</td>
                            <td class="py-3">{{ $tax->hacienda_code }}</td>
                            <td class="py-3">@if ($tax->is_default)<span class="badge bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-200"><x-icon name="star" solid class="w-3.5 h-3.5 mr-1" /> Predeterminado</span>@endif</td>
                            <td class="py-3">
                                <span class="badge {{ $tax->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                    {{ $tax->is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                <x-action-button action="edit({{ $tax->id }})" variant="link">Editar</x-action-button>
                                <x-action-button action="delete({{ $tax->id }})" variant="link-danger" confirm="¿Eliminar este impuesto?">Eliminar</x-action-button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No hay impuestos configurados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @forelse ($taxes as $tax)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="font-semibold flex items-center gap-1.5">{{ $tax->name }}@if ($tax->is_default)<x-icon name="star" solid class="w-4 h-4 text-brand-500" /><span class="sr-only">Predeterminado</span>@endif</div>
                        <span class="badge shrink-0 {{ $tax->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $tax->is_active ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>
                    <div class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Porcentaje</span><span>{{ number_format($tax->percent, 2) }}%</span></div>
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Código Hacienda</span><span>{{ $tax->hacienda_code }}</span></div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex gap-4">
                        <x-action-button action="edit({{ $tax->id }})" variant="link">Editar</x-action-button>
                        <x-action-button action="delete({{ $tax->id }})" variant="link-danger" confirm="¿Eliminar este impuesto?">Eliminar</x-action-button>
                    </div>
                </div>
            @empty
                <div class="text-center text-gray-500 py-6">No hay impuestos configurados.</div>
            @endforelse
        </div>
    </div>
</div>
