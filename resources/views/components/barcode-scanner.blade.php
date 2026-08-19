{{-- Escáner por cámara, reutilizable.

     Se incluye UNA vez por página y FUERA de cualquier componente Livewire: si
     quedara dentro, el morph de Livewire destruiría el <video> en mitad del
     escaneo y la cámara quedaría encendida sin dueño.

     Expone window.EncomiendasScanner.open({ onDetected, onError }) y .close().
     Necesita contexto seguro: HTTPS, o localhost en desarrollo. --}}
<div id="scanner-overlay"
     class="hidden fixed inset-0 z-[1000] bg-black/90 flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="relative rounded-xl overflow-hidden bg-black aspect-[3/4] sm:aspect-video">
            <video id="scanner-video" class="w-full h-full object-cover" muted playsinline></video>

            {{-- Marco guía: le dice al usuario dónde poner el código. --}}
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div class="w-4/5 h-1/3 border-2 border-white/80 rounded-lg shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]"></div>
            </div>
        </div>

        <p id="scanner-status" class="mt-3 text-center text-sm text-white/90"></p>

        <button type="button" onclick="window.EncomiendasScanner && window.EncomiendasScanner.close()"
                class="mt-4 w-full rounded-lg bg-white/10 hover:bg-white/20 text-white py-3 font-medium">
            Cerrar cámara
        </button>
    </div>
</div>

<script src="{{ asset('js/barcode-scanner.js') }}?v={{ @filemtime(public_path('js/barcode-scanner.js')) }}"></script>
