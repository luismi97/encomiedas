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
            El manifiesto que viaja con el camión. Al recibirlo en destino, lo que no se marque queda como faltante.
        </p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">
            <x-icon name="plus" class="w-4 h-4" /> Nuevo cierre
        </x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">Nuevo cierre de envío</h2>
            <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Sede origen</label>
                    <select wire:model="origin_branch_id" class="input @error('origin_branch_id') input-error @enderror">
                        <option value="">— Elegir —</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->prefixLabel() }} — {{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('origin_branch_id') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Sede destino</label>
                    <select wire:model="destination_branch_id" class="input @error('destination_branch_id') input-error @enderror">
                        <option value="">— Elegir —</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->prefixLabel() }} — {{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('destination_branch_id') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Chofer</label>
                    <select wire:model="driver_user_id" class="input">
                        <option value="">— Sin asignar —</option>
                        @foreach ($choferes as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Verá este cierre en «Mi ruta» desde su celular.</p>
                </div>
                <div><label class="label">Nombre en el manifiesto</label><input type="text" wire:model="driver_name" class="input"></div>
                <div><label class="label">Placa del vehículo</label><input type="text" wire:model="vehicle_plate" maxlength="20" class="input"></div>
                <div class="sm:col-span-2"><label class="label">Notas</label><textarea wire:model="notes" rows="2" class="input"></textarea></div>
                <div class="sm:col-span-2 flex gap-3">
                    <x-action-button type="submit" target="save" variant="primary" loadingText="Creando...">Crear cierre</x-action-button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    @if ($abierto)
        <div class="card space-y-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h2 class="text-lg font-semibold flex items-center gap-2">
                        {{ $abierto->code }}
                        <span class="badge {{ $abierto->badgeClasses() }}">{{ $abierto->statusLabel() }}</span>
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $abierto->rutaLabel() }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $abierto->driver_name ?: 'Sin chofer' }}
                        @if ($abierto->vehicle_plate) · {{ $abierto->vehicle_plate }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('dispatches.pdf', $abierto) }}" target="_blank" class="btn-secondary !py-2 !px-3 text-sm">
                        <x-icon name="document" class="w-4 h-4" /> Manifiesto PDF
                    </a>
                    <button type="button" wire:click="close" class="btn-secondary !py-2 !px-3 text-sm">Cerrar panel</button>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                <div><span class="text-gray-500 block">Guías</span><strong>{{ $abierto->lines->count() }}</strong></div>
                <div><span class="text-gray-500 block">Paquetes</span><strong>{{ $abierto->totalPaquetes() }}</strong></div>
                <div><span class="text-gray-500 block">Peso total</span><strong>{{ $abierto->pesoTotal() }} kg</strong></div>
                <div><span class="text-gray-500 block">Valor declarado</span><strong>₡{{ number_format($abierto->valorDeclaradoTotal(), 2) }}</strong></div>
            </div>

            {{-- En ruta: se reciben las guías, por escaneo o a mano --}}
            @if ($abierto->enRuta())
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <label class="label">Recibir por código de guía</label>
                    <div class="flex flex-wrap gap-2">
                        <input type="text" wire:model="scanCode" wire:keydown.enter.prevent="recibirPorCodigo"
                               placeholder="Escaneá el QR o escribí el código" class="input flex-1 min-w-[220px] font-mono">
                        <x-action-button action="recibirPorCodigo" variant="primary" loadingText="Recibiendo...">Recibir</x-action-button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">El lector de QR escribe el código y da Enter: no hace falta tocar el mouse.</p>
                </div>
            @endif

            <div class="data-table-wrap">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2">Guía</th>
                            <th class="py-2">Destinatario</th>
                            <th class="py-2">Paquetes</th>
                            <th class="py-2">Estado</th>
                            <th class="py-2 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($abierto->lines as $linea)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50 {{ $linea->incident === 'faltante' ? 'bg-red-50 dark:bg-red-900/20' : '' }}">
                                <td class="py-3 font-mono">{{ $linea->invoice?->code }}</td>
                                <td class="py-3 text-sm">{{ $linea->invoice?->recipient_name }}</td>
                                <td class="py-3 text-sm">{{ $linea->invoice?->items->count() }}</td>
                                <td class="py-3">
                                    @if ($linea->incident === 'faltante')
                                        <span class="badge bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200">Faltante</span>
                                    @elseif ($linea->received_at)
                                        <span class="badge bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200">Recibida</span>
                                    @else
                                        <span class="badge {{ \App\Models\Invoice::STATUS_BADGE_CLASSES[$linea->invoice?->status] ?? '' }}">
                                            {{ $linea->invoice?->statusLabel() }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if ($abierto->estaAbierto())
                                        <x-action-button action="quitar({{ $linea->invoice_id }})" variant="link-danger">Quitar</x-action-button>
                                    @elseif ($abierto->enRuta() && ! $linea->received_at)
                                        <x-action-button action="recibir({{ $linea->invoice_id }})" variant="link">Marcar recibida</x-action-button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-gray-500">Este cierre todavía no tiene guías.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($abierto->estaAbierto())
                <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                    <h3 class="font-semibold mb-2">Guías disponibles de esta ruta</h3>
                    @forelse ($disponibles as $guia)
                        <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <div>
                                <span class="font-mono">{{ $guia->code }}</span>
                                <span class="text-sm text-gray-500"> · {{ $guia->recipient_name }} · {{ $guia->items->count() }} paquete(s)</span>
                            </div>
                            <x-action-button action="agregar({{ $guia->id }})" variant="link">Agregar</x-action-button>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No hay guías pendientes para esta ruta.</p>
                    @endforelse

                    <div class="mt-4">
                        <x-action-button action="despachar" variant="primary" loadingText="Despachando..."
                            confirm="¿Despachar el cierre? Todas sus guías pasan a «Enviado» y ya no se pueden quitar.">
                            <x-icon name="truck" class="w-4 h-4" /> Despachar cierre
                        </x-action-button>
                    </div>
                </div>
            @elseif ($abierto->enRuta())
                <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                    <x-action-button action="cerrarRecepcion" variant="success" loadingText="Cerrando..."
                        confirm="¿Cerrar la recepción? Lo que no esté marcado queda registrado como faltante.">
                        <x-icon name="check" class="w-4 h-4" /> Cerrar recepción
                    </x-action-button>
                </div>
            @endif
        </div>
    @endif

    <div class="card space-y-4">
        <select wire:model.live="filterStatus" class="input sm:max-w-[220px]">
            <option value="">Todos los estados</option>
            @foreach (\App\Models\Dispatch::STATUSES as $valor => $etiqueta)
                <option value="{{ $valor }}">{{ $etiqueta }}</option>
            @endforeach
        </select>

        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Cierre</th>
                        <th class="py-2">Ruta</th>
                        <th class="py-2">Chofer</th>
                        <th class="py-2">Guías</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($dispatches as $cierre)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 font-mono">{{ $cierre->code }}</td>
                            <td class="py-3 font-mono text-sm">{{ $cierre->rutaLabel() }}</td>
                            <td class="py-3 text-sm">{{ $cierre->driver_name ?: '—' }}</td>
                            <td class="py-3 text-sm">{{ $cierre->lines_count }}</td>
                            <td class="py-3"><span class="badge {{ $cierre->badgeClasses() }}">{{ $cierre->statusLabel() }}</span></td>
                            <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                <x-action-button action="open({{ $cierre->id }})" variant="link">Abrir</x-action-button>
                                <a href="{{ route('dispatches.pdf', $cierre) }}" target="_blank" class="text-gray-500">PDF</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No hay cierres de envío.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $dispatches->links() }}</div>
    </div>
</div>
