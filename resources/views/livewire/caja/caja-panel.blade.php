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

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-gray-500 dark:text-gray-400">
            Todo cobro de contado entra al turno abierto. Sin caja abierta, el cobro no llega al arqueo.
        </p>
        <select wire:model.live="registerId" class="input sm:max-w-[260px]">
            @foreach ($cajas as $c)
                <option value="{{ $c->id }}">{{ $c->name }} — {{ $c->branch?->name }}</option>
            @endforeach
        </select>
    </div>

    @if (! $sesion)
        <div class="card">
            <h2 class="text-lg font-semibold mb-1">Abrir turno</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                El fondo inicial es el efectivo con el que arranca la gaveta. Es la base del arqueo al cerrar.
            </p>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="label">Fondo inicial (₡)</label>
                    <input type="number" step="0.01" wire:model="openingFloat" class="input sm:w-48">
                </div>
                <x-action-button action="abrir" variant="primary" loadingText="Abriendo...">
                    <x-icon name="check-circle" class="w-4 h-4" /> Abrir caja
                </x-action-button>
            </div>
        </div>
    @else
        <div class="card space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Turno abierto</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Desde {{ $sesion->opened_at->format('d/m/Y H:i') }} · {{ $sesion->opener?->name }}
                    </p>
                </div>
                <a href="{{ route('caja.pdf', $sesion) }}" target="_blank" class="btn-secondary !py-2 !px-3 text-sm">
                    <x-icon name="document" class="w-4 h-4" /> Reporte del turno
                </a>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                <div><span class="text-gray-500 block">Fondo inicial</span><strong>₡{{ number_format((float) $sesion->opening_float, 2) }}</strong></div>
                <div><span class="text-gray-500 block">Movimientos</span><strong>{{ $sesion->movements->count() }}</strong></div>
                <div><span class="text-gray-500 block">Efectivo esperado</span><strong class="text-lg">₡{{ number_format($esperado, 2) }}</strong></div>
                <div><span class="text-gray-500 block">Otros medios</span>
                    <strong>₡{{ number_format($porMedio->reject(fn ($m, $k) => $k === 'cash')->sum('total'), 2) }}</strong>
                </div>
            </div>

            @if ($porMedio->isNotEmpty())
                <div class="flex flex-wrap gap-2">
                    @foreach ($porMedio as $medio)
                        <span class="badge bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                            {{ $medio['etiqueta'] }}: ₡{{ number_format($medio['total'], 2) }} ({{ $medio['cantidad'] }})
                        </span>
                    @endforeach
                </div>
            @endif

            {{-- Entradas y salidas de efectivo --}}
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="font-semibold mb-3">Entrada o salida de efectivo</h3>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="label">Tipo</label>
                        <select wire:model="movementType" class="input">
                            <option value="in">Entrada</option>
                            <option value="out">Salida</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Monto (₡)</label>
                        <input type="number" step="0.01" wire:model="movementAmount" class="input">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label">Motivo</label>
                        <input type="text" wire:model="movementReason" placeholder="Reposición de sencillo, pago de mensajería…" class="input">
                    </div>
                </div>
                <div class="mt-3">
                    <x-action-button action="registrarMovimiento" variant="secondary" loadingText="Registrando...">Registrar</x-action-button>
                </div>
            </div>

            {{-- Arqueo --}}
            @if (! $showArqueo)
                <x-action-button action="abrirArqueo" variant="primary" loadingText="Abriendo...">
                    <x-icon name="banknotes" class="w-4 h-4" /> Cerrar turno y hacer arqueo
                </x-action-button>
            @else
                <div class="rounded-lg border border-brand-200 dark:border-brand-800 p-4 space-y-4">
                    <div>
                        <h3 class="font-semibold">Arqueo</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Contá por denominación. El sistema compara contra lo esperado y guarda la diferencia.
                        </p>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @foreach ($denominaciones as $d)
                            <div>
                                <label class="label">{{ $d->label() }}</label>
                                <input type="number" min="0" wire:model.live="counts.{{ $d->id }}" class="input">
                            </div>
                        @endforeach
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                        <div><span class="text-gray-500 block">Esperado</span><strong>₡{{ number_format($esperado, 2) }}</strong></div>
                        <div><span class="text-gray-500 block">Contado</span><strong>₡{{ number_format($this->contado, 2) }}</strong></div>
                        <div>
                            <span class="text-gray-500 block">Diferencia</span>
                            @php $dif = round($this->contado - $esperado, 2); @endphp
                            <strong class="{{ abs($dif) < 0.01 ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300' }}">
                                {{ $dif > 0 ? '+' : '' }}₡{{ number_format($dif, 2) }}
                            </strong>
                        </div>
                    </div>

                    <div>
                        <label class="label">Nota de cierre</label>
                        <textarea wire:model="closingNote" rows="2" class="input" placeholder="Explicación de la diferencia, si la hay"></textarea>
                    </div>

                    <div class="flex gap-3">
                        <x-action-button action="cerrar" variant="primary" loadingText="Cerrando..."
                            confirm="¿Cerrar el turno con este arqueo? No se puede reabrir.">
                            <x-icon name="check" class="w-4 h-4" /> Confirmar cierre
                        </x-action-button>
                        <button type="button" wire:click="$set('showArqueo', false)" class="btn-secondary">Cancelar</button>
                    </div>
                </div>
            @endif
        </div>

        {{-- Movimientos del turno --}}
        <div class="card">
            <h3 class="font-semibold mb-3">Movimientos del turno</h3>
            <div class="data-table-wrap">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2">Hora</th>
                            <th class="py-2">Tipo</th>
                            <th class="py-2">Referencia</th>
                            <th class="py-2">Medio</th>
                            <th class="py-2 text-right">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sesion->movements as $m)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="py-2 text-sm">{{ $m->happened_at->format('H:i') }}</td>
                                <td class="py-2 text-sm">{{ $m->typeLabel() }}</td>
                                <td class="py-2 text-sm">
                                    <span class="font-mono">{{ $m->reference ?: '—' }}</span>
                                    @if ($m->reason)<div class="text-xs text-gray-500">{{ $m->reason }}</div>@endif
                                </td>
                                <td class="py-2 text-sm">{{ $m->paymentMethodLabel() }}</td>
                                <td class="py-2 text-right {{ $m->type === 'out' ? 'text-red-600 dark:text-red-400' : '' }}">
                                    {{ $m->type === 'out' ? '−' : '' }}₡{{ number_format((float) $m->amount, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-gray-500">Todavía no hay movimientos en este turno.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card">
        <h3 class="font-semibold mb-3">Últimos turnos cerrados</h3>
        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Caja</th>
                        <th class="py-2">Cerrado</th>
                        <th class="py-2">Cajero</th>
                        <th class="py-2 text-right">Esperado</th>
                        <th class="py-2 text-right">Contado</th>
                        <th class="py-2 text-right">Diferencia</th>
                        <th class="py-2 text-right"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($historial as $t)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-2 text-sm">{{ $t->register?->name }} · {{ $t->register?->branch?->name }}</td>
                            <td class="py-2 text-sm">{{ $t->closed_at?->format('d/m/Y H:i') }}</td>
                            <td class="py-2 text-sm">{{ $t->closer?->name }}</td>
                            <td class="py-2 text-right text-sm">₡{{ number_format((float) $t->expected_cash, 2) }}</td>
                            <td class="py-2 text-right text-sm">₡{{ number_format((float) $t->counted_cash, 2) }}</td>
                            <td class="py-2 text-right">
                                <span class="badge {{ $t->cuadra()
                                    ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                    : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' }}">
                                    {{ (float) $t->discrepancy > 0 ? '+' : '' }}₡{{ number_format((float) $t->discrepancy, 2) }}
                                </span>
                            </td>
                            <td class="py-2 text-right">
                                <a href="{{ route('caja.pdf', $t) }}" target="_blank" class="text-gray-500 text-sm">PDF</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No hay turnos cerrados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
