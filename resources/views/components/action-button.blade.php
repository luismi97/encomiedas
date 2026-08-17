@props([
    'target' => null,
    'action' => null,
    'confirm' => null,
    'type' => 'button',
    'variant' => 'primary',
    'loadingText' => 'Procesando...',
    'disabled' => false,
])
@php
    // wire:target debe conservar los argumentos: si se recorta a "sendOne", ese
    // target coincide con CUALQUIER sendOne(...) y todas las filas de la lista
    // se ponen en estado de carga a la vez. Livewire 3 compara method + params.
    $target = $target ?? ($action ?? '');
    $classes = match ($variant) {
        'primary' => 'btn-primary',
        'secondary' => 'btn-secondary',
        'danger' => 'btn-danger',
        'success' => 'btn-success',
        'link' => 'text-brand-600 dark:text-brand-300 font-medium disabled:opacity-50',
        'link-danger' => 'text-red-600 font-medium disabled:opacity-50',
        'link-muted' => 'text-amber-600 font-medium disabled:opacity-50',
        default => 'btn-primary',
    };
@endphp
<button
    type="{{ $type }}"
    @if ($action) wire:click="{{ $action }}" @endif
    @if ($confirm) wire:confirm="{{ $confirm }}" @endif
    wire:loading.attr="disabled"
    wire:target="{{ $target }}"
    @if ($disabled) disabled @endif
    {{ $attributes->merge(['class' => $classes . ' inline-flex items-center justify-center gap-2']) }}
>
    <svg wire:loading wire:target="{{ $target }}" class="animate-spin h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
    </svg>
    <span wire:loading.remove wire:target="{{ $target }}">{{ $slot }}</span>
    <span wire:loading wire:target="{{ $target }}">{{ $loadingText }}</span>
</button>
