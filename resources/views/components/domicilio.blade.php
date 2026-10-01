@props(['guia', 'direccion' => true])

{{-- «A domicilio» en las pantallas del sistema, no solo en lo impreso.

     Antes solo lo decían la etiqueta y el recibo: quien armaba el cierre o
     buscaba la guía no se enteraba, y el paquete terminaba esperando en la
     sede a alguien que nunca iba a pasar a retirarlo. --}}
@if ($guia?->esADomicilio())
    <div {{ $attributes->merge(['class' => 'text-sm']) }} data-test="a-domicilio">
        <span class="badge bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200">
            <x-icon name="truck" class="w-3.5 h-3.5 mr-1" /> A domicilio
        </span>
        @if ($direccion && $guia->delivery_address)
            <span class="text-gray-600 dark:text-gray-300">{{ $guia->delivery_address }}</span>
        @endif
    </div>
@endif
