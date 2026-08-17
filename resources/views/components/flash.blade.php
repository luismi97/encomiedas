{{--
    Livewire actualiza solo el HTML del componente, no el layout. Un mensaje
    flash pintado en el layout no aparece tras un wire:click: se queda esperando
    a la siguiente carga completa y ahi sale fuera de contexto. Por eso el aviso
    se renderiza dentro del componente y se consume con pull() para que no se
    repita despues.
--}}
@php
    $flashSuccess = session()->pull('success');
    $flashError = session()->pull('error');
@endphp

@if ($flashSuccess)
    <div class="mb-4 flex items-start gap-3 p-4 rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200 text-base">
        <x-icon name="check-circle" class="w-5 h-5 mt-0.5" />
        <span>{{ $flashSuccess }}</span>
    </div>
@endif

@if ($flashError)
    <div class="mb-4 flex items-start gap-3 p-4 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200 text-base">
        <x-icon name="warning" class="w-5 h-5 mt-0.5" />
        <span>{{ $flashError }}</span>
    </div>
@endif
