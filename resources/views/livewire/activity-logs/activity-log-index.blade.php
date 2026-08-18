<div class="space-y-6">
    <p class="text-gray-500 dark:text-gray-400">
        Bitácora de acciones de los usuarios (principalmente cambios de estado hechos por repartidores).
    </p>

    <div class="card grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="label">Usuario</label>
            <select wire:model.live="userId" class="input">
                <option value="">Todos</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">Desde</label>
            <input type="date" wire:model.live="from" class="input">
        </div>
        <div>
            <label class="label">Hasta</label>
            <input type="date" wire:model.live="to" class="input">
        </div>
    </div>

    <div class="card">
        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Fecha</th>
                        <th class="py-2">Usuario</th>
                        <th class="py-2">Factura</th>
                        <th class="py-2">Descripción</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 text-sm whitespace-nowrap">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                            <td class="py-3">
                                {{ $log->user?->name ?? '—' }}
                                <div class="text-xs text-gray-500">{{ $log->user?->roleLabel() }}</div>
                            </td>
                            <td class="py-3">
                                @if ($log->invoice)
                                    <a href="{{ route('invoices.show', $log->invoice_id) }}" class="text-brand-600 dark:text-brand-300 font-medium">{{ $log->invoice->code }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-3 text-sm">{{ $log->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-500">No hay actividad registrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @forelse ($logs as $log)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold">{{ $log->user?->name ?? '—' }}</div>
                            <div class="text-xs text-gray-500">{{ $log->created_at->format('d/m/Y H:i') }}</div>
                        </div>
                        @if ($log->invoice)
                            <a href="{{ route('invoices.show', $log->invoice_id) }}" class="text-brand-600 dark:text-brand-300 font-medium text-sm">{{ $log->invoice->code }}</a>
                        @endif
                    </div>
                    <div class="mt-2 text-sm">{{ $log->description }}</div>
                </div>
            @empty
                <div class="text-center text-gray-500 py-6">No hay actividad registrada.</div>
            @endforelse
        </div>

        <div class="mt-4">{{ $logs->links() }}</div>
    </div>
</div>
