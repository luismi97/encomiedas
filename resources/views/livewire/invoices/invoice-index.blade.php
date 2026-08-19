<div class="space-y-6">
    <x-flash />

    <div class="card space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            @foreach (['today' => 'Hoy', 'week' => 'Esta semana', 'month' => 'Este mes', 'range' => 'Rango', 'all' => 'Todas'] as $value => $label)
                <button wire:click="$set('period', '{{ $value }}')"
                    class="px-4 py-2 rounded-lg text-sm font-semibold {{ $period === $value ? 'bg-brand-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            @if ($period === 'range')
                <div><label class="label">Desde</label><input type="date" wire:model.live="from" class="input"></div>
                <div><label class="label">Hasta</label><input type="date" wire:model.live="to" class="input"></div>
            @endif
            <div>
                <label class="label">Estado</label>
                <select wire:model.live="status" class="input">
                    <option value="">Todos</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Sucursal</label>
                <select wire:model.live="branchId" class="input">
                    <option value="">Todas</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Buscar</label>
                <input type="text" wire:model.live.debounce.400ms="search" class="input" placeholder="Código, remitente, receptor...">
            </div>
        </div>

        <div class="flex flex-wrap gap-3 pt-1">
            {{-- El cajero es quien recibe la paquetería en el mostrador: la
                 ruta ya lo permitía, pero el botón estaba detrás de isAdmin()
                 y no tenía por dónde llegar. --}}
            @if (auth()->user()->puedeOperarCaja())
                <a href="{{ route('invoices.create') }}" class="btn-primary"><x-icon name="plus" class="w-4 h-4" /> Nueva guía</a>
            @endif
            <a href="{{ route('invoices.export', ['from' => $from, 'to' => $to, 'status' => $status, 'branch_id' => $branchId, 'search' => $search]) }}"
               class="btn-secondary" target="_blank">
                <x-icon name="document" class="w-4 h-4" /> Exportar PDF
            </a>
        </div>
    </div>

    <div class="card">
        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Guía</th>
                        <th class="py-2">Ruta</th>
                        <th class="py-2">Remitente / Destinatario</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2">Cobro</th>
                        <th class="py-2 text-right">Total</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- wire:key en cada fila: sin él Livewire reutiliza nodos
                         de una fila en otra al re-renderizar y los botones
                         quedan pegados a la guía equivocada. --}}
                    @forelse ($invoices as $invoice)
                        <tr wire:key="guia-{{ $invoice->id }}"
                            class="border-b border-gray-100 dark:border-gray-700/50 align-top">
                            <td class="py-3">
                                <a href="{{ route('invoices.show', $invoice) }}"
                                   class="font-mono font-semibold text-brand-600 dark:text-brand-300 hover:underline">
                                    {{ $invoice->code }}
                                </a>
                                <div class="text-xs text-gray-500">{{ $invoice->created_at->format('d/m/Y H:i') }}</div>
                            </td>
                            <td class="py-3 text-sm whitespace-nowrap">
                                <span class="font-mono">{{ $invoice->pickupBranch?->prefix }}</span>
                                <span class="text-gray-400">&rarr;</span>
                                <span class="font-mono">{{ $invoice->deliveryBranch?->prefix }}</span>
                                @if ($invoice->assignedTo)
                                    <div class="text-xs text-gray-500">{{ $invoice->assignedTo->name }}</div>
                                @endif
                            </td>
                            <td class="py-3 text-sm">
                                <div>{{ $invoice->sender_name }}</div>
                                <div class="text-gray-500">&rarr; {{ $invoice->recipient_name }}</div>
                            </td>
                            <td class="py-3">
                                <span class="badge {{ $invoice->statusBadgeClasses() }}">{{ $invoice->statusLabel() }}</span>
                            </td>
                            <td class="py-3">
                                @if ($invoice->tieneCobroPendiente())
                                    <span class="badge bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Por cobrar</span>
                                @elseif ($invoice->esCredito())
                                    <span class="badge bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200">Crédito</span>
                                @else
                                    <span class="text-sm text-gray-500">Pagado</span>
                                @endif
                            </td>
                            <td class="py-3 text-right font-semibold tabular-nums whitespace-nowrap">
                                &#8353;{{ number_format($invoice->total, 2) }}
                            </td>
                            <td class="py-3">
                                {{-- Las acciones agrupadas y alineadas a la derecha: imprimir
                                     primero, que es lo que más se repite en mostrador; el
                                     cambio de estado al final, separado por una regla. --}}
                                <div class="flex items-center justify-end gap-1 whitespace-nowrap">
                                    <a href="{{ route('invoices.etiqueta', $invoice) }}" target="_blank"
                                       title="Etiqueta del paquete, con código de barras"
                                       class="btn-primary !py-1.5 !px-2.5 text-sm">
                                        <x-icon name="box" class="w-4 h-4" />
                                    </a>
                                    <a href="{{ route('invoices.recibo', $invoice) }}" target="_blank"
                                       title="Recibo del cliente"
                                       class="btn-secondary !py-1.5 !px-2.5 text-sm">
                                        <x-icon name="receipt" class="w-4 h-4" />
                                    </a>
                                    <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank"
                                       title="Factura en PDF"
                                       class="btn-secondary !py-1.5 !px-2.5 text-sm">
                                        <x-icon name="document" class="w-4 h-4" />
                                    </a>

                                    @php
                                        $siguientes = collect($invoice->siguientesEstados())
                                            ->except([\App\Models\Invoice::STATUS_DELIVERED, \App\Models\Invoice::STATUS_CANCELLED]);
                                    @endphp

                                    @if ($siguientes->isNotEmpty())
                                        <span class="w-px h-6 bg-gray-200 dark:bg-gray-600 mx-1"></span>
                                    @endif

                                    {{-- Los estados salen del ciclo de la guía, no de una
                                         cadena de @if que se desactualiza. Entregar y anular
                                         piden datos extra y se hacen en el detalle. --}}
                                    @foreach ($siguientes as $estado => $etiqueta)
                                        <x-action-button
                                            action="updateStatus({{ $invoice->id }}, '{{ $estado }}')"
                                            :variant="$estado === \App\Models\Invoice::STATUS_RETURNED ? 'danger' : 'primary'"
                                            confirm="Pasar la guía {{ $invoice->code }} a «{{ $etiqueta }}». El cambio queda en la bitácora y no se deshace. ¿Continuar?"
                                            loadingText="..." class="!py-1.5 !px-2.5 text-sm">
                                            {{ $etiqueta }}
                                        </x-action-button>
                                    @endforeach

                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-gray-500">No hay guías para el filtro seleccionado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- En el celular una tabla de siete columnas no se lee: las mismas
             guías van como tarjetas. --}}
        <div class="md:hidden space-y-3">
            @forelse ($invoices as $invoice)
                <div wire:key="guia-movil-{{ $invoice->id }}"
                     class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <a href="{{ route('invoices.show', $invoice) }}" class="font-mono font-semibold">{{ $invoice->code }}</a>
                            <div class="text-xs text-gray-500">{{ $invoice->created_at->format('d/m/Y H:i') }}</div>
                        </div>
                        <span class="badge {{ $invoice->statusBadgeClasses() }}">{{ $invoice->statusLabel() }}</span>
                    </div>
                    <div class="mt-2 text-sm">
                        <span class="font-mono">{{ $invoice->pickupBranch?->prefix }} &rarr; {{ $invoice->deliveryBranch?->prefix }}</span>
                        · {{ $invoice->recipient_name }}
                    </div>
                    <div class="mt-1 flex items-center justify-between">
                        <span class="font-semibold">&#8353;{{ number_format($invoice->total, 2) }}</span>
                        @if ($invoice->tieneCobroPendiente())
                            <span class="badge bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Por cobrar</span>
                        @elseif ($invoice->esCredito())
                            <span class="badge bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200">Crédito</span>
                        @endif
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex flex-wrap gap-2">
                        <a href="{{ route('invoices.etiqueta', $invoice) }}" target="_blank" class="btn-primary !py-2 !px-3 text-sm">
                            <x-icon name="box" class="w-4 h-4" /> Etiqueta
                        </a>
                        <a href="{{ route('invoices.recibo', $invoice) }}" target="_blank" class="btn-secondary !py-2 !px-3 text-sm">
                            <x-icon name="receipt" class="w-4 h-4" /> Recibo
                        </a>
                        <a href="{{ route('invoices.show', $invoice) }}" class="btn-secondary !py-2 !px-3 text-sm">Detalle</a>
                    </div>
                </div>
            @empty
                <div class="text-center text-gray-500 py-6">No hay guías para el filtro seleccionado.</div>
            @endforelse
        </div>
    </div>

    <div>{{ $invoices->links() }}</div>
</div>
