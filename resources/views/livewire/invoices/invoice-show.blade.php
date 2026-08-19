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
            {{-- Los botones salen del propio ciclo de estados: la pantalla ya no
                 decide qué se puede hacer, lo decide el modelo. --}}
            @foreach ($invoice->siguientesEstados() as $estado => $etiqueta)
                @php
                    $variante = match ($estado) {
                        \App\Models\Invoice::STATUS_DELIVERED => 'success',
                        \App\Models\Invoice::STATUS_RETURNED, \App\Models\Invoice::STATUS_CANCELLED, \App\Models\Invoice::STATUS_DISPOSED => 'danger',
                        default => 'primary',
                    };
                    $icono = match ($estado) {
                        \App\Models\Invoice::STATUS_DELIVERED => 'check',
                        \App\Models\Invoice::STATUS_RETURNED => 'undo',
                        \App\Models\Invoice::STATUS_IN_TRANSIT, \App\Models\Invoice::STATUS_DISPATCHED => 'truck',
                        \App\Models\Invoice::STATUS_AT_DESTINATION => 'inbox',
                        \App\Models\Invoice::STATUS_CANCELLED, \App\Models\Invoice::STATUS_DISPOSED => 'x',
                        default => 'check-circle',
                    };
                    $confirmar = in_array($estado, [
                        \App\Models\Invoice::STATUS_RETURNED,
                        \App\Models\Invoice::STATUS_CANCELLED,
                        \App\Models\Invoice::STATUS_DISPOSED,
                    ], true) ? '¿Confirmar el cambio a «' . $etiqueta . '»? Queda en la bitácora.' : null;
                @endphp
                <x-action-button action="updateStatus('{{ $estado }}')" :variant="$variante"
                    :confirm="$confirmar" loadingText="Actualizando...">
                    <x-icon :name="$icono" class="w-4 h-4" /> {{ $etiqueta }}
                </x-action-button>
            @endforeach

            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn-secondary"><x-icon name="download" class="w-4 h-4" /> Descargar factura</a>
            <a href="{{ route('invoices.recibo', $invoice) }}" target="_blank" class="btn-secondary"><x-icon name="receipt" class="w-4 h-4" /> Recibo del cliente</a>
            <a href="{{ route('invoices.etiqueta', $invoice) }}" target="_blank" class="btn-secondary"><x-icon name="box" class="w-4 h-4" /> Etiqueta del paquete</a>
            <x-action-button action="openIncidentForm" variant="secondary" loadingText="Abriendo...">
                <x-icon name="warning" class="w-4 h-4" /> Reportar incidencia
            </x-action-button>
        </div>
    </div>


    {{-- Anulación: el motivo es obligatorio y queda en la bitácora --}}
    @if ($showCancelForm)
        <div class="card border-red-200 dark:border-red-800">
            <h3 class="font-semibold mb-1">Anular la guía {{ $invoice->code }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                Queda registrado quién anuló y por qué. No se puede deshacer.
            </p>
            <label class="label">Motivo</label>
            <textarea wire:model="cancelReason" rows="2" class="input"
                      placeholder="Cliente desistió, error de digitación, paquete no apto…"></textarea>
            <div class="flex gap-3 mt-3">
                <x-action-button action="anular" variant="danger" loadingText="Anulando..."
                    confirm="¿Anular esta guía? Queda registrado y no se puede deshacer.">
                    <x-icon name="x" class="w-4 h-4" /> Confirmar anulación
                </x-action-button>
                <button type="button" wire:click="$set('showCancelForm', false)" class="btn-secondary">Cancelar</button>
            </div>
        </div>
    @endif

    {{-- Entrega: nombre, cédula y firma de quien retira --}}
    @if ($showDeliveryForm)
        <div class="card border-green-200 dark:border-green-800" wire:ignore.self>
            <h3 class="font-semibold mb-1">Registrar la entrega</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                Constancia de quién retiró el paquete.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Nombre de quien retira</label>
                    <input type="text" wire:model="receivedByName" class="input">
                </div>
                <div>
                    <label class="label">Identificación</label>
                    <input type="text" wire:model="receivedByIdentification" inputmode="numeric" class="input">
                </div>
            </div>

            {{-- Canvas propio, sin librería: son 30 líneas y evita una
                 dependencia externa que el CSP de producción podría bloquear. --}}
            <div class="mt-4" wire:ignore>
                <label class="label">Firma</label>
                <div class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white overflow-hidden">
                    <canvas id="firma" class="w-full touch-none" height="160" style="display:block; cursor:crosshair;"></canvas>
                </div>
                <button type="button" onclick="limpiarFirma()" class="btn-secondary !py-1.5 !px-3 text-sm mt-2">
                    Borrar firma
                </button>
            </div>

            <div class="flex gap-3 mt-4">
                <x-action-button action="entregar" variant="success" loadingText="Registrando...">
                    <x-icon name="check" class="w-4 h-4" /> Confirmar entrega
                </x-action-button>
                <button type="button" wire:click="$set('showDeliveryForm', false)" class="btn-secondary">Cancelar</button>
            </div>
        </div>

        @script
        <script>
            const lienzo = document.getElementById('firma');
            const ctx = lienzo.getContext('2d');
            let dibujando = false;

            // El canvas se dimensiona por CSS: hay que igualar su resolución
            // interna o la firma sale desplazada respecto al puntero.
            const ajustar = () => {
                const ancho = lienzo.offsetWidth;
                if (lienzo.width !== ancho) {
                    lienzo.width = ancho;
                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    ctx.strokeStyle = '#111';
                }
            };
            ajustar();
            window.addEventListener('resize', ajustar);

            const punto = (e) => {
                const r = lienzo.getBoundingClientRect();
                const t = e.touches ? e.touches[0] : e;
                return { x: t.clientX - r.left, y: t.clientY - r.top };
            };

            const empezar = (e) => { e.preventDefault(); dibujando = true; const p = punto(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); };
            const mover = (e) => { if (!dibujando) return; e.preventDefault(); const p = punto(e); ctx.lineTo(p.x, p.y); ctx.stroke(); };
            const soltar = () => {
                if (!dibujando) return;
                dibujando = false;
                // Se sube al componente al soltar, no en cada trazo.
                $wire.set('deliverySignature', lienzo.toDataURL('image/png'), false);
            };

            ['mousedown', 'touchstart'].forEach(ev => lienzo.addEventListener(ev, empezar));
            ['mousemove', 'touchmove'].forEach(ev => lienzo.addEventListener(ev, mover));
            ['mouseup', 'mouseleave', 'touchend'].forEach(ev => lienzo.addEventListener(ev, soltar));

            window.limpiarFirma = () => {
                ctx.clearRect(0, 0, lienzo.width, lienzo.height);
                $wire.set('deliverySignature', '', false);
            };
        </script>
        @endscript
    @endif


    {{-- Incidencias: registrar un problema sin mover el estado de la guía --}}
    @if ($showIncidentForm)
        <div class="card border-amber-200 dark:border-amber-800">
            <h3 class="font-semibold mb-1">Reportar una incidencia</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                Queda registrada sin cambiar el estado: un destinatario ausente deja la encomienda donde está.
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="label">Tipo</label>
                    <select wire:model="incidentType" class="input">
                        @foreach (\App\Models\GuideIncident::TYPES as $valor => $etiqueta)
                            <option value="{{ $valor }}">{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Qué pasó</label>
                    <input type="text" wire:model="incidentDescription" class="input"
                           placeholder="Se visitó a las 10:00 y no había nadie; se dejó aviso">
                </div>
            </div>
            <div class="flex gap-3 mt-3">
                <x-action-button action="registrarIncidencia" variant="primary" loadingText="Registrando...">Registrar</x-action-button>
                <button type="button" wire:click="$set('showIncidentForm', false)" class="btn-secondary">Cancelar</button>
            </div>
        </div>
    @endif

    @if ($invoice->incidents->isNotEmpty())
        <div class="card">
            <h3 class="font-semibold mb-3 flex items-center gap-2">
                <x-icon name="warning" class="w-5 h-5 text-amber-500" /> Incidencias
                @if ($invoice->incidents->whereNull('resolved_at')->isNotEmpty())
                    <span class="badge bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        {{ $invoice->incidents->whereNull('resolved_at')->count() }} abierta(s)
                    </span>
                @endif
            </h3>
            <div class="space-y-3">
                @foreach ($invoice->incidents as $incidencia)
                    <div class="rounded-lg border {{ $incidencia->estaResuelta()
                        ? 'border-gray-200 dark:border-gray-700 opacity-70'
                        : 'border-amber-200 dark:border-amber-800' }} p-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <span class="badge {{ $incidencia->badgeClasses() }}">{{ $incidencia->typeLabel() }}</span>
                                @if ($incidencia->estaResuelta())
                                    <span class="badge bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200">Resuelta</span>
                                @else
                                    <span class="text-xs text-gray-500">{{ $incidencia->diasAbierta() }} día(s) abierta</span>
                                @endif
                            </div>
                            @unless ($incidencia->estaResuelta())
                                <x-action-button action="resolverIncidencia({{ $incidencia->id }})" variant="link">
                                    Marcar resuelta
                                </x-action-button>
                            @endunless
                        </div>
                        <p class="text-sm mt-2">{{ $incidencia->description }}</p>
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $incidencia->reporter?->name }} · {{ $incidencia->reported_at->format('d/m/Y H:i') }}
                            @if ($incidencia->estaResuelta())
                                · resuelta por {{ $incidencia->resolver?->name }} el {{ $incidencia->resolved_at->format('d/m/Y') }}
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

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
            <div><span class="text-gray-500 block">Condición</span>{{ \App\Services\Hacienda\Catalogs::saleConditionLabel() }}</div>
            <div><span class="text-gray-500 block">Comprobante</span>{{ $invoice->billTypeLabel() }}</div>
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
                    @if ($ei->error_message && !$ei->wasRejected())
                        <div class="text-red-600 dark:text-red-400">{{ $ei->error_message }}</div>
                    @endif
                </div>

                @if ($ei->wasRejected())
                    <div class="mt-3">
                        <x-rejection-detail :electronic-invoice="$ei" />
                    </div>
                @endif

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

    @if ($invoice->cancellation_reason)
        <div class="card border-red-200 dark:border-red-800">
            <h3 class="font-semibold mb-1 text-red-800 dark:text-red-200">Motivo de la anulación</h3>
            <p class="text-sm">{{ $invoice->cancellation_reason }}</p>
            <p class="text-xs text-gray-500 mt-1">
                {{ $invoice->canceller?->name }} · {{ $invoice->cancelled_at?->format('d/m/Y H:i') }}
            </p>
        </div>
    @endif

    @if ($invoice->tieneEvidenciaDeEntrega())
        <div class="card">
            <h3 class="font-semibold mb-2 flex items-center gap-2"><x-icon name="check-circle" class="w-5 h-5 text-green-600" /> Evidencia de entrega</h3>
            <p class="text-sm">
                Retirada por <strong>{{ $invoice->received_by_name }}</strong>
                @if ($invoice->received_by_identification) · {{ $invoice->received_by_identification }} @endif
                @if ($invoice->delivered_at) · {{ $invoice->delivered_at->format('d/m/Y H:i') }} @endif
            </p>
            @if ($invoice->delivery_signature)
                <div class="mt-3 inline-block rounded-lg border border-gray-200 dark:border-gray-700 bg-white p-2">
                    <img src="{{ $invoice->delivery_signature }}" alt="Firma" style="max-width: 320px; height: auto;">
                </div>
            @endif
        </div>
    @endif

    <div class="card">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <h3 class="font-semibold mb-3 flex items-center gap-2"><x-icon name="truck" class="w-5 h-5 text-gray-400" /> Recorrido de la guía</h3>
            <div class="text-center">
                <div class="inline-block bg-white p-2 rounded-lg border border-gray-200 dark:border-gray-700">{!! $qrSvg !!}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Seguimiento público</div>
            </div>
        </div>
        <ol class="relative border-l border-gray-200 dark:border-gray-700 ml-2 space-y-4">
            @foreach ($invoice->statusHistories as $paso)
                <li class="ml-5">
                    <span class="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full
                        {{ $loop->last ? 'bg-brand-600' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="badge {{ \App\Models\Invoice::STATUS_BADGE_CLASSES[$paso->to_status] ?? '' }}">{{ $paso->toLabel() }}</span>
                        <span class="text-sm text-gray-500">{{ $paso->happened_at?->format('d/m/Y H:i') }}</span>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        @if ($paso->fromLabel()) Desde «{{ $paso->fromLabel() }}» · @endif
                        {{ $paso->branch?->name ?? 'Sede no registrada' }} ·
                        {{ $paso->user?->name ?? $paso->sourceLabel() }}
                    </div>
                    @if ($paso->note)
                        <div class="text-sm text-gray-600 dark:text-gray-300 mt-0.5">{{ $paso->note }}</div>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>

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
