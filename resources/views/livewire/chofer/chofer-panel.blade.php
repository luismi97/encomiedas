<div class="space-y-4">
    @if ($feedback)
        <div class="flex items-start gap-3 p-4 rounded-lg border text-base
            {{ $feedbackType === 'error'
                ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200'
                : 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200' }}">
            <x-icon name="{{ $feedbackType === 'error' ? 'warning' : 'check-circle' }}" class="w-5 h-5 mt-0.5" />
            <span class="flex-1">{{ $feedback }}</span>
            <button type="button" wire:click="dismissFeedback" class="opacity-60 hover:opacity-100" aria-label="Cerrar">
                <x-icon name="x" class="w-4 h-4" />
            </button>
        </div>
    @endif

    @if (! $cierre)
        <div class="card">
            <h2 class="text-lg font-semibold mb-1">Mis cierres en ruta</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Los viajes que traés asignados.</p>

            @forelse ($cierres as $c)
                <button type="button" wire:click="abrirCierre({{ $c->id }})"
                        class="w-full text-left rounded-lg border border-gray-200 dark:border-gray-700 p-4 mb-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="font-mono font-semibold">{{ $c->code }}</div>
                            <div class="text-sm text-gray-500">{{ $c->rutaLabel() }}</div>
                        </div>
                        <span class="badge {{ $c->badgeClasses() }}">{{ $c->statusLabel() }}</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Salió {{ $c->departed_at?->format('d/m/Y H:i') }} · {{ $c->vehicle_plate }}
                    </div>
                </button>
            @empty
                <p class="text-gray-500">No tenés cierres en ruta asignados en este momento.</p>
            @endforelse
        </div>
    @else
        {{-- Escaneo: lo primero y más grande, es lo que más se usa --}}
        <div class="card">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <div class="font-mono font-semibold text-lg">{{ $cierre->code }}</div>
                    <div class="text-sm text-gray-500">{{ $cierre->rutaLabel() }}</div>
                </div>
                <button type="button" wire:click="$set('dispatchId', null)" class="btn-secondary !py-1.5 !px-3 text-sm">
                    Volver
                </button>
            </div>

            <label class="label">Escanear guía</label>
            <div class="flex gap-2">
                <input type="text" wire:model="scanCode" wire:keydown.enter.prevent="escanear"
                       placeholder="Escaneá el QR" inputmode="none"
                       class="input flex-1 font-mono text-lg" autofocus>
                <x-action-button action="escanear" variant="primary" loadingText="...">Marcar</x-action-button>
            </div>
            <p class="text-xs text-gray-500 mt-1">
                {{ $cierre->recibidas()->count() }} de {{ $cierre->lines->count() }} marcadas como llegadas.
            </p>
        </div>

        {{-- Guías del viaje --}}
        <div class="space-y-3">
            @foreach ($cierre->lines as $linea)
                @php $guia = $linea->invoice; @endphp
                @continue (! $guia)

                <div class="card !p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-mono font-semibold">{{ $guia->code }}</div>
                            <div class="text-sm">{{ $guia->recipient_name }}</div>
                            @if ($guia->recipient_phone)
                                <a href="tel:{{ $guia->recipient_phone }}" class="text-sm text-brand-600 dark:text-brand-300">
                                    {{ $guia->recipient_phone }}
                                </a>
                            @endif
                            <div class="text-xs text-gray-500 mt-1">
                                {{ $guia->items->count() }} paquete(s) · {{ $guia->deliveryBranch?->name }}
                            </div>
                        </div>
                        <span class="badge shrink-0 {{ \App\Models\Invoice::STATUS_BADGE_CLASSES[$guia->status] ?? '' }}">
                            {{ $guia->statusLabel() }}
                        </span>
                    </div>

                    {{-- Entrega con firma --}}
                    @if ($deliveringId === $guia->id)
                        <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700" wire:key="entrega-{{ $guia->id }}">
                            <label class="label">Quién retira</label>
                            <input type="text" wire:model="receivedByName" class="input mb-2">
                            <label class="label">Identificación</label>
                            <input type="text" wire:model="receivedByIdentification" inputmode="numeric" class="input mb-2">

                            <label class="label">Firma</label>
                            <div wire:ignore class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white overflow-hidden">
                                <canvas id="firma-chofer" class="w-full touch-none" height="150" style="display:block;"></canvas>
                            </div>

                            <div class="flex gap-2 mt-3">
                                <x-action-button action="entregar" variant="success" loadingText="Guardando...">
                                    <x-icon name="check" class="w-4 h-4" /> Confirmar entrega
                                </x-action-button>
                                <button type="button" wire:click="$set('deliveringId', null)" class="btn-secondary">Cancelar</button>
                            </div>
                        </div>

                        @script
                        <script>
                            const c = document.getElementById('firma-chofer');
                            if (c) {
                                const ctx = c.getContext('2d');
                                let trazando = false;
                                c.width = c.offsetWidth;
                                ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
                                const p = e => { const r = c.getBoundingClientRect(), t = e.touches ? e.touches[0] : e;
                                                 return { x: t.clientX - r.left, y: t.clientY - r.top }; };
                                ['mousedown','touchstart'].forEach(ev => c.addEventListener(ev, e => {
                                    e.preventDefault(); trazando = true; const q = p(e); ctx.beginPath(); ctx.moveTo(q.x, q.y); }));
                                ['mousemove','touchmove'].forEach(ev => c.addEventListener(ev, e => {
                                    if (!trazando) return; e.preventDefault(); const q = p(e); ctx.lineTo(q.x, q.y); ctx.stroke(); }));
                                ['mouseup','mouseleave','touchend'].forEach(ev => c.addEventListener(ev, () => {
                                    if (!trazando) return; trazando = false;
                                    $wire.set('deliverySignature', c.toDataURL('image/png'), false); }));
                            }
                        </script>
                        @endscript

                    {{-- Incidencia --}}
                    @elseif ($incidentInvoiceId === $guia->id)
                        <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                            <label class="label">Qué pasó</label>
                            <select wire:model="incidentType" class="input mb-2">
                                @foreach (\App\Models\GuideIncident::TYPES as $valor => $etiqueta)
                                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                            <input type="text" wire:model="incidentDescription" class="input"
                                   placeholder="Se visitó a las 10:00 y no había nadie">
                            <div class="flex gap-2 mt-3">
                                <x-action-button action="registrarIncidencia" variant="primary" loadingText="...">Registrar</x-action-button>
                                <button type="button" wire:click="$set('incidentInvoiceId', null)" class="btn-secondary">Cancelar</button>
                            </div>
                        </div>
                    @else
                        <div class="flex flex-wrap gap-3 mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                            @if ($guia->puedePasarA(\App\Models\Invoice::STATUS_DELIVERED))
                                <x-action-button action="abrirEntrega({{ $guia->id }})" variant="link">Entregar</x-action-button>
                            @endif
                            <x-action-button action="abrirIncidencia({{ $guia->id }})" variant="link-muted">Reportar problema</x-action-button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
