@props([
    // Propiedad del componente donde va el id del cliente elegido.
    'model',
    // Propiedad donde vive el término de búsqueda.
    'search',
    'label' => 'Cliente registrado',
    'elegido' => null,
    'resultados' => null,
])

{{-- Buscador de clientes en vez de un <select> con la tabla entera.

     Con 5.000 clientes, dos selects sumaban 10.000 <option> y 1,2 MB de HTML
     que Livewire reenvía en cada interacción. Acá se consulta bajo demanda y se
     traen unos pocos resultados. --}}
<div x-data="{ abierto: false }" @click.outside="abierto = false" class="relative">
    <label class="label">{{ $label }}</label>

    @if ($elegido)
        {{-- Ya hay uno elegido: se muestra y se puede quitar. --}}
        <div class="flex items-center gap-2 rounded-lg border border-brand-300 dark:border-brand-700
                    bg-brand-50 dark:bg-brand-900/20 px-3 py-2">
            <span class="flex-1 text-sm">
                <span class="font-medium">{{ $elegido->name }}</span>
                @if ($elegido->identification)
                    <span class="text-gray-500 dark:text-gray-400">· {{ $elegido->identification }}</span>
                @endif
            </span>
            <button type="button" wire:click="$set('{{ $model }}', null)"
                    class="text-gray-500 hover:text-red-600" title="Quitar">
                <x-icon name="x" class="w-4 h-4" />
            </button>
        </div>
    @else
        <input type="text"
               wire:model.live.debounce.300ms="{{ $search }}"
               @focus="abierto = true"
               placeholder="Buscar por nombre o cédula..."
               autocomplete="off"
               class="input">

        @if ($resultados !== null && $resultados->isNotEmpty())
            <ul x-show="abierto" x-transition
                class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border
                       border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg">
                @foreach ($resultados as $cliente)
                    <li wire:key="{{ $model }}-{{ $cliente->id }}">
                        <button type="button"
                                wire:click="$set('{{ $model }}', {{ $cliente->id }})"
                                @click="abierto = false"
                                class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700">
                            <span class="font-medium">{{ $cliente->name }}</span>
                            @if ($cliente->identification)
                                <span class="text-gray-500 dark:text-gray-400">· {{ $cliente->identification }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif ($resultados !== null)
            <p class="text-xs text-gray-500 mt-1">Ningún cliente coincide.</p>
        @endif
    @endif
</div>
