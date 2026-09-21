@props(['posicion' => 'derecha'])
{{--
    Ayuda de un control: el signo de interrogación que explica qué hace el botón
    de al lado.

    Va SIEMPRE fuera del botón que explica, nunca dentro: un <button> anidado en
    otro es HTML inválido, y el navegador dispara el de afuera cuando se toca el
    de adentro —el operario pediría ayuda y estaría anulando una guía—.

    Se abre con el puntero, con el foco del teclado y con un toque en la pantalla,
    porque en el mostrador hay de las tres cosas. El texto va en aria-description
    además de a la vista: quien usa lector de pantalla lo oye al llegar al botón,
    sin tener que abrir nada.
--}}
<span x-data="{ abierto: false }"
      x-on:keydown.escape.window="abierto = false"
      class="relative inline-flex align-middle print:hidden"
      {{ $attributes }}>
    <button type="button"
            x-on:click.stop.prevent="abierto = ! abierto"
            x-on:mouseenter="abierto = true"
            x-on:mouseleave="abierto = false"
            x-on:focus="abierto = true"
            x-on:blur="abierto = false"
            :aria-expanded="abierto ? 'true' : 'false'"
            aria-label="Qué hace este control"
            class="w-4 h-4 shrink-0 rounded-full border border-gray-400 dark:border-gray-500
                   text-[10px] leading-none font-bold text-gray-500 dark:text-gray-400
                   hover:border-brand-500 hover:text-brand-600 dark:hover:text-brand-300
                   focus:outline-none focus:ring-2 focus:ring-brand-500 flex items-center justify-center">
        ?
    </button>

    <span x-show="abierto" x-cloak role="tooltip"
          class="absolute z-50 bottom-full mb-2 w-64 max-w-[70vw] p-3 rounded-lg shadow-lg
                 bg-gray-900 text-gray-50 text-xs font-normal leading-relaxed text-left
                 dark:bg-gray-700
                 {{ $posicion === 'izquierda' ? 'right-0' : 'left-0' }}">
        {{ $slot }}
    </span>
</span>
