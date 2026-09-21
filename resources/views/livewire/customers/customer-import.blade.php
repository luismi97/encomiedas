<div class="max-w-4xl space-y-6">
    @if ($feedback)
        <div class="flex items-start gap-3 p-4 rounded-lg border text-base
            {{ $feedbackType === 'error'
                ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200'
                : 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200' }}">
            <x-icon name="{{ $feedbackType === 'error' ? 'warning' : 'check-circle' }}" class="w-5 h-5 mt-0.5" />
            <span class="flex-1">{{ $feedback }}</span>
            <button type="button" wire:click="dismissFeedback" class="opacity-60 hover:opacity-100" aria-label="Cerrar aviso">
                <x-icon name="x" class="w-4 h-4" />
            </button>
        </div>
    @endif

    <div class="card space-y-4">
        <div class="flex items-start justify-between flex-wrap gap-3">
            <div>
                <h2 class="text-lg font-semibold">1 · La plantilla</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-xl">
                    Descargala, pegá tus clientes debajo del encabezado y volvé acá. Trae dos
                    ejemplos —uno de contado y uno de crédito— porque son los que se comportan distinto.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('customers.plantilla') }}" class="btn-secondary inline-flex items-center gap-2"
                   data-ayuda="importar-plantilla">
                    <x-icon name="download" class="w-4 h-4" /> Descargar plantilla
                </a>
                <x-ayuda posicion="izquierda">Un CSV con los encabezados que el sistema entiende y dos filas de ejemplo. Abrilo con Excel, reemplazá los ejemplos por tus clientes y guardalo como CSV.</x-ayuda>
            </div>
        </div>

        <details class="text-sm">
            <summary class="cursor-pointer text-brand-600 dark:text-brand-300 font-medium">Qué significa cada columna</summary>
            <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                @foreach ($columnas as $clave => $explicacion)
                    <div class="flex gap-2">
                        <dt class="font-mono text-xs text-gray-600 dark:text-gray-300 shrink-0">{{ $clave }}</dt>
                        <dd class="text-xs text-gray-500">{{ $explicacion }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="text-xs text-gray-500 mt-3">
                Solo <span class="font-mono">nombre</span> es obligatorio. El separador puede ser coma o punto y coma,
                la identificación admite guiones y la sucursal se puede escribir con el nombre o con el prefijo.
            </p>
        </details>
    </div>

    <div class="card space-y-4">
        <h2 class="text-lg font-semibold">2 · El archivo</h2>

        <div>
            <label class="label">Archivo CSV</label>
            <input type="file" wire:model="archivo" accept=".csv,text/csv" class="input"
                   data-ayuda="importar-archivo">
            @error('archivo') <p class="error-text">{{ $message }}</p> @enderror
            <p class="text-xs text-gray-500 mt-1" wire:loading wire:target="archivo">Subiendo el archivo...</p>
        </div>

        <div class="flex items-center gap-2">
            <x-action-button action="analizar" variant="secondary" loadingText="Revisando..."
                data-ayuda="importar-revisar">
                <x-icon name="clipboard-list" class="w-4 h-4" /> Revisar el archivo
            </x-action-button>
            <x-ayuda>Lee el archivo y muestra qué pasaría con cada fila. Todavía no guarda nada: es el paso para descubrir los errores antes y no después.</x-ayuda>
        </div>
    </div>

    @if ($erroresDelArchivo)
        <div class="card border-red-200 dark:border-red-800">
            <h3 class="font-semibold text-red-700 dark:text-red-300">El archivo tiene un problema</h3>
            <ul class="mt-2 space-y-1 text-sm text-red-700 dark:text-red-300 list-disc list-inside">
                @foreach ($erroresDelArchivo as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($analizado && $filas)
        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">3 · Qué va a pasar</h2>

            <div class="grid grid-cols-3 gap-3 text-center">
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                    <div class="text-2xl font-semibold tabular-nums" data-test="total-importables">{{ $importables }}</div>
                    <div class="text-xs text-gray-500">se importan</div>
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                    <div class="text-2xl font-semibold tabular-nums text-amber-600 dark:text-amber-300" data-test="total-existentes">{{ $yaExistentes }}</div>
                    <div class="text-xs text-gray-500">ya registrados</div>
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                    <div class="text-2xl font-semibold tabular-nums text-red-600 dark:text-red-300" data-test="total-problemas">{{ $conProblemas }}</div>
                    <div class="text-xs text-gray-500">con problemas</div>
                </div>
            </div>

            @if ($yaExistentes > 0)
                <label class="inline-flex items-start gap-2">
                    <input type="checkbox" wire:model="actualizarExistentes" class="checkbox mt-0.5">
                    <span class="text-sm text-gray-700 dark:text-gray-300">
                        <span class="font-medium">Actualizar los que ya están registrados</span>
                        <span class="block text-xs text-gray-500">
                            Sin marcar, los repetidos se dejan como están y no se tocan.
                        </span>
                    </span>
                </label>
            @endif

            <div class="data-table-wrap max-h-96 overflow-y-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-white dark:bg-gray-800">
                        <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2">Fila</th>
                            <th class="py-2">Nombre</th>
                            <th class="py-2">Qué pasa</th>
                        </tr>
                    </thead>
                    <tbody data-test="filas-del-archivo">
                        @foreach ($filas as $fila)
                            <tr wire:key="fila-{{ $fila['numero'] }}" class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="py-2 tabular-nums text-gray-500">{{ $fila['numero'] }}</td>
                                <td class="py-2">{{ $fila['nombre'] ?: '—' }}</td>
                                <td class="py-2">
                                    @if (! $fila['importable'])
                                        <span class="badge bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200">No entra</span>
                                        <span class="block text-xs text-red-600 dark:text-red-300 mt-1">
                                            {{ implode(' ', $fila['problemas']) }}
                                        </span>
                                    @elseif ($fila['existente'])
                                        <span class="badge bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                                            {{ $actualizarExistentes ? 'Se actualiza' : 'Ya existe: se omite' }}
                                        </span>
                                    @else
                                        <span class="badge bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200">Se crea</span>
                                    @endif
                                    @if ($fila['avisos'])
                                        <span class="block text-xs text-amber-600 dark:text-amber-300 mt-1">
                                            {{ implode(' ', $fila['avisos']) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-2 pt-2">
                <x-action-button action="importar" variant="primary" loadingText="Importando..."
                    :disabled="$importables === 0"
                    confirm="¿Importar {{ $importables }} cliente(s)? Esto sí escribe en la base."
                    data-ayuda="importar-confirmar">
                    <x-icon name="upload" class="w-4 h-4" /> Importar {{ $importables }} cliente(s)
                </x-action-button>
                <x-ayuda>Ahora sí guarda. Las filas marcadas «No entra» se quedan afuera: corregilas en el archivo y volvé a subirlo.</x-ayuda>
            </div>
        </div>
    @endif

    @if ($resumen)
        <div class="card" data-test="resumen-import">
            <h2 class="text-lg font-semibold">Resultado</h2>
            <ul class="mt-2 text-sm space-y-1">
                <li><strong class="tabular-nums">{{ $resumen['creados'] }}</strong> cliente(s) creado(s)</li>
                <li><strong class="tabular-nums">{{ $resumen['actualizados'] }}</strong> actualizado(s)</li>
                <li><strong class="tabular-nums">{{ $resumen['omitidos'] }}</strong> sin importar</li>
            </ul>
            <a href="{{ route('customers.index') }}" class="btn-secondary mt-4 inline-flex items-center gap-2">
                <x-icon name="users" class="w-4 h-4" /> Ver los clientes
            </a>
        </div>
    @endif
</div>
