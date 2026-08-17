<div class="max-w-3xl space-y-6">
    <x-flash />

    <div class="card">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-2xl font-bold">{{ $invoice->code }}</div>
                <div class="text-gray-500 dark:text-gray-400">Creada: {{ $invoice->created_at->format('d/m/Y H:i') }}</div>
                @if ($invoice->delivered_at)
                    <div class="text-gray-500 dark:text-gray-400">Entregada: {{ $invoice->delivered_at->format('d/m/Y H:i') }}</div>
                @endif
                @if ($invoice->returned_at)
                    <div class="text-gray-500 dark:text-gray-400">Devuelta: {{ $invoice->returned_at->format('d/m/Y H:i') }}</div>
                @endif
            </div>
            <span class="badge {{ $invoice->statusBadgeClasses() }} text-base px-4 py-2">{{ $invoice->statusLabel() }}</span>
        </div>

        <div class="mt-4 flex flex-wrap gap-3">
            @if (!in_array($invoice->status, [\App\Models\Invoice::STATUS_DELIVERED, \App\Models\Invoice::STATUS_CANCELLED]))
                @if ($invoice->status === \App\Models\Invoice::STATUS_PENDING)
                    <x-action-button action="updateStatus('in_transit')" variant="primary" loadingText="Actualizando..."><x-icon name="truck" class="w-4 h-4" /> Marcar en camino</x-action-button>
                @endif
                @if ($invoice->status === \App\Models\Invoice::STATUS_IN_TRANSIT)
                    <x-action-button action="updateStatus('delivered')" variant="success" loadingText="Actualizando..."><x-icon name="check" class="w-4 h-4" /> Marcar entregada</x-action-button>
                    <x-action-button action="updateStatus('returned')" variant="danger" confirm="¿Confirmar devolución de esta encomienda?" loadingText="Actualizando..."><x-icon name="undo" class="w-4 h-4" /> Marcar devuelta</x-action-button>
                @endif
            @endif
            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn-secondary"><x-icon name="download" class="w-4 h-4" /> Descargar factura</a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="card">
            <h3 class="font-semibold mb-2 flex items-center gap-2"><x-icon name="upload" class="w-5 h-5 text-gray-400" /> Remitente</h3>
            <p>{{ $invoice->sender_name }}</p>
            @if ($invoice->sender_phone)<p class="text-sm text-gray-500">Tel: {{ $invoice->sender_phone }}</p>@endif
            @if ($invoice->sender_identification)<p class="text-sm text-gray-500">Identificación: {{ $invoice->sender_identification }}</p>@endif
            <p class="text-sm text-gray-500 mt-2">Sucursal de recogida: <strong>{{ $invoice->pickupBranch->name }}</strong></p>
            @if ($invoice->pickupBranch->address)<p class="text-sm text-gray-500">{{ $invoice->pickupBranch->address }}</p>@endif
        </div>
        <div class="card">
            <h3 class="font-semibold mb-2 flex items-center gap-2"><x-icon name="inbox" class="w-5 h-5 text-gray-400" /> Receptor</h3>
            <p>{{ $invoice->recipient_name }}</p>
            @if ($invoice->recipient_phone)<p class="text-sm text-gray-500">Tel: {{ $invoice->recipient_phone }}</p>@endif
            @if ($invoice->recipient_identification)<p class="text-sm text-gray-500">Identificación ({{ $invoice->recipient_identification_type }}): {{ $invoice->recipient_identification }}</p>@endif
            @if ($invoice->recipient_email)<p class="text-sm text-gray-500">{{ $invoice->recipient_email }}</p>@endif
            <p class="text-sm text-gray-500 mt-2">Sucursal de entrega: <strong>{{ $invoice->deliveryBranch->name }}</strong></p>
            @if ($invoice->deliveryBranch->address)<p class="text-sm text-gray-500">{{ $invoice->deliveryBranch->address }}</p>@endif
        </div>
    </div>

    <div class="card">
        <h3 class="font-semibold mb-2 flex items-center gap-2"><x-icon name="truck" class="w-5 h-5 text-gray-400" /> Encomienda</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <div><span class="text-gray-500 block">Creada por</span>{{ $invoice->creator?->name ?? '—' }}</div>
            <div><span class="text-gray-500 block">Repartidor asignado</span>{{ $invoice->assignedTo?->name ?? '— Sin asignar —' }}</div>
            <div><span class="text-gray-500 block">Condición</span>Contado</div>
        </div>
        @if ($invoice->notes)
            <p class="text-sm mt-3"><span class="text-gray-500">Notas:</span> {{ $invoice->notes }}</p>
        @endif
    </div>

    <div class="card">
        <h3 class="font-semibold mb-3">Paquetes</h3>
        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Código</th><th class="py-2">Tamaño</th><th class="py-2">Peso</th><th class="py-2">Descripción</th><th class="py-2 text-right">Precio</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-2">{{ $item->package_code }}</td>
                            <td class="py-2">{{ $item->size }}</td>
                            <td class="py-2">{{ $item->weight }} kg</td>
                            <td class="py-2">{{ $item->description }}</td>
                            <td class="py-2 text-right">₡{{ number_format($item->price, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @foreach ($invoice->items as $item)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                    <div class="flex justify-between font-medium"><span>{{ $item->package_code }}</span><span>₡{{ number_format($item->price, 2) }}</span></div>
                    <div class="mt-1 text-sm text-gray-500 space-y-0.5">
                        <div class="flex justify-between"><span>Tamaño</span><span>{{ $item->size }}</span></div>
                        <div class="flex justify-between"><span>Peso</span><span>{{ $item->weight }} kg</span></div>
                        @if ($item->description)<div class="flex justify-between gap-2"><span>Descripción</span><span class="text-right">{{ $item->description }}</span></div>@endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex justify-end mt-4">
            <div class="w-full sm:w-72 space-y-1">
                <div class="flex justify-between"><span>Subtotal</span><span>₡{{ number_format($invoice->subtotal, 2) }}</span></div>
                <div class="flex justify-between"><span>Descuento</span><span>-₡{{ number_format($invoice->discount_amount, 2) }}</span></div>
                @foreach ($invoice->taxes as $tax)
                    <div class="flex justify-between text-sm"><span>{{ $tax->name }} ({{ number_format($tax->percent, 2) }}%)</span><span>₡{{ number_format($tax->amount, 2) }}</span></div>
                @endforeach
                <div class="flex justify-between text-lg font-bold border-t border-gray-200 dark:border-gray-700 pt-2">
                    <span>Total</span><span>₡{{ number_format($invoice->total, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    @if (auth()->user()->isAdmin())
        <div class="card">
            <h3 class="font-semibold mb-3 flex items-center gap-2"><x-icon name="receipt" class="w-5 h-5 text-gray-400" /> Facturación electrónica (Hacienda)</h3>
            @if (!$invoice->electronicInvoice)
                <p class="text-gray-500">La encomienda debe estar <strong>entregada</strong> para reservar el comprobante.</p>
            @else
                @php $ei = $invoice->electronicInvoice; @endphp
                <div class="space-y-2 text-sm">
                    <div><strong>Tipo:</strong> {{ $ei->typeLabel() }}</div>
                    <div><strong>Consecutivo:</strong> {{ $ei->consecutivo }}</div>
                    <div><strong>Clave:</strong> <span class="break-all">{{ $ei->clave }}</span></div>
                    <div><strong>Estado:</strong> {{ $ei->statusLabel() }}</div>
                    @if ($ei->error_message)
                        <div class="text-red-600">{{ $ei->error_message }}</div>
                    @endif
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    @if (in_array($ei->status, ['pending', 'error']))
                        <x-action-button action="sendToHacienda" variant="primary" loadingText="Enviando..."><x-icon name="send" class="w-4 h-4" /> Enviar a Hacienda</x-action-button>
                    @endif
                    @if ($ei->pdf_path)
                        <a href="{{ route('electronic-invoices.pdf', $ei) }}" target="_blank" class="btn-secondary"><x-icon name="document" class="w-4 h-4" /> Ver comprobante PDF</a>
                    @endif
                    @if ($ei->status === 'accepted')
                        <x-action-button action="openNoteForm('NC')" variant="secondary"><x-icon name="undo" class="w-4 h-4" /> Nota de crédito</x-action-button>
                        <x-action-button action="openNoteForm('ND')" variant="secondary"><x-icon name="plus" class="w-4 h-4" /> Nota de débito</x-action-button>
                    @endif
                </div>

                @if ($ei->status === 'accepted' && !$showNoteForm)
                    <p class="mt-3 text-xs text-gray-500">
                        Un comprobante aceptado por Hacienda no se puede anular ni editar: se corrige emitiendo una nota.
                    </p>
                @endif

                @if ($showNoteForm)
                    <div class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                        <h4 class="font-semibold">
                            {{ $noteType === 'NC' ? 'Nota de crédito (anula o rebaja)' : 'Nota de débito (cobro adicional)' }}
                        </h4>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label">Monto (₡, IVA incluido)</label>
                                <input type="number" step="0.01" min="0.01" wire:model="noteAmount" class="input">
                                @error('noteAmount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="label">Razón</label>
                                <input type="text" maxlength="180" wire:model="noteReason" class="input"
                                       placeholder="{{ $noteType === 'NC' ? 'Anulación por encomienda devuelta' : 'Cobro adicional por sobrepeso' }}">
                                @error('noteReason') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <x-action-button action="issueNote" variant="primary" loadingText="Emitiendo...">Emitir y enviar</x-action-button>
                            <x-action-button action="closeNoteForm" variant="link-muted">Cancelar</x-action-button>
                        </div>
                    </div>
                @endif

                @if ($invoice->electronicNotes->isNotEmpty())
                    <div class="mt-4 border-t border-gray-100 dark:border-gray-700 pt-3">
                        <h4 class="font-semibold text-sm mb-2">Notas emitidas</h4>
                        <ul class="space-y-1 text-sm">
                            @foreach ($invoice->electronicNotes as $note)
                                <li class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span>
                                        {{ $note->typeLabel() }} · {{ $note->consecutivo }}
                                        <span class="text-gray-500">— {{ $note->reference_reason }}</span>
                                    </span>
                                    <span class="text-gray-500">
                                        ₡{{ number_format((float) $note->total, 2) }} · {{ $note->statusLabel() }}
                                        @if ($note->pdf_path)
                                            <a href="{{ route('electronic-invoices.pdf', $note) }}" target="_blank" class="text-brand-600 ml-2">PDF</a>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>
    @endif

    @if ($invoice->activityLogs->isNotEmpty())
        <div class="card">
            <h3 class="font-semibold mb-3 flex items-center gap-2"><x-icon name="clock" class="w-5 h-5 text-gray-400" /> Historial de actividad</h3>
            <ul class="space-y-2 text-sm">
                @foreach ($invoice->activityLogs as $log)
                    <li class="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700/50 pb-2">
                        <span>{{ $log->description }}</span>
                        <span class="text-gray-400 whitespace-nowrap">{{ $log->created_at->format('d/m/Y H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <a href="{{ route('invoices.index') }}" class="btn-secondary inline-flex"><x-icon name="arrow-left" class="w-4 h-4" /> Volver</a>
</div>
