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

    {{-- Antigüedad de saldos: lo primero que mira quien cobra --}}
    <div class="card">
        <h2 class="text-lg font-semibold mb-1">Cuentas por cobrar</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
            No importa solo cuánto deben, sino desde cuándo.
        </p>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            @foreach (['Al día', '1 – 30 días', '31 – 60 días', '61 – 90 días', 'Más de 90 días'] as $tramo)
                @php $datos = $antiguedad[$tramo] ?? null; @endphp
                <div class="rounded-lg border p-3
                    {{ $tramo === 'Al día'
                        ? 'border-gray-200 dark:border-gray-700'
                        : ($tramo === 'Más de 90 días'
                            ? 'border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20'
                            : 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20') }}">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $tramo }}</div>
                    <div class="font-semibold text-lg">₡{{ number_format($datos['total'] ?? 0, 2) }}</div>
                    <div class="text-xs text-gray-500">{{ $datos['cantidad'] ?? 0 }} estado(s)</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <label class="label">Cliente de crédito</label>
        <select wire:model.live="customerId" class="input sm:max-w-md">
            <option value="">— Elegir cliente —</option>
            @foreach ($clientes as $c)
                <option value="{{ $c->id }}">{{ $c->name }}@if ($c->identification) · {{ $c->identification }}@endif</option>
            @endforeach
        </select>
        @if ($clientes->isEmpty())
            <p class="text-sm text-gray-500 mt-2">
                No hay clientes de crédito. Marcá la condición «Crédito» en la ficha de un cliente.
            </p>
        @endif
    </div>

    @if ($cliente)
        <div class="card space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ $cliente->name }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Límite ₡{{ number_format((float) $cliente->credit_limit, 2) }}
                        @if ($cliente->credit_cutoff_day) · corte el día {{ $cliente->credit_cutoff_day }} @endif
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                <div><span class="text-gray-500 block">Sin cortar</span><strong>₡{{ number_format($sinCortar, 2) }}</strong></div>
                <div><span class="text-gray-500 block">Facturado sin pagar</span><strong>₡{{ number_format($facturado, 2) }}</strong></div>
                <div><span class="text-gray-500 block">Saldo total</span><strong class="text-lg">₡{{ number_format($saldoTotal, 2) }}</strong></div>
                <div>
                    <span class="text-gray-500 block">Disponible</span>
                    <strong class="text-lg {{ $disponible < 0 ? 'text-red-700 dark:text-red-300' : 'text-green-700 dark:text-green-300' }}">
                        ₡{{ number_format($disponible, 2) }}
                    </strong>
                </div>
            </div>

            @if ($disponible < 0)
                <div class="flex items-start gap-3 p-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm">
                    <x-icon name="warning" class="w-5 h-5 mt-0.5" />
                    <span>Este cliente pasó su límite de crédito por ₡{{ number_format(abs($disponible), 2) }}.</span>
                </div>
            @endif

            {{-- Corte --}}
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="font-semibold mb-1">Cortar período</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                    Agrupa las {{ $pendientes->count() }} guía(s) acumuladas en un estado de cuenta por
                    ₡{{ number_format($sinCortar, 2) }}.
                </p>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="label">Plazo de crédito (días)</label>
                        <input type="number" min="1" max="365" wire:model="creditTermDays" class="input w-32">
                    </div>
                    <x-action-button action="cortar" variant="primary" loadingText="Cortando..."
                        :disabled="$pendientes->isEmpty()">
                        <x-icon name="receipt" class="w-4 h-4" /> Emitir estado de cuenta
                    </x-action-button>
                </div>
            </div>

            {{-- Abono --}}
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="font-semibold mb-3">Registrar abono</h3>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="label">Monto (₡)</label>
                        <input type="number" step="0.01" wire:model="paymentAmount" class="input">
                    </div>
                    <div>
                        <label class="label">Medio</label>
                        <select wire:model="paymentMethod" class="input">
                            @foreach (\App\Models\Invoice::PAYMENT_METHODS as $valor => $etiqueta)
                                <option value="{{ $valor }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Aplicar a</label>
                        <select wire:model="paymentStatementId" class="input">
                            <option value="">Del más viejo al más nuevo</option>
                            @foreach ($estados->where('status', 'issued') as $e)
                                <option value="{{ $e->id }}">{{ $e->code }} · ₡{{ number_format((float) $e->balance, 2) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Referencia</label>
                        <input type="text" wire:model="paymentReference" placeholder="N.º de depósito" class="input">
                    </div>
                </div>
                <div class="mt-3">
                    <x-action-button action="abonar" variant="secondary" loadingText="Registrando...">Registrar abono</x-action-button>
                </div>
            </div>
        </div>

        {{-- Guías acumuladas --}}
        @if ($pendientes->isNotEmpty())
            <div class="card">
                <h3 class="font-semibold mb-3">Guías acumuladas desde el último corte</h3>
                <div class="data-table-wrap">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2">Guía</th><th class="py-2">Fecha</th>
                                <th class="py-2">Destinatario</th><th class="py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pendientes as $g)
                                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                    <td class="py-2 font-mono text-sm">{{ $g->code }}</td>
                                    <td class="py-2 text-sm">{{ $g->created_at->format('d/m/Y') }}</td>
                                    <td class="py-2 text-sm">{{ $g->recipient_name }}</td>
                                    <td class="py-2 text-right">₡{{ number_format((float) $g->total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Estados de cuenta --}}
        <div class="card">
            <h3 class="font-semibold mb-3">Estados de cuenta</h3>
            <div class="data-table-wrap">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2">Código</th><th class="py-2">Período</th><th class="py-2">Vence</th>
                            <th class="py-2 text-right">Total</th><th class="py-2 text-right">Saldo</th>
                            <th class="py-2">Estado</th><th class="py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($estados as $e)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="py-2 font-mono text-sm">{{ $e->code }}</td>
                                <td class="py-2 text-sm">{{ $e->periodoLabel() }}</td>
                                <td class="py-2 text-sm">
                                    {{ $e->due_date?->format('d/m/Y') }}
                                    @if ($e->estaVencido())
                                        <div class="text-xs text-red-600 dark:text-red-400">{{ $e->tramoAntiguedad() }}</div>
                                    @endif
                                </td>
                                <td class="py-2 text-right text-sm">₡{{ number_format((float) $e->total, 2) }}</td>
                                <td class="py-2 text-right">₡{{ number_format((float) $e->balance, 2) }}</td>
                                <td class="py-2">
                                    <span class="badge {{ $e->estaSaldado()
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                        : ($e->estaVencido()
                                            ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'
                                            : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200') }}">
                                        {{ $e->statusLabel() }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <a href="{{ route('credito.pdf', $e) }}" target="_blank" class="text-gray-500 text-sm">PDF</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-gray-500">Este cliente no tiene estados de cuenta.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
