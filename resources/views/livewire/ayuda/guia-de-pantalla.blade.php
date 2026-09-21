{{--
    El recorrido guiado.

    La tarjeta va fija abajo y no flotando junto al botón: posicionar un globo
    contra un elemento arbitrario se rompe con el teclado abierto, con el zoom
    del navegador y en pantalla de celular, y un paso que queda fuera de la
    vista es peor que no tenerlo. Lo que sí se mueve es el resaltado: al elemento
    anclado se le pone un anillo y se lo trae a la vista.

    Si el paso no tiene ancla —o el elemento no está en pantalla— la tarjeta
    igual se muestra: un paso que se salta solo deja la explicación a medias.
--}}
<div>
    @if ($guia && $actual)
        <div x-data="{
                abierta: @entangle('abierta'),
                ancla: @js($actual['ancla'] ?? null),
                resaltado: null,

                resaltar() {
                    this.limpiar();

                    if (! this.abierta || ! this.ancla) return;

                    const destino = document.querySelector(`[data-ayuda='${this.ancla}']`);
                    if (! destino) return;

                    this.resaltado = destino;
                    destino.classList.add('ring-4', 'ring-brand-400', 'rounded-lg');
                    destino.scrollIntoView({ block: 'center', behavior: 'smooth' });
                },

                limpiar() {
                    this.resaltado?.classList.remove('ring-4', 'ring-brand-400', 'rounded-lg');
                    this.resaltado = null;
                },
            }"
            x-init="$nextTick(() => resaltar())"
            x-effect="abierta; ancla; $nextTick(() => resaltar())"
            x-on:destroy="limpiar()"
            x-on:keydown.escape.window="if (abierta) $wire.descartar()">

            <div x-show="abierta" x-cloak
                 class="fixed inset-x-0 bottom-0 z-50 p-4 sm:p-6 print:hidden"
                 role="dialog" aria-modal="false" aria-labelledby="guia-titulo">
                <div class="mx-auto max-w-lg rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700
                            bg-white dark:bg-gray-800 p-5 space-y-3">

                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-brand-600 dark:text-brand-300 font-semibold">
                                {{ $guia['titulo'] }} · paso {{ $paso + 1 }} de {{ $total }}
                            </p>
                            <h2 id="guia-titulo" class="text-lg font-semibold mt-0.5">{{ $actual['titulo'] }}</h2>
                        </div>
                        <button type="button" wire:click="descartar"
                                class="opacity-60 hover:opacity-100 shrink-0" aria-label="Cerrar la guía">
                            <x-icon name="x" class="w-5 h-5" />
                        </button>
                    </div>

                    <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">{{ $actual['texto'] }}</p>

                    <div class="flex items-center gap-1.5 pt-1" aria-hidden="true">
                        @for ($i = 0; $i < $total; $i++)
                            <span class="h-1.5 rounded-full transition-all
                                {{ $i === $paso ? 'w-6 bg-brand-500' : 'w-1.5 bg-gray-300 dark:bg-gray-600' }}"></span>
                        @endfor
                    </div>

                    <div class="flex items-center justify-between gap-3 pt-2">
                        <button type="button" wire:click="descartar"
                                class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                            No mostrar más
                        </button>

                        <div class="flex items-center gap-2">
                            @if ($paso > 0)
                                <button type="button" wire:click="anterior" class="btn-secondary !py-2 !px-3 text-sm">
                                    Atrás
                                </button>
                            @endif
                            <button type="button" wire:click="siguiente" class="btn-primary !py-2 !px-4 text-sm">
                                {{ $esElUltimo ? 'Entendido' : 'Siguiente' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
