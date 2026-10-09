{{--
    Agrandar la letra de todo el sistema, para quien lee con dificultad.

    Botón que abre un panel con radios nativos dentro de un fieldset: el
    teclado (Tab, flechas, Esc) y los lectores de pantalla ya saben usarlo,
    sin roles ARIA inventados. Se guarda en este equipo, como el tema; lo
    aplica partials.tamano-letra al cargar cada página.
--}}
@php
    $tamanos = [
        '100'   => 'Normal',
        '112.5' => 'Grande',
        '125'   => 'Más grande',
        '150'   => 'Muy grande',
    ];
@endphp

{{-- Sin «relative» a propósito: el panel se ancla a la barra superior y no al
     botón. Anclado al botón, con la letra grande se metía debajo del menú
     lateral en escritorio y se salía por la izquierda en un celular. --}}
<div class="shrink-0"
     x-data="{
        abierto: false,
        tamano: (() => { try { return localStorage.getItem('tamanoLetra') || '100' } catch (e) { return '100' } })(),
     }"
     x-init="$watch('tamano', (v) => {
        document.documentElement.style.fontSize = v === '100' ? '' : v + '%';
        try { localStorage.setItem('tamanoLetra', v) } catch (e) {}
     })"
     x-on:keydown.escape="if (abierto) { abierto = false; $refs.boton.focus() }"
     x-on:click.outside="abierto = false"
     data-test="tamano-letra">
    <button type="button" x-ref="boton" x-on:click="abierto = !abierto"
            aria-controls="panel-tamano-letra" :aria-expanded="abierto.toString()"
            title="Tamaño de letra"
            class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
        <span aria-hidden="true" class="flex items-end font-semibold leading-none w-5 h-5 justify-center">
            <span class="text-xs">A</span><span class="text-lg">A</span>
        </span>
        <span class="sr-only">Tamaño de letra</span>
    </button>

    <div id="panel-tamano-letra" x-show="abierto" x-cloak x-transition.opacity
         class="absolute right-3 sm:right-6 top-full mt-2 w-64 max-w-[calc(100vw-1.5rem)] z-50 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg p-4">
        <fieldset>
            <legend class="font-semibold mb-2">Tamaño de letra</legend>
            <div class="space-y-1">
                @foreach ($tamanos as $valor => $etiqueta)
                    <label class="flex items-center gap-3 py-1.5 cursor-pointer">
                        <input type="radio" name="tamano-letra" value="{{ $valor }}" x-model="tamano">
                        <span>{{ $etiqueta }} <span class="text-gray-500 dark:text-gray-400 text-sm">({{ $valor }} %)</span></span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            Se guarda en este equipo. El zoom del navegador (Ctrl y +, o ⌘ y + en Mac) también funciona.
        </p>
    </div>
</div>
