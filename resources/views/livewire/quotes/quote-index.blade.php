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
            Precios por escrito para pasarle a un cliente. <strong>No se facturan</strong>: no consumen
            consecutivo de guía, no entran en las ventas y no llegan a Hacienda.
        </p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">
            <x-icon name="plus" class="w-4 h-4" /> Nueva cotización
        </x-action-button>
    </div>

    @if ($showForm)
        <div class="card space-y-5">
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Editar cotización' : 'Nueva cotización' }}</h2>

            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="label">Sede origen</label>
                        <select wire:model.live="origin_branch_id" class="input @error('origin_branch_id') input-error @enderror">
                            <option value="">Elegir...</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->prefixLabel() }} — {{ $b->name }}</option>
                            @endforeach
                        </select>
                        @error('origin_branch_id') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Sede destino</label>
                        <select wire:model.live="destination_branch_id" class="input @error('destination_branch_id') input-error @enderror">
                            <option value="">Elegir...</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->prefixLabel() }} — {{ $b->name }}</option>
                            @endforeach
                        </select>
                        @error('destination_branch_id') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Cliente registrado</label>
                        <select wire:model.live="customer_id" class="input">
                            <option value="">Ninguno</option>
                            @foreach ($clientes as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Opcional: rellena los datos</p>
                    </div>
                    <div>
                        <label class="label">Válida hasta</label>
                        <input type="date" wire:model="valid_until" class="input @error('valid_until') input-error @enderror">
                        @error('valid_until') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="label">A nombre de</label>
                        <input type="text" wire:model="customer_name" class="input @error('customer_name') input-error @enderror">
                        @error('customer_name') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Correo</label>
                        <input type="email" wire:model="customer_email" class="input @error('customer_email') input-error @enderror"
                               placeholder="Para enviarle la cotización">
                        @error('customer_email') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Teléfono</label>
                        <input type="text" wire:model="customer_phone" class="input">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                        <h3 class="font-semibold">Bultos a cotizar</h3>
                        <x-action-button action="addItem" variant="secondary" loadingText="...">
                            <x-icon name="plus" class="w-4 h-4" /> Agregar bulto
                        </x-action-button>
                    </div>

                    <div class="space-y-3">
                        @foreach ($items as $index => $item)
                            <div wire:key="cot-item-{{ $index }}"
                                 class="grid grid-cols-2 lg:grid-cols-[repeat(18,minmax(0,1fr))] gap-3 items-end border-b border-gray-100 dark:border-gray-700 pb-3">
                                <div class="col-span-2 lg:col-span-3">
                                    <label class="label">Tipo de bulto</label>
                                    <select wire:model="items.{{ $index }}.package_type_id" class="input">
                                        @foreach ($tiposDeBulto as $tipo)
                                            <option value="{{ $tipo->id }}">{{ $tipo->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="label">Peso (kg)</label>
                                    <input type="number" step="0.01" wire:model.blur="items.{{ $index }}.weight" class="input">
                                </div>
                                <div class="col-span-2 lg:col-span-4">
                                    <label class="label">L × A × H (cm)</label>
                                    <div class="grid grid-cols-3 gap-1">
                                        <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.length_cm" placeholder="Largo" class="input input-compacto">
                                        <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.width_cm" placeholder="Ancho" class="input input-compacto">
                                        <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.height_cm" placeholder="Alto" class="input input-compacto">
                                    </div>
                                </div>
                                <div class="col-span-2 lg:col-span-5">
                                    <label class="label">Descripción</label>
                                    <input type="text" wire:model="items.{{ $index }}.description" class="input">
                                </div>
                                <div class="lg:col-span-3">
                                    <label class="label">Precio (₡)</label>
                                    <input type="number" step="0.01" wire:model.live="items.{{ $index }}.price"
                                           class="input @error('items.'.$index.'.price') input-error @enderror">
                                    @error('items.'.$index.'.price') <p class="error-text">{{ $message }}</p> @enderror
                                </div>
                                <div class="lg:col-span-1">
                                    @if (count($items) > 1)
                                        <button type="button" wire:click="removeItem({{ $index }})" class="btn-danger !py-2 !px-3 text-sm w-full">Quitar</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($quote && ($quote['sin_tarifa'] ?? false))
                        <p class="mt-2 text-sm rounded-lg border border-amber-200 dark:border-amber-800
                                  bg-amber-50 dark:bg-amber-900/30 text-amber-900 dark:text-amber-100 p-3">
                            Hay bultos sin tarifa configurada para esta ruta y peso: digitá el precio a mano.
                        </p>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Notas</label>
                        <textarea wire:model="notes" rows="2" class="input"
                                  placeholder="Condiciones, plazos de entrega, aclaraciones..."></textarea>
                    </div>
                    <div class="space-y-3">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model.live="aplicarImpuesto" class="checkbox">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Incluir impuestos</span>
                        </label>
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3 space-y-1 text-base">
                            <div class="flex justify-between"><span>Subtotal</span><span>₡{{ number_format($this->subtotal, 2) }}</span></div>
                            <div class="flex justify-between"><span>Impuestos</span><span>₡{{ number_format($this->taxTotal, 2) }}</span></div>
                            <div class="flex justify-between font-bold text-lg border-t border-gray-200 dark:border-gray-700 pt-1">
                                <span>Total</span><span>₡{{ number_format($this->total, 2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando...">Guardar cotización</x-action-button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    @if ($enviandoId)
        <div class="card border-brand-300 dark:border-brand-700">
            <h2 class="text-lg font-semibold mb-1">Enviar por correo</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                Le llega el detalle y la cotización en PDF adjunta.
            </p>
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[260px]">
                    <label class="label">Correo del cliente</label>
                    <input type="email" wire:model="enviarA" class="input @error('enviarA') input-error @enderror"
                           placeholder="cliente@ejemplo.com">
                    @error('enviarA') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <x-action-button action="enviar" variant="primary" loadingText="Enviando...">
                    <x-icon name="send" class="w-4 h-4" /> Enviar
                </x-action-button>
                <button type="button" wire:click="cancelarEnvio" class="btn-secondary">Cancelar</button>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Cotización</th>
                        <th class="py-2">Cliente</th>
                        <th class="py-2">Ruta</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2">Vence</th>
                        <th class="py-2 text-right">Total</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cotizaciones as $cot)
                        <tr wire:key="cot-{{ $cot->id }}" class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3">
                                <span class="font-mono font-semibold">{{ $cot->code }}</span>
                                <div class="text-xs text-gray-500">{{ $cot->created_at->format('d/m/Y') }}</div>
                            </td>
                            <td class="py-3 text-sm">
                                {{ $cot->customer_name }}
                                @if ($cot->fueEnviada())
                                    <div class="text-xs text-gray-500">Enviada a {{ $cot->sent_to }}</div>
                                @endif
                            </td>
                            <td class="py-3 text-sm font-mono whitespace-nowrap">{{ $cot->rutaLabel() }}</td>
                            <td class="py-3">
                                <span class="badge {{ $cot->estadoBadgeClasses() }}">{{ $cot->estadoLabel() }}</span>
                            </td>
                            <td class="py-3 text-sm">{{ $cot->valid_until?->format('d/m/Y') ?? '—' }}</td>
                            <td class="py-3 text-right font-semibold tabular-nums whitespace-nowrap">
                                &#8353;{{ number_format((float) $cot->total, 2) }}
                            </td>
                            <td class="py-3">
                                <div class="flex items-center justify-end gap-1 whitespace-nowrap">
                                    <a href="{{ route('quotes.pdf', $cot) }}" target="_blank"
                                       title="Descargar en PDF" class="btn-primary !py-1.5 !px-2.5 text-sm">
                                        <x-icon name="download" class="w-4 h-4" />
                                    </a>
                                    <x-action-button action="abrirEnvio({{ $cot->id }})" variant="secondary"
                                        loadingText="..." class="!py-1.5 !px-2.5 text-sm" title="Enviar por correo">
                                        <x-icon name="send" class="w-4 h-4" />
                                    </x-action-button>
                                    @unless ($cot->fueAceptada())
                                        <x-action-button action="edit({{ $cot->id }})" variant="link" class="ml-2">Editar</x-action-button>
                                        <x-action-button action="delete({{ $cot->id }})" variant="link-danger"
                                            confirm="¿Eliminar la cotización {{ $cot->code }}?">Eliminar</x-action-button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-gray-500">
                            Todavía no hay cotizaciones. Creá una para pasarle un precio a un cliente sin facturarlo.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @foreach ($cotizaciones as $cot)
                <div wire:key="cot-movil-{{ $cot->id }}" class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-mono font-semibold">{{ $cot->code }}</div>
                            <div class="text-sm text-gray-500">{{ $cot->customer_name }}</div>
                        </div>
                        <span class="badge {{ $cot->estadoBadgeClasses() }}">{{ $cot->estadoLabel() }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between">
                        <span class="text-sm font-mono">{{ $cot->rutaLabel() }}</span>
                        <span class="font-semibold">&#8353;{{ number_format((float) $cot->total, 2) }}</span>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex flex-wrap gap-2">
                        <a href="{{ route('quotes.pdf', $cot) }}" target="_blank" class="btn-primary !py-2 !px-3 text-sm">
                            <x-icon name="download" class="w-4 h-4" /> PDF
                        </a>
                        <x-action-button action="abrirEnvio({{ $cot->id }})" variant="secondary" loadingText="..."
                            class="!py-2 !px-3 text-sm">
                            <x-icon name="send" class="w-4 h-4" /> Enviar
                        </x-action-button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $cotizaciones->links() }}</div>
    </div>
</div>
