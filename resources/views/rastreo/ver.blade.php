<x-rastreo-layout>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ $guia->code }}</div>
            <div class="flex flex-wrap items-center gap-3 mt-1">
                <h1 class="text-2xl font-semibold">{{ $guia->statusLabel() }}</h1>
                <span class="badge {{ \App\Models\Invoice::STATUS_BADGE_CLASSES[$guia->status] ?? '' }}">
                    {{ $guia->statusLabel() }}
                </span>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div>
                    <span class="text-gray-500 dark:text-gray-400 block">Origen</span>
                    {{ $guia->pickupBranch?->name }}
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400 block">Destino</span>
                    {{ $guia->deliveryBranch?->name }}
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400 block">Destinatario</span>
                    {{ $receptor }}
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400 block">Paquetes</span>
                    {{ $guia->items()->count() }}
                </div>
            </div>
        </div>

        @if ($porVencer)
            <div class="p-4 bg-amber-50 dark:bg-amber-900/30 border-b border-amber-200 dark:border-amber-800 flex items-start gap-3">
                <x-icon name="warning" class="w-5 h-5 mt-0.5 text-amber-700 dark:text-amber-300 shrink-0" />
                <div class="text-sm text-amber-800 dark:text-amber-200">
                    <strong>Esta encomienda está próxima a desecho.</strong>
                    @if ($fechaLimite)
                        Debe retirarse antes del <strong>{{ $fechaLimite->format('d/m/Y') }}</strong>.
                    @endif
                </div>
            </div>
        @endif

        @if (! $sedeAbierta && $proximaApertura)
            <div class="p-4 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700 flex items-start gap-3">
                <x-icon name="clock" class="w-5 h-5 mt-0.5 text-gray-400 shrink-0" />
                <div class="text-sm text-gray-600 dark:text-gray-300">
                    La sucursal está cerrada en este momento.
                    Vuelve a abrir el <strong>{{ $proximaApertura->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm') }}</strong>.
                </div>
            </div>
        @endif

        <div class="p-6">
            <h2 class="font-semibold mb-4">Recorrido</h2>
            <ol class="relative border-l border-gray-200 dark:border-gray-700 ml-2 space-y-5">
                @foreach ($recorrido as $paso)
                    <li class="ml-5">
                        <span class="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full
                            {{ $loop->last ? 'bg-brand-600' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                        <div class="font-medium">{{ $paso->toLabel() }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $paso->happened_at?->format('d/m/Y H:i') }}
                            @if ($paso->branch) · {{ $paso->branch->name }} @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    <div class="mt-4 text-center">
        <a href="{{ route('rastreo.buscar') }}" class="text-brand-600 dark:text-brand-300 font-medium text-sm">
            Consultar otra encomienda
        </a>
    </div>
</x-rastreo-layout>
