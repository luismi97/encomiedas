<x-rastreo-layout>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h1 class="text-xl font-semibold mb-1">¿Con cuál empresa envió?</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
            El código <span class="font-mono">{{ $codigo }}</span> existe en más de una empresa.
            Elegí la que aparece en tu recibo. Si escaneás el QR del recibo, entrás directo.
        </p>

        <div class="space-y-2" data-test="opciones-empresa">
            @foreach ($opciones as $opcion)
                <a href="{{ route('rastreo.empresa', ['empresa' => $opcion['slug'], 'code' => $codigo]) }}"
                   class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-brand-500 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition">
                    <span class="font-medium">{{ $opcion['empresa'] }}</span>
                    <span class="text-gray-400" aria-hidden="true">&rsaquo;</span>
                </a>
            @endforeach
        </div>

        <a href="{{ route('rastreo.buscar') }}" class="inline-block mt-4 text-brand-600 dark:text-brand-300 font-medium text-sm">
            Consultar otro código
        </a>
    </div>
</x-rastreo-layout>
