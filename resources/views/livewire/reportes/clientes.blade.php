{{-- Facturas por cliente: resumen por cédula del receptor y, al elegir uno,
     cada comprobante con su guía. --}}
<div class="card" data-test="reporte-clientes">
    @if (($datos['vista'] ?? '') === 'detalle')
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h2 class="text-lg font-semibold">{{ $datos['nombre'] }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $cliente }}</p>
            </div>
            <button type="button" wire:click="$set('cliente', '')" class="btn-secondary !py-2 !px-3 text-sm">
                ← Todos los clientes
            </button>
        </div>

        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Fecha</th>
                        <th class="py-2">Comprobante</th>
                        <th class="py-2">Guía</th>
                        <th class="py-2">Estado en Hacienda</th>
                        <th class="py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($datos['filas'] as $fila)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-2 text-sm whitespace-nowrap">{{ $fila['fecha'] }}</td>
                            <td class="py-2 text-sm">
                                {{ $fila['tipo'] }}
                                <div class="text-xs text-gray-500 font-mono">{{ $fila['consecutivo'] }}</div>
                                @if ($fila['exonerado'])
                                    <span class="badge bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Exonerado</span>
                                @endif
                            </td>
                            <td class="py-2 text-sm">
                                @if ($fila['guia'])
                                    <a href="{{ route('invoices.show', $fila['guia']) }}" class="text-brand-600 dark:text-brand-400 hover:underline font-mono">{{ $fila['guia']->code }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 text-sm">
                                <span class="badge {{ match ($fila['estado']) {
                                    'accepted' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
                                    'rejected', 'error' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
                                    default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
                                } }}">{{ $fila['estadoNombre'] }}</span>
                            </td>
                            <td class="py-2 text-right tabular-nums">₡{{ number_format($fila['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">Este cliente no tiene comprobantes en el período.</td></tr>
                    @endforelse
                </tbody>
                @if ($datos['filas']->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-gray-600 font-semibold">
                            <td class="py-2" colspan="4">Facturado (aceptado, menos notas de crédito)</td>
                            <td class="py-2 text-right tabular-nums">₡{{ number_format($datos['monto'], 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @else
        <h2 class="text-lg font-semibold mb-1">Facturas por cliente</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
            Comprobantes emitidos a nombre de cada cédula (los tiquetes no tienen receptor). El monto es lo aceptado por Hacienda, con las notas de crédito restando.
        </p>

        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Cliente</th>
                        <th class="py-2 text-right">Comprobantes</th>
                        <th class="py-2 text-right">Aceptados</th>
                        <th class="py-2 text-right">Con problema</th>
                        <th class="py-2 text-right">Pendientes</th>
                        <th class="py-2 text-right">Facturado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($datos['filas'] as $fila)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer"
                            wire:click="verCliente('{{ $fila['cedula'] }}')" wire:key="cli-{{ $fila['cedula'] }}">
                            <td class="py-2">
                                <span class="text-brand-600 dark:text-brand-400 hover:underline">{{ $fila['nombre'] }}</span>
                                <div class="text-xs text-gray-500 font-mono">{{ $fila['cedula'] }}</div>
                            </td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($fila['cantidad']) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($fila['aceptados']) }}</td>
                            <td class="py-2 text-right tabular-nums {{ $fila['problemas'] ? 'text-red-600 dark:text-red-400 font-semibold' : '' }}">{{ number_format($fila['problemas']) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($fila['pendientes']) }}</td>
                            <td class="py-2 text-right tabular-nums">₡{{ number_format($fila['monto'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No hay facturas electrónicas en el período y la sede seleccionados.</td></tr>
                    @endforelse
                </tbody>
                @if ($datos['filas']->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-gray-600 font-semibold">
                            <td class="py-2">Total</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($datos['filas']->sum('cantidad')) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($datos['filas']->sum('aceptados')) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($datos['filas']->sum('problemas')) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($datos['filas']->sum('pendientes')) }}</td>
                            <td class="py-2 text-right tabular-nums">₡{{ number_format($datos['filas']->sum('monto'), 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif
</div>
