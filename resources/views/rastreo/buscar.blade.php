<x-rastreo-layout>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h1 class="text-xl font-semibold mb-1">Consultar una encomienda</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
            Digite el código de guía que aparece en su recibo, por ejemplo <span class="font-mono">SJ-LIM-00005</span>.
        </p>

        @if ($error)
            <div class="mb-4 flex items-start gap-3 p-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 text-sm">
                <x-icon name="warning" class="w-5 h-5 mt-0.5 shrink-0" />
                <span>{{ $error }}</span>
            </div>
        @endif

        <form method="GET" action="{{ route('rastreo.buscar') }}" class="flex flex-col sm:flex-row gap-2">
            <input type="text" name="codigo" value="{{ $codigo }}" required autofocus
                   placeholder="SJ-LIM-00005"
                   class="input flex-1 font-mono uppercase" autocomplete="off">
            <button type="submit" class="btn-primary">Consultar</button>
        </form>
    </div>
</x-rastreo-layout>
