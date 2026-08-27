/**
 * Tonos de confirmación para el escaneo.
 *
 * Sintetizados con Web Audio en vez de archivos de sonido: un .mp3 es un
 * binario más que subir en cada despliegue y una petición más en una bodega con
 * mala señal.
 *
 * API global:
 *   window.Sonidos.ok()      lectura confirmada por el servidor
 *   window.Sonidos.error()   código inválido o rechazado
 *   window.Sonidos.despertar() habilita el audio dentro de un gesto del usuario
 */
(() => {
    'use strict';

    let ctx = null;

    /**
     * Los navegadores móviles solo permiten sonar si el contexto se creó
     * durante un gesto del usuario. Se llama al tocar «escanear».
     */
    function despertar() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            ctx = ctx || new Ctx();
            if (ctx.state === 'suspended') ctx.resume();
        } catch (e) {}
    }

    function tono(frecuencia, duracion, volumen = 0.15, retraso = 0) {
        try {
            despertar();
            if (!ctx) return;

            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            const t0 = ctx.currentTime + retraso;

            osc.type = 'square';
            osc.frequency.value = frecuencia;

            // Rampa exponencial: un corte seco suena a chasquido.
            gain.gain.setValueAtTime(volumen, t0);
            gain.gain.exponentialRampToValueAtTime(0.0001, t0 + duracion);

            osc.connect(gain).connect(ctx.destination);
            osc.start(t0);
            osc.stop(t0 + duracion);
        } catch (e) { /* sin audio: quedan la vibración y el aviso en pantalla */ }
    }

    function vibrar(patron) {
        try { if (navigator.vibrate) navigator.vibrate(patron); } catch (e) {}
    }

    // Agudo y corto: el pitido de caja que todo el mundo reconoce.
    function ok() {
        tono(1760, 0.12);
        vibrar(60);
    }

    // Dos tonos graves: distinto del de éxito incluso sin mirar la pantalla.
    function error() {
        tono(320, 0.18, 0.2);
        tono(240, 0.28, 0.2, 0.2);
        vibrar([80, 60, 80]);
    }

    window.Sonidos = { ok, error, despertar };

    /*
     * El servidor avisa del resultado con este evento. Va acá y no en cada
     * pantalla para que el lector físico y la cámara suenen igual: los dos
     * terminan en la misma confirmación.
     */
    if (!window.__sonidosBound) {
        window.__sonidosBound = true;
        window.addEventListener('scan-resultado', (e) => {
            (e.detail && e.detail.ok) ? ok() : error();
        });
    }
})();
