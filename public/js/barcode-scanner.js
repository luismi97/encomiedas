/**
 * Lector de códigos por cámara.
 *
 * Usa la API nativa BarcodeDetector cuando existe (Chrome/Edge/Android: rápida
 * y sin conexión). En los navegadores que no la traen (Safari/iOS, Firefox)
 * carga ZXing desde un CDN como respaldo. Requiere contexto seguro: HTTPS, o
 * localhost en desarrollo.
 *
 * API global:
 *   window.EncomiendasScanner.open({ onDetected: code => ..., onError: msg => ... })
 *   window.EncomiendasScanner.close()
 *
 * Espera en el DOM, FUERA de todo componente Livewire:
 *   #scanner-overlay  #scanner-video  #scanner-status
 *
 * Va fuera del componente a propósito: el morph de Livewire destruiría el
 * <video> en mitad del escaneo y la cámara quedaría encendida sin dueño.
 */
(() => {
    'use strict';

    // Code 128 es el de la etiqueta del paquete; QR el del recibo del cliente.
    // Los demás van por si entra un bulto con etiqueta de otro courier.
    const FORMATS = ['code_128', 'qr_code', 'code_39', 'ean_13', 'ean_8', 'itf', 'codabar'];
    const ZXING_CDN = 'https://unpkg.com/@zxing/library@0.21.3/umd/index.min.js';

    // Ventana para ignorar la misma lectura repetida: mientras el código sigue
    // frente a la cámara se detecta en cada cuadro.
    const DEDUP_MS = 1500;

    const state = {
        active: false,
        stream: null,       // MediaStream cuando la abre este módulo (ruta nativa)
        detector: null,
        zxingReader: null,  // el respaldo maneja su propio stream
        rafId: null,
        lastCode: null,
        lastAt: 0,
        onDetected: null,
        onError: null,
        origParent: null,
        origNext: null,
    };

    const $ = (id) => document.getElementById(id);
    const els = () => ({
        overlay: $('scanner-overlay'),
        video: $('scanner-video'),
        status: $('scanner-status'),
    });

    /**
     * Monta el overlay al final del <body>.
     *
     * Así escapa del stacking context de la página: si queda dentro de una
     * tarjeta con overflow o z-index propio, la cámara aparece por debajo y los
     * toques caen en lo que hay detrás.
     */
    function mountOnBody(overlay) {
        if (!overlay) return;
        if (overlay.parentElement !== document.body) {
            state.origParent = overlay.parentElement;
            state.origNext = overlay.nextSibling;
            document.body.appendChild(overlay);
        }
        overlay.removeAttribute('inert');
    }

    function restoreOverlay(overlay) {
        if (overlay && state.origParent) {
            state.origParent.insertBefore(overlay, state.origNext || null);
        }
        state.origParent = null;
        state.origNext = null;
    }

    /**
     * Pitido sintetizado con Web Audio.
     *
     * Sin archivo de sonido: es un binario más que subir en cada despliegue y
     * una petición más en una bodega con mala señal.
     */
    let audioCtx = null;
    function beep() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            audioCtx = audioCtx || new Ctx();
            if (audioCtx.state === 'suspended') audioCtx.resume();

            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'square';
            osc.frequency.value = 1760;
            gain.gain.setValueAtTime(0.12, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.14);
            osc.connect(gain).connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.15);
        } catch (e) { /* sin audio: la vibración y el aviso en pantalla alcanzan */ }
    }

    /**
     * El contexto de audio hay que crearlo dentro del gesto del usuario —el
     * toque que abre la cámara— o los navegadores móviles no dejan sonar nada
     * después.
     */
    function unlockAudio() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            audioCtx = audioCtx || new Ctx();
            if (audioCtx.state === 'suspended') audioCtx.resume();
        } catch (e) {}
    }

    function feedbackSuccess() {
        try { if (navigator.vibrate) navigator.vibrate(60); } catch (e) {}
        beep();
    }

    function setStatus(msg) {
        const { status } = els();
        if (status) status.textContent = msg || '';
    }

    function fail(msg) {
        setStatus(msg);
        if (typeof state.onError === 'function') state.onError(msg);
    }

    function emitDetected(rawValue) {
        const code = (rawValue || '').trim();
        if (!code) return;

        const now = Date.now();
        if (code === state.lastCode && (now - state.lastAt) < DEDUP_MS) return;

        state.lastCode = code;
        state.lastAt = now;
        feedbackSuccess();

        if (typeof state.onDetected === 'function') state.onDetected(code);
    }

    const nativeSupported = () => 'BarcodeDetector' in window;

    async function startCameraNative() {
        const { video } = els();
        state.stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
        });
        video.srcObject = state.stream;
        video.setAttribute('playsinline', 'true'); // iOS: no se va a pantalla completa
        await video.play();
    }

    async function runNative() {
        try {
            state.detector = new window.BarcodeDetector({ formats: FORMATS });
        } catch (e) {
            state.detector = new window.BarcodeDetector(); // formato no soportado: todos
        }

        const { video } = els();

        const tick = async () => {
            if (!state.active) return;
            try {
                const codes = await state.detector.detect(video);
                if (codes && codes.length) emitDetected(codes[0].rawValue);
            } catch (e) { /* cuadro no listo: seguir */ }
            state.rafId = requestAnimationFrame(tick);
        };

        state.rafId = requestAnimationFrame(tick);
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            if (window.ZXing) return resolve();
            const s = document.createElement('script');
            s.src = src;
            s.async = true;
            s.onload = resolve;
            s.onerror = () => reject(new Error('cdn'));
            document.head.appendChild(s);
        });
    }

    async function runZxing() {
        setStatus('Cargando lector...');
        await loadScript(ZXING_CDN);

        if (!window.ZXing || !window.ZXing.BrowserMultiFormatReader) throw new Error('zxing');

        const reader = new window.ZXing.BrowserMultiFormatReader();
        state.zxingReader = reader;

        // ZXing abre la cámara y emite en continuo; reset() la cierra.
        await reader.decodeFromConstraints(
            { audio: false, video: { facingMode: { ideal: 'environment' } } },
            els().video,
            (result) => { if (result) emitDetected(result.getText()); }
        );
    }

    async function open(opts = {}) {
        if (state.active) return;

        state.onDetected = opts.onDetected || null;
        state.onError = opts.onError || null;
        state.lastCode = null;
        state.lastAt = 0;

        const { overlay } = els();
        if (overlay) {
            mountOnBody(overlay);
            overlay.classList.remove('hidden');
        }

        state.active = true;
        unlockAudio();
        setStatus('Iniciando cámara...');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            fail('La cámara necesita una conexión segura (HTTPS).');
            return;
        }

        try {
            if (nativeSupported()) {
                await startCameraNative();
                setStatus('Apuntá al código de barras de la etiqueta');
                await runNative();
            } else {
                await runZxing();
                setStatus('Apuntá al código de barras de la etiqueta');
            }
        } catch (e) {
            const name = e && e.name;

            if (name === 'NotAllowedError' || name === 'SecurityError') {
                fail('Permiso de cámara denegado. Habilitalo en el navegador y volvé a intentar.');
            } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                fail('No se encontró una cámara en este dispositivo.');
            } else if (e && (e.message === 'zxing' || e.message === 'cdn')) {
                fail('Este navegador no soporta escaneo por cámara. Usá Chrome, o el lector físico.');
            } else {
                fail('No se pudo iniciar la cámara.');
            }
        }
    }

    function close() {
        state.active = false;

        if (state.rafId) { cancelAnimationFrame(state.rafId); state.rafId = null; }
        if (state.zxingReader) { try { state.zxingReader.reset(); } catch (e) {} state.zxingReader = null; }

        // Apagar las pistas explícitamente: sin esto la luz de la cámara queda
        // encendida aunque el overlay ya no se vea.
        if (state.stream) { state.stream.getTracks().forEach((t) => t.stop()); state.stream = null; }

        const { overlay, video } = els();
        if (video) { try { video.pause(); } catch (e) {} video.srcObject = null; }
        if (overlay) {
            overlay.classList.add('hidden');
            restoreOverlay(overlay);
        }
    }

    // Se reasigna siempre para que, si wire:navigate vuelve a ejecutar el
    // script, gane la versión más reciente y no la primera de la sesión.
    window.EncomiendasScanner = { open, close };

    // Los listeners globales se enlazan una sola vez: si no, se acumula uno por
    // cada navegación y la cámara se cierra varias veces.
    if (!window.__scannerBound) {
        window.__scannerBound = true;
        const closeCurrent = () => { if (window.EncomiendasScanner) window.EncomiendasScanner.close(); };
        document.addEventListener('livewire:navigating', closeCurrent);
        window.addEventListener('pagehide', closeCurrent);
    }
})();
