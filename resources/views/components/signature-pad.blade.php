@props([
    // Propiedad Livewire donde se deja la firma como data URI.
    'model' => 'deliverySignature',
    'alto' => 160,
])

{{-- Pad de firma.

     Se inicializa con Alpine y no con @script: el canvas vive dentro de un @if,
     así que al arrancar el componente todavía no existe en el DOM. Un script de
     inicialización buscaba #firma, recibía null y moría — el pad quedaba mudo y
     no dibujaba nada.

     x-init corre cuando el elemento entra al DOM, que es justo al abrirse el
     formulario de entrega. --}}
<div wire:ignore
     x-data="{
        ctx: null,
        dibujando: false,
        vacio: true,

        init() {
            const lienzo = this.$refs.lienzo;
            this.ctx = lienzo.getContext('2d');
            this.ajustar();

            // El ancho depende del CSS y al abrirse puede medir 0: se reintenta
            // en el siguiente cuadro, cuando ya tiene tamaño real.
            requestAnimationFrame(() => this.ajustar());
            this.alRedimensionar = () => this.ajustar();
            window.addEventListener('resize', this.alRedimensionar);
        },

        destroy() {
            window.removeEventListener('resize', this.alRedimensionar);
        },

        /*
         * Iguala la resolución interna al tamaño en pantalla.
         *
         * Sin esto la firma sale desplazada respecto al dedo. Cambiar .width
         * borra el lienzo, así que solo se toca cuando de verdad cambió y se
         * repone el trazo ya hecho.
         */
        ajustar() {
            const lienzo = this.$refs.lienzo;
            const ancho = Math.round(lienzo.getBoundingClientRect().width);

            if (ancho === 0 || lienzo.width === ancho) return;

            const previo = this.vacio ? null : lienzo.toDataURL('image/png');

            lienzo.width = ancho;
            this.ctx.lineWidth = 2;
            this.ctx.lineJoin = 'round';
            this.ctx.lineCap = 'round';
            this.ctx.strokeStyle = '#111';

            if (previo) {
                const img = new Image();
                img.onload = () => this.ctx.drawImage(img, 0, 0);
                img.src = previo;
            }
        },

        punto(e) {
            const r = this.$refs.lienzo.getBoundingClientRect();
            const t = e.touches ? e.touches[0] : e;
            return { x: t.clientX - r.left, y: t.clientY - r.top };
        },

        empezar(e) {
            e.preventDefault();
            this.dibujando = true;
            const p = this.punto(e);
            this.ctx.beginPath();
            this.ctx.moveTo(p.x, p.y);
            // Un toque sin arrastre también deja marca: firmar un punto vale.
            this.ctx.lineTo(p.x + 0.1, p.y);
            this.ctx.stroke();
            this.vacio = false;
        },

        mover(e) {
            if (! this.dibujando) return;
            e.preventDefault();
            const p = this.punto(e);
            this.ctx.lineTo(p.x, p.y);
            this.ctx.stroke();
        },

        soltar() {
            if (! this.dibujando) return;
            this.dibujando = false;
            // Se sube al soltar y no en cada trazo: una petición por firma.
            $wire.set('{{ $model }}', this.$refs.lienzo.toDataURL('image/png'), false);
        },

        limpiar() {
            const lienzo = this.$refs.lienzo;
            this.ctx.clearRect(0, 0, lienzo.width, lienzo.height);
            this.vacio = true;
            $wire.set('{{ $model }}', '', false);
        },
     }">
    <label class="label">Firma</label>

    <div class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white overflow-hidden">
        <canvas x-ref="lienzo"
                class="w-full touch-none"
                height="{{ $alto }}"
                style="display:block; cursor:crosshair;"
                @mousedown="empezar" @mousemove="mover" @mouseup="soltar" @mouseleave="soltar"
                @touchstart="empezar" @touchmove="mover" @touchend="soltar"></canvas>
    </div>

    <div class="flex items-center gap-3 mt-2">
        <button type="button" @click="limpiar()" class="btn-secondary !py-1.5 !px-3 text-sm">
            Borrar firma
        </button>
        <span class="text-xs text-gray-500" x-show="vacio">Firmá con el dedo o el mouse</span>
        <span class="text-xs text-green-600 dark:text-green-400" x-show="! vacio" x-cloak>Firma capturada</span>
    </div>
</div>
