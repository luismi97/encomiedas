{{-- Reporte contable: ventas e IVA de comprobantes aceptados por Hacienda (ReporteContable). --}}
@php $r = $datos; @endphp
<div class="card space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Reporte contable (ventas e IVA)</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Por fecha de emisión, del {{ $r['desde'] }} al {{ $r['hasta'] }}@if ($r['sede']) · {{ $r['sede'] }}@endif.
                Solo comprobantes <strong>aceptados por Hacienda</strong>; las notas de crédito restan.
            </p>
        </div>
        <a href="{{ route('reportes.contable.pdf', ['from' => $from, 'to' => $to, 'branch_id' => $branchId]) }}" target="_blank" class="btn-secondary">
            <x-icon name="download" class="w-4 h-4" /> Descargar PDF
        </a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-sm text-gray-500 dark:text-gray-400">Ventas netas</div>
            <div class="text-xl font-bold tabular-nums">₡{{ number_format($r['neto']['venta'], 2) }}</div>
        </div>
        <div class="rounded-lg border border-brand-200 dark:border-brand-800 bg-brand-50 dark:bg-brand-900/30 p-3">
            <div class="text-sm text-gray-600 dark:text-gray-300">IVA a declarar</div>
            <div class="text-xl font-bold tabular-nums">₡{{ number_format($r['neto']['iva'], 2) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-sm text-gray-500 dark:text-gray-400">Total</div>
            <div class="text-xl font-bold tabular-nums">₡{{ number_format($r['neto']['total'], 2) }}</div>
        </div>
    </div>

    @if ($r['pendientes'] > 0)
        <p class="text-sm text-amber-700 dark:text-amber-300">
            {{ $r['pendientes'] }} comprobante(s) del período siguen sin respuesta de Hacienda y no están incluidos. Si los aceptan, este reporte cambia.
        </p>
    @endif
    @if ($r['deSandbox'] > 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $r['deSandbox'] }} comprobante(s) de pruebas (sandbox) no se cuentan.
        </p>
    @endif

    <div class="data-table-wrap">
        <table class="w-full text-left">
            <thead>
                <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                    <th class="py-2">Tipo</th><th class="py-2 text-right">Cantidad</th>
                    <th class="py-2 text-right">Venta neta</th><th class="py-2 text-right">IVA</th><th class="py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($r['porTipo'] as $fila)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50">
                        <td class="py-2">{{ $fila['tipo'] }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $fila['cantidad'] }}</td>
                        <td class="py-2 text-right tabular-nums">₡{{ number_format($fila['venta'], 2) }}</td>
                        <td class="py-2 text-right tabular-nums">₡{{ number_format($fila['iva'], 2) }}</td>
                        <td class="py-2 text-right tabular-nums">₡{{ number_format($fila['total'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-500">No hay comprobantes aceptados por Hacienda en el período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($r['porTarifa'])
        <div>
            <h3 class="font-semibold mb-2">Por tarifa de IVA</h3>
            <div class="data-table-wrap">
                <table class="w-full text-left">
                    <tbody>
                        @foreach ($r['porTarifa'] as $fila)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="py-2">{{ $fila['tarifa'] }}</td>
                                <td class="py-2 text-right tabular-nums">Base ₡{{ number_format($fila['venta'], 2) }}</td>
                                <td class="py-2 text-right tabular-nums">IVA ₡{{ number_format($fila['iva'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <form wire:submit="enviarAlContador" class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <h3 class="font-semibold mb-2">Enviar al contador</h3>
        <div class="flex flex-col sm:flex-row gap-3 sm:items-start">
            <div class="flex-1">
                <input type="email" wire:model="correoContador" placeholder="contador@ejemplo.com"
                       class="input @error('correoContador') input-error @enderror">
                @error('correoContador') <p class="error-text">{{ $message }}</p> @enderror
                @error('to') <p class="error-text">{{ $message }}</p> @enderror
            </div>
            <x-action-button type="submit" target="enviarAlContador" variant="primary" loadingText="Enviando...">
                <x-icon name="send" class="w-4 h-4" /> Enviar PDF por correo
            </x-action-button>
        </div>
        <p class="text-xs text-gray-500 mt-1">Va con el PDF adjunto. El correo queda guardado para la próxima vez.</p>
    </form>

    @if ($r['detalle'])
        <details>
            <summary class="cursor-pointer font-semibold">Detalle ({{ count($r['detalle']) }} comprobantes)</summary>
            <div class="data-table-wrap mt-2">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2">Fecha</th><th class="py-2">Tipo</th><th class="py-2">Consecutivo</th><th class="py-2">Receptor</th>
                            <th class="py-2 text-right">Venta neta</th><th class="py-2 text-right">IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($r['detalle'] as $fila)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="py-1.5 whitespace-nowrap">{{ $fila['fecha'] }}</td>
                                <td class="py-1.5">{{ $fila['tipoNombre'] }}</td>
                                <td class="py-1.5">{{ $fila['consecutivo'] }}</td>
                                <td class="py-1.5">{{ $fila['receptor'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">₡{{ number_format($fila['venta'], 2) }}</td>
                                <td class="py-1.5 text-right tabular-nums">₡{{ number_format($fila['iva'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
