{{-- Cierres de caja: los turnos cerrados del período y, al elegir uno, su
     detalle con los movimientos y el arqueo. --}}
<div class="card" data-test="reporte-caja">
    @php
        $badgeDiferencia = fn ($s) => $s->cuadra()
            ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
            : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200';
        $diferencia = fn (float $d) => ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . '₡' . number_format(abs($d), 2);
    @endphp

    @if (($datos['vista'] ?? '') === 'detalle')
        @php
            $sesion = $datos['sesion'];
            $sede = $sesion->branch ?? $sesion->register?->branch;
            $abierto = $sesion->closed_at === null;
            $variosDias = $sesion->opened_at && ! $sesion->opened_at->isSameDay($sesion->closed_at ?? now());
            $d = $datos['desglose'];
        @endphp

        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h2 class="text-lg font-semibold">
                    Turno #{{ $sesion->id }} · {{ $sesion->register?->name ?? 'Caja' }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $sede?->name ?? 'Sin sede' }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('caja.pdf', $sesion) }}" target="_blank" class="btn-secondary !py-2 !px-3 text-sm">PDF</a>
                <button type="button" wire:click="$set('turno', null)" class="btn-secondary !py-2 !px-3 text-sm">
                    ← Todos los cierres
                </button>
            </div>
        </div>

        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6 text-sm">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <dt class="text-xs uppercase text-gray-500 dark:text-gray-400">Apertura</dt>
                <dd>{{ $sesion->opened_at?->format('d/m/Y H:i') }}</dd>
                <dd class="text-gray-500 dark:text-gray-400">{{ $sesion->opener?->name }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <dt class="text-xs uppercase text-gray-500 dark:text-gray-400">Cierre</dt>
                <dd>{{ $sesion->closed_at?->format('d/m/Y H:i') ?? 'Turno abierto' }}</dd>
                <dd class="text-gray-500 dark:text-gray-400">{{ $sesion->closer?->name }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <dt class="text-xs uppercase text-gray-500 dark:text-gray-400">Fondo inicial</dt>
                <dd class="tabular-nums">₡{{ number_format((float) $sesion->opening_float, 2) }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <dt class="text-xs uppercase text-gray-500 dark:text-gray-400">Diferencia</dt>
                <dd>
                    @if ($abierto)
                        <span class="text-gray-500">Sin arqueo</span>
                    @else
                        <span class="badge {{ $badgeDiferencia($sesion) }}">{{ $diferencia((float) $sesion->discrepancy) }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div>
                <h3 class="font-semibold mb-2">Cobros por medio de pago</h3>
                <table class="w-full text-left text-sm">
                    <tbody>
                        @forelse ($datos['porMedio'] as $medio)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="py-1.5">{{ $medio['etiqueta'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $medio['cantidad'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">₡{{ number_format($medio['total'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-gray-500">Sin cobros en el turno.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Solo el efectivo entra a la gaveta: tarjeta, SINPE y transferencia no se cuentan en el arqueo.</p>
            </div>

            {{-- De dónde sale el esperado, para poder rehacer la cuenta a mano. --}}
            <div>
                <h3 class="font-semibold mb-2">Arqueo</h3>
                <table class="w-full text-sm tabular-nums">
                    <tbody>
                        <tr><td class="py-1">Fondo inicial</td><td class="py-1 text-right">₡{{ number_format((float) $sesion->opening_float, 2) }}</td></tr>
                        <tr><td class="py-1">+ Cobros en efectivo</td><td class="py-1 text-right">₡{{ number_format($d['cobros'], 2) }}</td></tr>
                        @if ($d['entradas'] > 0)
                            <tr><td class="py-1">+ Entradas</td><td class="py-1 text-right">₡{{ number_format($d['entradas'], 2) }}</td></tr>
                        @endif
                        @if ($d['salidas'] > 0)
                            <tr><td class="py-1">− Salidas</td><td class="py-1 text-right">−₡{{ number_format($d['salidas'], 2) }}</td></tr>
                        @endif
                        @unless ($abierto)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">Efectivo esperado</td><td class="py-1 text-right">₡{{ number_format((float) $sesion->expected_cash, 2) }}</td></tr>
                            <tr><td class="py-1">Efectivo contado</td><td class="py-1 text-right">₡{{ number_format((float) $sesion->counted_cash, 2) }}</td></tr>
                            <tr class="border-t-2 border-gray-300 dark:border-gray-600 font-semibold">
                                <td class="py-1">{{ $sesion->hayFaltante() ? 'Faltante' : ($sesion->haySobrante() ? 'Sobrante' : 'Diferencia') }}</td>
                                <td class="py-1 text-right {{ $sesion->cuadra() ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                                    ₡{{ number_format(abs((float) $sesion->discrepancy), 2) }}
                                </td>
                            </tr>
                        @endunless
                    </tbody>
                </table>

                @if ($sesion->counts->where('quantity', '>', 0)->isNotEmpty())
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-gray-600 dark:text-gray-300">Conteo por denominación</summary>
                        <table class="w-full mt-2 tabular-nums">
                            @foreach ($sesion->counts->where('quantity', '>', 0)->sortByDesc(fn ($c) => $c->denomination?->value) as $c)
                                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                    <td class="py-1">{{ $c->denomination?->label() }}</td>
                                    <td class="py-1 text-right">× {{ $c->quantity }}</td>
                                    <td class="py-1 text-right">₡{{ number_format((float) $c->subtotal, 2) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </details>
                @endif
            </div>
        </div>

        @if ($sesion->closing_note)
            <p class="text-sm mb-6"><strong>Nota de cierre:</strong> {{ $sesion->closing_note }}</p>
        @endif

        <h3 class="font-semibold mb-2">Movimientos del turno</h3>
        <div class="data-table-wrap">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">{{ $variosDias ? 'Fecha' : 'Hora' }}</th>
                        <th class="py-2">Tipo</th>
                        <th class="py-2">Referencia</th>
                        <th class="py-2">Medio</th>
                        <th class="py-2">Registró</th>
                        <th class="py-2 text-right">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sesion->movements as $m)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-2 whitespace-nowrap">{{ $m->happened_at?->format($variosDias ? 'd/m H:i' : 'H:i') }}</td>
                            <td class="py-2">{{ $m->typeLabel() }}</td>
                            <td class="py-2">
                                @if ($m->invoice)
                                    <a href="{{ route('invoices.show', $m->invoice) }}" class="text-brand-600 dark:text-brand-400 hover:underline font-mono">{{ $m->invoice->code }}</a>
                                @else
                                    {{ $m->reference ?: $m->reason }}
                                @endif
                            </td>
                            <td class="py-2">{{ $m->paymentMethodLabel() }}</td>
                            <td class="py-2 text-gray-500 dark:text-gray-400">{{ $m->creator?->name }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $m->type === \App\Models\CashMovement::TYPE_OUT ? '−' : '' }}₡{{ number_format((float) $m->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">Sin movimientos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        @php($filas = $datos['filas'] ?? collect())

        <h2 class="text-lg font-semibold mb-4">Cierres de caja</h2>

        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Caja</th>
                        <th class="py-2">Turno</th>
                        <th class="py-2">Cajero</th>
                        <th class="py-2 text-right">Esperado</th>
                        <th class="py-2 text-right">Contado</th>
                        <th class="py-2 text-right">Diferencia</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($filas as $s)
                        @php($sede = $s->branch ?? $s->register?->branch)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/50" wire:key="turno-{{ $s->id }}">
                            <td class="py-2 text-sm">
                                {{-- Varias sedes tienen su «Caja principal»: sin la sede delante no se distinguen. --}}
                                <span class="badge bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200 font-mono" title="{{ $sede?->name ?? 'Sin sede' }}">{{ $sede?->prefixLabel() ?: '—' }}</span>
                                {{ $s->register?->name ?? 'Caja' }}
                            </td>
                            <td class="py-2 text-sm whitespace-nowrap">
                                {{ $s->opened_at?->format('d/m H:i') }} – {{ $s->closed_at?->format($s->opened_at?->isSameDay($s->closed_at) ? 'H:i' : 'd/m H:i') }}
                            </td>
                            <td class="py-2 text-sm">
                                {{ $s->opener?->name }}
                                @if ($s->closed_by && $s->closed_by !== $s->opened_by)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">Cerró {{ $s->closer?->name }}</span>
                                @endif
                            </td>
                            <td class="py-2 text-right text-sm tabular-nums">₡{{ number_format((float) $s->expected_cash, 2) }}</td>
                            <td class="py-2 text-right text-sm tabular-nums">₡{{ number_format((float) $s->counted_cash, 2) }}</td>
                            <td class="py-2 text-right">
                                <span class="badge tabular-nums {{ $badgeDiferencia($s) }}">{{ $diferencia((float) $s->discrepancy) }}</span>
                            </td>
                            <td class="py-2 text-right whitespace-nowrap">
                                {{-- Link de verdad, para poder abrirlo en otra pestaña; con un clic normal no recarga. --}}
                                <a href="{{ route('reportes.index', ['reporte' => 'caja', 'turno' => $s->id]) }}"
                                   wire:click.prevent="verTurno({{ $s->id }})"
                                   class="text-brand-600 dark:text-brand-400 hover:underline text-sm">Ver detalle</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-gray-500">
                                No hay cierres de caja para el período y los filtros seleccionados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($filas->isNotEmpty())
                    @php($descuadres = $filas->reject->cuadra()->count())
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-gray-600 font-semibold">
                            <td class="py-2" colspan="3">
                                {{ $filas->count() }} {{ $filas->count() === 1 ? 'turno' : 'turnos' }}
                                <span class="font-normal text-sm {{ $descuadres ? 'text-red-600 dark:text-red-400' : 'text-gray-500' }}">
                                    · {{ $descuadres }} {{ $descuadres === 1 ? 'descuadrado' : 'descuadrados' }}
                                </span>
                            </td>
                            <td class="py-2 text-right tabular-nums">₡{{ number_format((float) $filas->sum('expected_cash'), 2) }}</td>
                            <td class="py-2 text-right tabular-nums">₡{{ number_format((float) $filas->sum('counted_cash'), 2) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $diferencia((float) $filas->sum('discrepancy')) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif
</div>
