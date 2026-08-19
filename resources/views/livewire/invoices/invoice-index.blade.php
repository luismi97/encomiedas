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
            @if (auth()->user()->isAdmin())
                <a href="{{ route('invoices.create') }}" class="btn-primary"><x-icon name="plus" class="w-4 h-4" /> Nueva factura</a>
            @endif
            <a href="{{ route('invoices.export', ['from' => $from, 'to' => $to, 'status' => $status, 'branch_id' => $branchId, 'search' => $search]) }}"
               class="btn-secondary" target="_blank">
                <x-icon name="document" class="w-4 h-4" /> Exportar PDF
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse ($invoices as $invoice)
            <div class="card">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-bold text-lg">{{ $invoice->code }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $invoice->created_at->format('d/m/Y H:i') }}</div>
                    </div>
                    <span class="badge {{ $invoice->statusBadgeClasses() }}">{{ $invoice->statusLabel() }}</span>
                </div>

                <div class="mt-3 text-sm space-y-1">
                    <div class="flex items-start gap-2"><x-icon name="upload" class="w-4 h-4 mt-0.5 text-gray-400" /><span><strong>{{ $invoice->sender_name }}</strong> — {{ $invoice->pickupBranch->name }}</span></div>
                    <div class="flex items-start gap-2"><x-icon name="inbox" class="w-4 h-4 mt-0.5 text-gray-400" /><span><strong>{{ $invoice->recipient_name }}</strong> — {{ $invoice->deliveryBranch->name }}</span></div>
                    @if ($invoice->assignedTo)
                        <div class="flex items-start gap-2"><x-icon name="truck" class="w-4 h-4 mt-0.5 text-gray-400" /><span>Repartidor: {{ $invoice->assignedTo->name }}</span></div>
                    @endif
                    <div class="font-semibold text-gray-700 dark:text-gray-200">Total: ₡{{ number_format($invoice->total, 2) }}</div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    {{-- Dos impresos distintos y por eso dos botones: el recibo
                         se lo lleva el cliente; la etiqueta se pega al bulto y
                         es la que se escanea. --}}
                    <a href="{{ route('invoices.recibo', $invoice) }}" target="_blank"
                       class="btn-secondary !py-2 !px-3 text-sm">
                        <x-icon name="receipt" class="w-4 h-4" /> Recibo
                    </a>
                    <a href="{{ route('invoices.etiqueta', $invoice) }}" target="_blank"
                       class="btn-primary !py-2 !px-3 text-sm">
                        <x-icon name="box" class="w-4 h-4" /> Etiqueta del paquete
                    </a>
                    <a href="{{ route('invoices.show', $invoice) }}" class="btn-secondary !py-2 !px-3 text-sm">Ver detalle</a>
                    <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn-secondary !py-2 !px-3 text-sm"><x-icon name="document" class="w-4 h-4" /> Factura</a>

                    {{-- Los estados salen del ciclo de la guía, no de una
                         cadena de @if que se desactualiza. Entregar y anular
                         piden datos extra y se hacen en la pantalla de detalle. --}}
                    @foreach ($invoice->siguientesEstados() as $estado => $etiqueta)
                        @continue (in_array($estado, [\App\Models\Invoice::STATUS_DELIVERED, \App\Models\Invoice::STATUS_CANCELLED], true))
                        @php
                            $variante = $estado === \App\Models\Invoice::STATUS_RETURNED ? 'danger' : 'primary';
                            $icono = match ($estado) {
                                \App\Models\Invoice::STATUS_RETURNED => 'undo',
                                \App\Models\Invoice::STATUS_IN_TRANSIT, \App\Models\Invoice::STATUS_DISPATCHED => 'truck',
                                \App\Models\Invoice::STATUS_AT_DESTINATION => 'inbox',
                                default => 'check-circle',
                            };
                        @endphp
                        <x-action-button action="updateStatus({{ $invoice->id }}, '{{ $estado }}')"
                            :variant="$variante" loadingText="..." class="!py-2 !px-3 text-sm">
                            <x-icon :name="$icono" class="w-4 h-4" /> {{ $etiqueta }}
                        </x-action-button>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="col-span-full card text-center text-gray-500 py-10">No hay facturas para el filtro seleccionado.</div>
        @endforelse
    </div>

    <div>{{ $invoices->links() }}</div>
</div>
