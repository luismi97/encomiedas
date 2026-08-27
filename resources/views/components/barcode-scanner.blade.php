{{-- Escáner por cámara, reutilizable.

     Se incluye UNA vez por página y FUERA de cualquier componente Livewire: si
     quedara dentro, el morph de Livewire destruiría el <video> en mitad del
     escaneo y la cámara quedaría encendida sin dueño.

     Expone window.EncomiendasScanner.open({ onDetected, onError }) y .close().
     Necesita contexto seguro: HTTPS, o localhost en desarrollo.

     ---------------------------------------------------------------------
     POR QUÉ ESTA PANTALLA NO USA UTILIDADES DE TAILWIND

     Acá no se recompila Tailwind al desplegar, así que el bundle es el que
     dejó el último `npm run build`. Una utilidad que ya se usaba en otra
     pantalla está; una que se estrena acá, NO — y no falla haciendo ruido:
     falla dejando el elemento sin ese estilo.

     Concreto: `object-contain` no está en el bundle actual. Cambiar el
     `object-cover` del <video> por esa clase habría dejado el `object-fit` en
     su valor por defecto (`fill`), o sea el video ESTIRADO y deformado, sin
     ningún aviso.

     Por eso todo lo de esta pantalla vive en el <style> de abajo, con nombres
     propios `scan-*`. Al tocar esto: no agregar clases de Tailwind nuevas,
     agregar reglas acá.
     --------------------------------------------------------------------- --}}
<style>
    .scan-overlay {
        position: fixed; inset: 0; z-index: 1000;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 1rem;
        background: rgba(0, 0, 0, .9);
        /* Contenido más alto que la pantalla (cámara vertical en un teléfono
           chico): se scrollea en vez de quedar cortado. Con justify-center a
           secas, lo que sobra se pierde arriba y abajo sin forma de alcanzarlo. */
        overflow-y: auto;
    }
    /* Propia, para no depender de que `.hidden` siga en el bundle. */
    .scan-overlay.hidden { display: none; }

    .scan-panel { width: 100%; max-width: 28rem; margin: auto; }

    .scan-frame {
        position: relative; overflow: hidden;
        border-radius: .75rem; background: #000;
        /* Proporción de arranque; el JS la reemplaza por la del stream real
           (fitFrameToVideo). El tope de alto es para que una cámara vertical no
           se coma la pantalla y empuje el botón de cerrar afuera. El ancho lo
           topa el JS en la misma proporción — si solo se topara el alto, la
           caja seguiría midiendo el 100% del ancho, la proporción se rompería y
           el video saldría con bandas negras a los lados, o sea más chico de lo
           que cabe. */
        aspect-ratio: 16 / 9;
        max-height: 70vh; max-height: 70dvh;
        margin: 0 auto; /* centrado cuando queda más angosto que el panel */
    }
    /* contain y no cover: recortar el cuadro es esconderle al operario parte de
       lo que la cámara sí está viendo, y él apunta con lo que ve. */
    .scan-video { display: block; width: 100%; height: 100%; object-fit: contain; }

    .scan-guide-wrap {
        position: absolute; inset: 0; pointer-events: none;
        display: flex; align-items: center; justify-content: center;
    }
    .scan-guide {
        width: 80%; height: 50%;
        border: 2px solid rgba(255, 255, 255, .8); border-radius: .5rem;
        box-shadow: 0 0 0 9999px rgba(0, 0, 0, .35);
    }

    .scan-status {
        margin-top: .75rem; text-align: center;
        font-size: .875rem; color: rgba(255, 255, 255, .9);
    }

    .scan-btn {
        display: block; width: 100%; margin-top: 1rem; padding: .75rem;
        border: 0; border-radius: .5rem; cursor: pointer;
        font-size: .875rem; font-weight: 500; font-family: inherit;
        background: rgba(255, 255, 255, .1); color: #fff;
    }
    .scan-btn:hover { background: rgba(255, 255, 255, .2); }
</style>

<div id="scanner-overlay" class="scan-overlay hidden">
    <div class="scan-panel">
        <div id="scanner-frame" class="scan-frame">
            <video id="scanner-video" class="scan-video" muted playsinline></video>

            {{-- Marco guía: le dice al usuario dónde poner el código, y el JS lo
                 mide para saber cuál de los códigos a la vista es el apuntado. --}}
            <div class="scan-guide-wrap">
                <div id="scanner-guide" class="scan-guide"></div>
            </div>
        </div>

        <p id="scanner-status" class="scan-status"></p>

        <button type="button" onclick="window.EncomiendasScanner && window.EncomiendasScanner.close()"
                class="scan-btn">
            Cerrar cámara
        </button>
    </div>
</div>

<script src="{{ asset('js/barcode-scanner.js') }}?v={{ @filemtime(public_path('js/barcode-scanner.js')) }}"></script>
