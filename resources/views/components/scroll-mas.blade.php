@props([
    'hayMas' => false,
    'enElTope' => false,
    'visibles' => 0,
    'accion' => 'cargarMas',
    'etiqueta' => 'registros',
])
{{--
    Pie del scroll infinito.

    Carga sola al acercarse al final —con 400px de anticipación, para que la
    tanda llegue antes de que el operario vea el fondo— pero deja el botón a la
    vista: sin él, quien navega con teclado o con el lector de pantalla no tiene
    forma de pedir la siguiente tanda, porque nunca dispara el observador.

    El candado `pidiendo` importa: el observador se dispara en cada cuadro de
    desplazamiento, y sin él salían diez peticiones por gesto.
--}}
<div class="pt-4">
    @if ($enElTope)
        <p class="text-sm text-center text-amber-700 dark:text-amber-300">
            Se están mostrando {{ number_format($visibles) }} {{ $etiqueta }}, que es el máximo por pantalla.
            Acotá el filtro o buscá por código para llegar a lo que falta.
        </p>
    @elseif ($hayMas)
        <div
            x-data="{
                pidiendo: false,
                observador: null,
                pedir() {
                    if (this.pidiendo) return;
                    this.pidiendo = true;
                    $wire.{{ $accion }}().finally(() => { this.pidiendo = false; });
                },
            }"
            x-init="
                observador = new IntersectionObserver(
                    (entradas) => { if (entradas[0].isIntersecting) pedir(); },
                    { rootMargin: '400px' }
                );
                observador.observe($el);
            "
            x-on:destroy="observador?.disconnect()"
            class="flex justify-center"
        >
            <button type="button" wire:click="{{ $accion }}"
                wire:loading.attr="disabled" wire:target="{{ $accion }}"
                class="btn-secondary inline-flex items-center gap-2">
                <svg wire:loading wire:target="{{ $accion }}" class="animate-spin h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span wire:loading.remove wire:target="{{ $accion }}">Cargar más</span>
                <span wire:loading wire:target="{{ $accion }}">Cargando...</span>
            </button>
        </div>
    @elseif ($visibles > 0)
        <p class="text-sm text-center text-gray-500">No hay más {{ $etiqueta }}.</p>
    @endif
</div>
