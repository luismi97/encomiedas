<div class="space-y-6">
    <div class="card space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <div class="sm:col-span-2">
                <label class="label">Reporte</label>
                <select wire:model.live="reporte" class="input">
                    @foreach (\App\Livewire\Reportes\ReportePanel::REPORTES as $valor => $etiqueta)
                        <option value="{{ $valor }}">{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="label">Desde</label><input type="date" wire:model.live="from" class="input"></div>
            <div><label class="label">Hasta</label><input type="date" wire:model.live="to" class="input"></div>
        </div>
        <div class="sm:max-w-xs">
            <label class="label">Sede</label>
            <select wire:model.live="branchId" class="input">
                <option value="">Todas las sedes</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="card">
        <h2 class="text-lg font-semibold mb-4">
            {{ \App\Livewire\Reportes\ReportePanel::REPORTES[$reporte] }}
        </h2>

        @php
            $filas = $datos['filas'] ?? collect();
            $columnas = $datos['columnas'] ?? [];
            $conExtra = $datos['conExtra'] ?? false;
            $sinMoneda = $datos['sinMoneda'] ?? false;
            // Columna aparte para la plata que todavía no entró: mezclarla con
            // el monto haría leer como cobrado lo que solo está prometido.
            $conPorCobrar = $datos['conPorCobrar'] ?? false;
        @endphp

        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        @foreach ($columnas as $i => $col)
                            <th class="py-2 {{ $i >= count($columnas) - ($conPorCobrar ? 3 : 2) ? 'text-right' : '' }}">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($filas as $fila)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-2">{{ $fila['etiqueta'] }}</td>
                            @if ($conExtra)
                                <td class="py-2 text-sm text-gray-500">{{ $fila['extra'] ?? '' }}</td>
                            @endif
                            <td class="py-2 text-right tabular-nums">{{ number_format($fila['cantidad']) }}</td>
                            <td class="py-2 text-right tabular-nums">
                                {{ $sinMoneda ? number_format($fila['monto'], 1) : '₡' . number_format($fila['monto'], 2) }}
                            </td>
                            @if ($conPorCobrar)
                                <td class="py-2 text-right tabular-nums">
                                    @if (($fila['por_cobrar'] ?? 0) > 0)
                                        <span class="badge bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                            ₡{{ number_format($fila['por_cobrar'], 2) }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ max(1, count($columnas)) }}" class="py-6 text-center text-gray-500">
                                No hay datos para el período y la sede seleccionados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($filas->isNotEmpty() && ! $sinMoneda)
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-gray-600 font-semibold">
                            <td class="py-2" colspan="{{ $conExtra ? 2 : 1 }}">Total</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($filas->sum('cantidad')) }}</td>
                            <td class="py-2 text-right tabular-nums">₡{{ number_format($filas->sum('monto'), 2) }}</td>
                            @if ($conPorCobrar)
                                <td class="py-2 text-right tabular-nums text-amber-700 dark:text-amber-300">
                                    ₡{{ number_format($filas->sum('por_cobrar'), 2) }}
                                </td>
                            @endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
