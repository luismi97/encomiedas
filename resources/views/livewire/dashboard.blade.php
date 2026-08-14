<div class="space-y-6">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card">
            <div class="text-sm text-gray-500 dark:text-gray-400">Hoy</div>
            <div class="text-3xl font-bold mt-1">{{ $todayCount }}</div>
        </div>
        <div class="card">
            <div class="text-sm text-gray-500 dark:text-gray-400">Pendientes</div>
            <div class="text-3xl font-bold mt-1 text-yellow-600">{{ $pendingCount }}</div>
        </div>
        <div class="card">
            <div class="text-sm text-gray-500 dark:text-gray-400">En camino</div>
            <div class="text-3xl font-bold mt-1 text-blue-600">{{ $inTransitCount }}</div>
        </div>
        <div class="card">
            <div class="text-sm text-gray-500 dark:text-gray-400">Entregadas hoy</div>
            <div class="text-3xl font-bold mt-1 text-green-600">{{ $deliveredCount }}</div>
        </div>
    </div>

    @if (auth()->user()->isAdmin())
        <a href="{{ route('hacienda.pending') }}" class="card flex items-center justify-between hover:shadow-md transition">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Pendientes de envío a Hacienda</div>
                <div class="text-2xl font-bold mt-1">{{ $haciendaPending }}</div>
            </div>
            <span class="text-3xl">🧾</span>
        </a>
    @endif

    <div class="flex flex-wrap gap-3">
        @if (auth()->user()->isAdmin())
            <a href="{{ route('invoices.create') }}" class="btn-primary">➕ Nueva factura</a>
        @endif
        <a href="{{ route('invoices.index') }}" class="btn-secondary">📋 Ver todas las facturas</a>
    </div>

    <div class="card">
        <h2 class="text-lg font-semibold mb-4">Últimas facturas</h2>
        <div class="overflow-x-auto -mx-4 sm:mx-0">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 px-4 sm:px-0">Código</th>
                        <th class="py-2 px-4">Receptor</th>
                        <th class="py-2 px-4">Ruta</th>
                        <th class="py-2 px-4">Estado</th>
                        <th class="py-2 px-4"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recent as $invoice)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="py-3 px-4 sm:px-0 font-medium">{{ $invoice->code }}</td>
                            <td class="py-3 px-4">{{ $invoice->recipient_name }}</td>
                            <td class="py-3 px-4 text-sm">{{ $invoice->pickupBranch->name }} → {{ $invoice->deliveryBranch->name }}</td>
                            <td class="py-3 px-4">
                                <span class="badge {{ $invoice->statusBadgeClasses() }}">
                                    {{ $invoice->statusLabel() }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <a href="{{ route('invoices.show', $invoice) }}" class="text-brand-600 dark:text-brand-300 font-medium">Ver →</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">Sin facturas todavía.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
