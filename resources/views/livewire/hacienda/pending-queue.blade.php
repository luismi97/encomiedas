<div class="space-y-6">
    <x-flash />

    <p class="text-gray-500 dark:text-gray-400">
        Cuando una encomienda se marca como <strong>entregada</strong>, su comprobante se reserva aquí
        y espera a que un administrador decida enviarlo a Hacienda (individual o en bloque).
    </p>

    <div class="flex flex-wrap gap-2">
        @foreach (['pending' => 'Pendientes / con error', 'sent' => 'Enviados (procesando)', 'accepted' => 'Aceptados', 'rejected' => 'Rechazados'] as $value => $label)
            <button wire:click="$set('tab', '{{ $value }}')"
                class="px-4 py-2 rounded-lg text-sm font-semibold {{ $tab === $value ? 'bg-brand-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'pending')
        <div class="flex items-center justify-between flex-wrap gap-3">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="selectAll" class="rounded w-5 h-5">
                Seleccionar todos en esta página
            </label>
            <x-action-button action="sendSelected" variant="primary" loadingText="Enviando..." :disabled="empty($selected)">
                <x-icon name="send" class="w-4 h-4" /> Enviar seleccionadas a Hacienda ({{ count($selected) }})
            </x-action-button>
        </div>
    @endif

    @if ($rejection)
        <div class="card border-red-200 dark:border-red-800">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <h3 class="font-semibold">Motivo del rechazo</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $rejection->invoice?->code }} · consecutivo {{ $rejection->consecutivo }}
                    </p>
                </div>
                <button type="button" wire:click="closeRejection" class="text-gray-400 hover:text-gray-600" aria-label="Cerrar">
                    <x-icon name="x" class="w-5 h-5" />
                </button>
            </div>

            <x-rejection-detail :electronic-invoice="$rejection" />

            <div class="mt-4">
                <x-action-button action="retry({{ $rejection->id }})" variant="primary" loadingText="Reintentando...">
                    <x-icon name="undo" class="w-4 h-4" /> Corregir y reintentar
                </x-action-button>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        @if ($tab === 'pending')
                            <th class="py-2 w-10"></th>
                        @endif
                        <th class="py-2">Factura</th>
                        <th class="py-2">Tipo</th>
                        <th class="py-2">Clave</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $ei)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50 align-top">
                            @if ($tab === 'pending')
                                <td class="py-3"><input type="checkbox" wire:model.live="selected" value="{{ $ei->id }}" class="rounded w-5 h-5"></td>
                            @endif
                            <td class="py-3">
                                <a href="{{ route('invoices.show', $ei->invoice_id) }}" class="font-medium text-brand-600 dark:text-brand-300">
                                    {{ $ei->invoice?->code }}
                                </a>
                            </td>
                            <td class="py-3">{{ $ei->typeLabel() }}</td>
                            <td class="py-3 text-xs break-all max-w-[220px]">{{ $ei->clave }}</td>
                            <td class="py-3">
                                <span class="text-sm">{{ $ei->statusLabel() }}</span>
                                @if ($ei->wasRejected())
                                    <div class="text-xs text-red-600 dark:text-red-400 max-w-xs mt-0.5">
                                        {{ $ei->rejectionSummary() }}
                                    </div>
                                    <x-action-button action="showRejection({{ $ei->id }})" variant="link"
                                        class="!text-xs !font-medium mt-1">Ver motivo</x-action-button>
                                @elseif ($ei->error_message)
                                    <div class="text-xs text-red-600 dark:text-red-400 max-w-xs mt-0.5">{{ $ei->error_message }}</div>
                                @endif
                            </td>
                            <td class="py-3 text-right space-x-3 whitespace-nowrap">
                                @if (in_array($ei->status, ['pending', 'error']))
                                    <x-action-button action="sendOne({{ $ei->id }})" variant="link">Enviar</x-action-button>
                                @endif
                                @if ($ei->status === 'rejected')
                                    <x-action-button action="retry({{ $ei->id }})" variant="link-muted">Reintentar</x-action-button>
                                @endif
                                @if ($ei->pdf_path)
                                    <a href="{{ route('electronic-invoices.pdf', $ei) }}" target="_blank" class="text-gray-500">PDF</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No hay comprobantes en esta categoría.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @forelse ($items as $ei)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        @if ($tab === 'pending')
                            <input type="checkbox" wire:model.live="selected" value="{{ $ei->id }}" class="rounded w-5 h-5 mt-1">
                        @endif
                        <div class="flex-1">
                            <a href="{{ route('invoices.show', $ei->invoice_id) }}" class="font-semibold text-brand-600 dark:text-brand-300">
                                {{ $ei->invoice?->code }}
                            </a>
                            <div class="text-sm text-gray-500">{{ $ei->typeLabel() }} · {{ $ei->statusLabel() }}</div>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500 break-all">{{ $ei->clave }}</div>
                    @if ($ei->wasRejected())
                        <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $ei->rejectionSummary() }}</div>
                        <x-action-button action="showRejection({{ $ei->id }})" variant="link"
                            class="!text-xs !font-medium mt-1">Ver motivo</x-action-button>
                    @elseif ($ei->error_message)
                        <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $ei->error_message }}</div>
                    @endif
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex flex-wrap gap-4">
                        @if (in_array($ei->status, ['pending', 'error']))
                            <x-action-button action="sendOne({{ $ei->id }})" variant="link">Enviar</x-action-button>
                        @endif
                        @if ($ei->status === 'rejected')
                            <x-action-button action="retry({{ $ei->id }})" variant="link-muted">Reintentar</x-action-button>
                        @endif
                        @if ($ei->pdf_path)
                            <a href="{{ route('electronic-invoices.pdf', $ei) }}" target="_blank" class="text-gray-500">PDF</a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-center text-gray-500 py-6">No hay comprobantes en esta categoría.</div>
            @endforelse
        </div>

        <div class="mt-4">{{ $items->links() }}</div>
    </div>
</div>
