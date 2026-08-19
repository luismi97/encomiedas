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
            Precio por ruta y rango de peso. Dejar una sede en «cualquiera» crea una tarifa base sin declarar todas las combinaciones.
        </p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">
            <x-icon name="plus" class="w-4 h-4" /> Nueva tarifa
        </x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar tarifa' : 'Nueva tarifa' }}</h2>

            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Nombre (opcional)</label>
                        <input type="text" wire:model="name" placeholder="Ej. Metropolitana liviana" class="input">
                    </div>
                    <div>
                        <label class="label">Tipo de envío</label>
                        <select wire:model="shipment_type" class="input">
                            <option value="">Todos</option>
                            @foreach (\App\Models\Rate::SHIPMENT_TYPES as $valor => $etiqueta)
                                <option value="{{ $valor }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Sede origen</label>
                        <select wire:model="origin_branch_id" class="input @error('origin_branch_id') input-error @enderror">
                            <option value="">Cualquiera</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->prefixLabel() }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Sede destino</label>
                        <select wire:model="destination_branch_id"
                                class="input @error('destination_branch_id') input-error @enderror">
                            <option value="">Cualquiera</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->prefixLabel() }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('destination_branch_id') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div>
                        <label class="label">Peso desde (kg)</label>
                        <input type="number" step="0.01" wire:model="min_weight"
                               class="input @error('min_weight') input-error @enderror">
                        @error('min_weight') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Peso hasta (kg)</label>
                        <input type="number" step="0.01" wire:model="max_weight" placeholder="sin tope"
                               class="input @error('max_weight') input-error @enderror">
                        @error('max_weight') <p class="error-text">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-500 mt-1">Vacío = tramo abierto</p>
                    </div>
                    <div>
                        <label class="label">Precio (₡)</label>
                        <input type="number" step="0.01" wire:model="price"
                               class="input @error('price') input-error @enderror">
                        @error('price') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">₡ por kg adicional</label>
                        <input type="number" step="0.01" wire:model="price_per_extra_kg"
                               class="input @error('price_per_extra_kg') input-error @enderror">
                        @error('price_per_extra_kg') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                </div>

                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="is_active" class="checkbox">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Tarifa activa</span>
                </label>

                <div class="flex gap-3 pt-2">
                    <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando...">Guardar</x-action-button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card">
        <h2 class="text-lg font-semibold mb-1">Probar una cotización</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
            Verificá qué tarifa gana antes de que la use un cajero. Se cobra por el mayor entre el peso real y el volumétrico.
        </p>

        <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
            <div class="col-span-2 sm:col-span-1">
                <label class="label">Origen</label>
                <select wire:model="probe_origin" class="input">
                    <option value="">—</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->prefixLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="label">Destino</label>
                <select wire:model="probe_destination" class="input">
                    <option value="">—</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->prefixLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="label">Peso (kg)</label><input type="number" step="0.01" wire:model="probe_weight" class="input"></div>
            <div><label class="label">Largo (cm)</label><input type="number" step="0.1" wire:model="probe_length" class="input"></div>
            <div><label class="label">Ancho (cm)</label><input type="number" step="0.1" wire:model="probe_width" class="input"></div>
            <div><label class="label">Alto (cm)</label><input type="number" step="0.1" wire:model="probe_height" class="input"></div>
        </div>

        <div class="mt-3">
            <x-action-button action="probe" variant="secondary" loadingText="Calculando...">Cotizar</x-action-button>
        </div>

        @if ($probeResult)
            <div class="mt-4 rounded-lg border {{ $probeResult['tarifa']
                ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/30'
                : 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30' }} p-4">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                    <div><span class="text-gray-500 block">Peso real</span>{{ $probeResult['peso_real'] }} kg</div>
                    <div><span class="text-gray-500 block">Peso volumétrico</span>{{ $probeResult['peso_volumetrico'] }} kg</div>
                    <div><span class="text-gray-500 block">Se cobra por</span><strong>{{ $probeResult['peso_facturable'] }} kg</strong></div>
                    <div>
                        <span class="text-gray-500 block">Precio</span>
                        @if ($probeResult['precio'] !== null)
                            <strong class="text-lg">₡{{ number_format($probeResult['precio'], 2) }}</strong>
                        @else
                            <span class="text-amber-700 dark:text-amber-300">—</span>
                        @endif
                    </div>
                </div>
                @if ($probeResult['tarifa'])
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        Aplica: <strong>{{ $probeResult['tarifa']->name ?: $probeResult['tarifa']->rutaLabel() }}</strong>
                        · {{ $probeResult['tarifa']->rutaLabel() }} · {{ $probeResult['tarifa']->pesoLabel() }}
                    </p>
                @else
                    <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">{{ $probeResult['motivo'] }}</p>
                @endif
            </div>
        @endif
    </div>

    <div class="card">
        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Tarifa</th>
                        <th class="py-2">Ruta</th>
                        <th class="py-2">Tipo</th>
                        <th class="py-2">Peso</th>
                        <th class="py-2 text-right">Precio</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rates as $rate)
                        <tr wire:key="tarifa-{{ $rate->id }}" class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 font-medium">{{ $rate->name ?: '—' }}</td>
                            <td class="py-3 text-sm font-mono">{{ $rate->rutaLabel() }}</td>
                            <td class="py-3 text-sm">{{ $rate->shipmentTypeLabel() }}</td>
                            <td class="py-3 text-sm">{{ $rate->pesoLabel() }}</td>
                            <td class="py-3 text-right">
                                ₡{{ number_format($rate->price, 2) }}
                                @if ($rate->price_per_extra_kg > 0)
                                    <div class="text-xs text-gray-500">+₡{{ number_format($rate->price_per_extra_kg, 2) }}/kg</div>
                                @endif
                            </td>
                            <td class="py-3">
                                <x-action-button action="toggleActive({{ $rate->id }})" variant="link" loadingText="..."
                                    class="badge {{ $rate->is_active
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                        : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                    {{ $rate->is_active ? 'Activa' : 'Inactiva' }}
                                </x-action-button>
                            </td>
                            <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                <x-action-button action="edit({{ $rate->id }})" variant="link">Editar</x-action-button>
                                <x-action-button action="delete({{ $rate->id }})" variant="link-danger" confirm="¿Eliminar esta tarifa?">Eliminar</x-action-button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No hay tarifas configuradas: el precio se digita a mano en cada guía.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @forelse ($rates as $rate)
                <div wire:key="tarifa-movil-{{ $rate->id }}" class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold">{{ $rate->name ?: $rate->rutaLabel() }}</div>
                            <div class="text-sm font-mono text-gray-500">{{ $rate->rutaLabel() }}</div>
                        </div>
                        <strong>₡{{ number_format($rate->price, 2) }}</strong>
                    </div>
                    <div class="mt-2 text-sm text-gray-500">{{ $rate->pesoLabel() }} · {{ $rate->shipmentTypeLabel() }}</div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex gap-4">
                        <x-action-button action="edit({{ $rate->id }})" variant="link">Editar</x-action-button>
                        <x-action-button action="delete({{ $rate->id }})" variant="link-danger" confirm="¿Eliminar esta tarifa?">Eliminar</x-action-button>
                    </div>
                </div>
            @empty
                <div class="text-center text-gray-500 py-6">No hay tarifas configuradas.</div>
            @endforelse
        </div>
    </div>
</div>
