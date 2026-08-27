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
 *   #scanner-guide (marco guía)  #scanner-frame (caja del video)
 *
 * Va fuera del componente a propósito: el morph de Livewire destruiría el
 * <video> en mitad del escaneo y la cámara quedaría encendida sin dueño.
 *
 * --------------------------------------------------------------------------
 * POR QUÉ NO ENTREGA LA PRIMERA LECTURA QUE ENCUENTRA
 *
 * Un decodificador puede devolver un código equivocado a partir de un cuadro
 * borroso, y lo devuelve con la misma confianza que uno bueno. Acá ese valor
 * mueve el estado de una guía: recibir el bulto que no era no se deshace
 * solo. De ahí las cuatro defensas de abajo; ninguna es cosmética.
 *
 * La quinta (el cuadro girado) ataca lo contrario: que NO lea nada estando el
 * código a la vista.
 * --------------------------------------------------------------------------
 */
(() => {
    'use strict';

    // 1) SIMBOLOGÍAS.
    //
    // Code 128 es el de la etiqueta del paquete; QR el del recibo del cliente.
    // Los demás van por si entra un bulto con etiqueta de otro courier.
    //
    // ITF y Codabar se conservan —un ITF-14 en una caja de embalaje es real en
    // paquetería— pero NO llevan dígito verificador: el decodificador no tiene
    // cómo saber si leyó mal, y medio símbolo leído es un número válido y
    // distinto. Por eso a esos tres se les exige una confirmación más (abajo)
    // en vez de sacarlos.
    const FORMATS = ['code_128', 'qr_code', 'code_39', 'ean_13', 'ean_8', 'itf', 'codabar'];

    // 2) CONFIRMACION. Cuantas lecturas iguales seguidas hacen falta para dar
    // el codigo por bueno. Una lectura falsa casi nunca se repite identica en
    // el frame siguiente; una buena si. Cuesta ~60 ms (dos frames) y es
    // imperceptible al lado de equivocarse.
    const CONFIRM_DEFAULT = 2;
    const CONFIRM_UNCHECKED = 3;  // formatos sin dígito verificador (code_39, itf, codabar)
    const UNCHECKED_FORMATS = ['code_39', 'itf', 'codabar'];
    const CONFIRM_WINDOW_MS = 1500; // si tardan mas en repetirse, se reinicia el conteo

    const ZXING_CDN = 'https://unpkg.com/@zxing/library@0.21.3/umd/index.min.js';
    // Con la cámara abierta apuntando a una etiqueta, el lector la reconoce en
    // CADA cuadro. Para que no se re-dispare sola, el mismo código solo vuelve
    // a contar si antes dejó de verse este tiempo — o sea, si salió del cuadro
    // y volvió. Una ventana fija no alcanzaba: sostener el bulto un segundo de
    // más mandaba la guía dos veces, y la segunda contesta "ya fue recibida",
    // que pisa en rojo el "recibida" que el operario acababa de leer.
    const REARM_MS = 1200;
    // Cuánto se recuerda un código ya entregado. Solo acota la memoria: pasado
    // este tiempo sin verse, se olvida y volvería a entrar como nuevo.
    const SEEN_TTL_MS = 60000;
    // La mira se agranda un 10%: el cajero apunta, no encuadra al pixel. No mas,
    // porque el recuadro ya es w-4/5 h-1/2 — con un 25% de holgura pasaba del
    // ancho del frame y dejaba de desempatar nada en horizontal.
    const ROI_SLACK = 1.1;
    const ROTATE_AFTER_MS = 900; // sin decodificar nada en este tiempo, probar tambien girado
    const SCAN_PROMPT = 'Apuntá al código de barras de la etiqueta';

    const state = {
        active: false,
        stream: null,       // MediaStream cuando la camara la abre este modulo (ruta nativa)
        detector: null,     // BarcodeDetector nativo
        zxingReader: null,  // respaldo ZXing (maneja su propio stream)
        rafId: null,
        // código ya entregado -> última vez que se lo vio. NO alcanza con
        // recordar solo el último: al recibir un cierre hay varias etiquetas a
        // la vista y la cámara barre de una a otra, así que al volver a cruzar
        // una ya leída se reenviaría sola.
        seen: new Map(),
        pending: null,      // { code, format, hits, at } lectura a la espera de confirmacion
        hintAt: 0,          // ultima vez que se aviso "centre el codigo"
        lastSeenAt: 0,      // ultima vez que el detector devolvio ALGO (aunque se descartara)
        rotMode: false,     // alternando frame normal / girado 90 grados
        rotFlip: false,     // cual de los dos toca en este frame
        rotCanvas: null,    // lienzo reutilizado para girar/copiar el frame
        warned: false,      // ya se aviso por consola de un fallo del decodificador
        needsCanvas: false, // el motor decodifica un lienzo, no el <video> (ZXing)
        minInterval: 0,     // ms minimos entre decodificaciones (freno para ZXing)
        lastTickAt: 0,
        onDetected: null,
        onError: null,
        origParent: null,   // posicion original del overlay (para restaurarla al cerrar)
        origNext: null,
    };

    // Monta el overlay al final del <body>, por encima de cualquier modal. Asi
    // escapa del stacking context de la pagina y del inert que el focus-trap de
    // un modal (x-trap.inert) le pone a todo lo que esta fuera del modal — sin
    // esto el escaner aparece detras del modal de registro y los toques caen en
    // el backdrop del modal (que lo cierra).
    function mountOnBody(overlay) {
        if (!overlay) return;
        if (overlay.parentElement !== document.body) {
            state.origParent = overlay.parentElement;
            state.origNext = overlay.nextSibling;
            document.body.appendChild(overlay);
        }
        overlay.removeAttribute('inert');
    }

    // Devuelve el overlay a su lugar original para no dejar duplicados al navegar.
    function restoreOverlay(overlay) {
        if (overlay && state.origParent) {
            state.origParent.insertBefore(overlay, state.origNext || null);
        }
        state.origParent = null;
        state.origNext = null;
    }

    const $ = (id) => document.getElementById(id);
    const els = () => ({
        overlay: $('scanner-overlay'),
        video: $('scanner-video'),
        status: $('scanner-status'),
        guide: $('scanner-guide'),
        frame: $('scanner-frame'),
    });

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
        if (!status) return;
        status.textContent = msg || '';
        status.classList.remove('scan-ok', 'scan-err');
    }

    /**
     * Muestra el resultado de la lectura DENTRO del overlay.
     *
     * La cámara se queda abierta a propósito (recibir un cierre son veinte
     * guías seguidas), y el overlay tapa la pantalla entera: el aviso que el
     * componente pinta en la página queda DEBAJO y no se ve hasta cerrarla.
     * Sin esto, escanear veinte guías es escanear a ciegas — no se sabe cuál
     * entró y cuál no hasta el final.
     */
    function notify(message, type) {
        const { status } = els();
        if (!status) return;
        status.textContent = message || SCAN_PROMPT;
        status.classList.remove('scan-ok', 'scan-err');
        if (message) status.classList.add(type === 'error' ? 'scan-err' : 'scan-ok');
    }

    // Muestra el error en el overlay y se lo pasa a quien abrio el escaner.
    function fail(msg) {
        setStatus(msg);
        if (typeof state.onError === 'function') state.onError(msg);
    }

    // ---------------------------------------------------------------------
    // 3) DIGITO VERIFICADOR
    //
    // EAN-13, EAN-8, UPC-A y UPC-E llevan uno por norma GS1: una lectura
    // corrida o con un digito de mas no cuadra y se descarta sin costo. Es la
    // defensa mas barata que hay contra un codigo inventado, y la unica que
    // atrapa el caso en que la lectura mala SI se repite entre frames (camara
    // quieta sobre una etiqueta arrugada o con brillo).
    // ---------------------------------------------------------------------

    // Suma GS1: pesos 3 y 1 alternados de derecha a izquierda sobre el cuerpo.
    function gtinValid(s) {
        if (!/^\d+$/.test(s)) return false;
        const n = s.length;
        if (n !== 8 && n !== 12 && n !== 13 && n !== 14) return false;
        let sum = 0;
        for (let i = n - 2; i >= 0; i--) {
            sum += ((n - 2 - i) % 2 === 0 ? 3 : 1) * (+s[i]);
        }
        return ((10 - (sum % 10)) % 10) === (+s[n - 1]);
    }

    // UPC-E comprimido (8 digitos) -> UPC-A (12) SOLO para verificarlo: su
    // digito verificador se calcula sobre la forma larga. No se usa para
    // cambiar lo que se entrega — el codigo que se guarda tiene que ser el que
    // el lector leyo, no una version reescrita por nosotros.
    function upceToUpca(s) {
        if (!/^[01]\d{7}$/.test(s)) return null;
        const sys = s[0], d = s.slice(1, 7), chk = s[7];
        switch (d[5]) {
            case '0': case '1': case '2':
                return sys + d[0] + d[1] + d[5] + '0000' + d[2] + d[3] + d[4] + chk;
            case '3':
                return sys + d[0] + d[1] + d[2] + '00000' + d[3] + d[4] + chk;
            case '4':
                return sys + d[0] + d[1] + d[2] + d[3] + '00000' + d[4] + chk;
            default:
                return sys + d[0] + d[1] + d[2] + d[3] + d[4] + '0000' + d[5] + chk;
        }
    }

    // true = el codigo es coherente consigo mismo (o no hay forma de saberlo).
    function checksumOk(code, format) {
        switch (format) {
            case 'ean_13': case 'ean_8': case 'upc_a':
                return gtinValid(code);
            case 'upc_e': {
                // Segun el navegador llega comprimido (8) o ya expandido (12).
                if (code.length === 12) return gtinValid(code);
                const upca = upceToUpca(code);
                return upca ? gtinValid(upca) : false;
            }
            default:
                // code_128 y qr_code los verifica el propio decodificador;
                // code_39 no tiene con que.
                return true;
        }
    }

    // Formatos sin verificacion propia: se les exige una repeticion mas.
    function confirmationsNeeded(format) {
        return UNCHECKED_FORMATS.indexOf(format) !== -1 ? CONFIRM_UNCHECKED : CONFIRM_DEFAULT;
    }

    // ---------------------------------------------------------------------
    // 4) LA MIRA MANDA
    //
    // Cuando en el cuadro hay mas de un codigo (la caja trae el del embalaje y
    // el del producto, o se ve la etiqueta del articulo de al lado) el detector
    // los devuelve todos y no promete ningun orden: quedarse con el primero era
    // quedarse con uno al azar. Se elige el que esta dentro del recuadro, y
    // entre esos el mas centrado.
    // ---------------------------------------------------------------------

    // Las medidas se cachean: leer getBoundingClientRect fuerza un reflow, y
    // esto correria en cada frame. La geometria del overlay solo cambia si
    // gira la pantalla o cambia el tamano de la ventana.
    let roiCache = null;
    function invalidateRoi() { roiCache = null; }

    // Al girar el equipo o cambiar el tamano de la ventana cambia el tope de
    // alto en pixeles, y con el el ancho que le corresponde a la caja.
    function onViewportChange() {
        invalidateRoi();
        fitFrameToVideo();
    }
    window.addEventListener('resize', onViewportChange);
    window.addEventListener('orientationchange', onViewportChange);

    function roiInVideo() {
        if (roiCache) return roiCache;
        return (roiCache = measureRoi());
    }

    // Le da al contenedor la proporcion del video que la camara entrego de
    // verdad, para que se vea el frame ENTERO y sin banda negra. No se puede
    // fijar en el CSS: cada equipo entrega lo que puede (16:9, 4:3, y en
    // telefono a veces ya girado a vertical).
    function fitFrameToVideo() {
        const { video, frame } = els();
        if (!video || !frame || !video.videoWidth || !video.videoHeight) return;
        frame.style.aspectRatio = video.videoWidth + ' / ' + video.videoHeight;

        // El ancho se topa en la MISMA proporcion que el alto.
        //
        // Con solo el tope de alto del CSS, el ancho se queda en el 100% del
        // panel: la proporcion se rompe, `object-contain` mete bandas negras a
        // los lados y la camara se ve mas chica de lo que cabe (es lo que pasaba
        // con una camara vertical, 1080x1920, donde el tope siempre entra en
        // juego). Topando los dos, la caja encoge entera y el video la llena.
        //
        // El tope se LEE del CSS en vez de repetirlo aqui, para que no haya dos
        // numeros que puedan discrepar.
        const cap = parseFloat(getComputedStyle(frame).maxHeight);
        frame.style.maxWidth = (isFinite(cap) && cap > 0)
            ? (cap * video.videoWidth / video.videoHeight) + 'px'
            : '';

        invalidateRoi(); // cambio la caja: las medidas cacheadas ya no valen
    }

    // Recuadro de la mira convertido a pixeles del video. Devuelve null si aun
    // no hay medidas fiables (video sin dimensiones, overlay recien abierto).
    function measureRoi() {
        const { video, guide } = els();
        if (!video || !guide) return null;
        const vw = video.videoWidth, vh = video.videoHeight;
        if (!vw || !vh) return null;
        const vr = video.getBoundingClientRect();
        const gr = guide.getBoundingClientRect();
        if (!vr.width || !vr.height || !gr.width || !gr.height) return null;

        // El <video> va con object-CONTAIN: la imagen se escala al MENOR de los
        // dos factores y se centra, sin recortar nada. Sin replicar eso, el
        // recuadro caeria en otro lugar del frame.
        //
        // Con la proporcion del contenedor ya igualada a la del video (lo hace
        // fitFrameToVideo) los dos factores coinciden y no hay banda negra;
        // el minimo es lo correcto mientras tanto.
        const scale = Math.min(vr.width / vw, vr.height / vh);
        const offX = vr.left + (vr.width - vw * scale) / 2;
        const offY = vr.top + (vr.height - vh * scale) / 2;

        const x = (gr.left - offX) / scale;
        const y = (gr.top - offY) / scale;
        const w = gr.width / scale;
        const h = gr.height / scale;

        // Holgura: el cajero apunta, no encuadra.
        const cx = x + w / 2, cy = y + h / 2;
        const sw = w * ROI_SLACK, sh = h * ROI_SLACK;

        return { x: cx - sw / 2, y: cy - sh / 2, w: sw, h: sh, cx: cx, cy: cy };
    }

    function centerOf(box) {
        if (!box) return null;
        const x = typeof box.x === 'number' ? box.x : box.left;
        const y = typeof box.y === 'number' ? box.y : box.top;
        if (typeof x !== 'number' || typeof y !== 'number') return null;
        return { x: x + (box.width || 0) / 2, y: y + (box.height || 0) / 2 };
    }

    // De todos los codigos del frame, el que hay que tomar (o null).
    //
    // mapCenter traduce las coordenadas a las del frame original cuando lo que
    // se analizo fue el lienzo girado; sin el, la mira compararia posiciones de
    // dos sistemas de coordenadas distintos.
    function pickCandidate(codes, mapCenter) {
        const usable = [];
        for (const c of codes) {
            const code = (c.rawValue || '').trim();
            if (!code) continue;
            if (!checksumOk(code, c.format)) continue; // lectura incoherente: fuera
            let center = centerOf(c.boundingBox);
            if (center && mapCenter) center = mapCenter(center);
            usable.push({ code: code, format: c.format, center: center });
        }
        if (!usable.length) return null;

        // Con un solo codigo a la vista no hay nada que desempatar, y se acepta
        // aunque caiga fuera de la mira: si el mapeo del recuadro fallara en
        // algun equipo, el escaner tiene que seguir leyendo. La mira desempata,
        // no autoriza — de la lectura falsa se encargan el verificador y las
        // confirmaciones.
        if (usable.length === 1) return usable[0];

        const roi = roiInVideo();
        if (!roi) {
            // Sin medidas: al menos el mas cercano al centro del frame.
            const { video } = els();
            const fc = { x: (video.videoWidth || 0) / 2, y: (video.videoHeight || 0) / 2 };
            return nearest(usable, fc) || usable[0];
        }

        const inside = usable.filter((u) => u.center
            && u.center.x >= roi.x && u.center.x <= roi.x + roi.w
            && u.center.y >= roi.y && u.center.y <= roi.y + roi.h);

        if (!inside.length) {
            // Hay codigos a la vista pero ninguno en la mira: decirlo, en vez
            // de quedarse callado leyendo el equivocado.
            const now = Date.now();
            if (now - state.hintAt > 900) {
                state.hintAt = now;
                setStatus('Centrá el código en el recuadro');
            }
            return null;
        }

        return nearest(inside, { x: roi.cx, y: roi.cy }) || inside[0];
    }

    function nearest(list, point) {
        let best = null, bestD = Infinity;
        for (const it of list) {
            if (!it.center) continue;
            const dx = it.center.x - point.x, dy = it.center.y - point.y;
            const d = dx * dx + dy * dy;
            if (d < bestD) { bestD = d; best = it; }
        }
        return best;
    }

    // Acumula lecturas iguales hasta llegar a las confirmaciones necesarias.
    function considerCandidate(code, format) {
        if (!code) return;
        // Ya hay un codigo bueno a la vista: retirar el aviso de centrar.
        if (state.hintAt) { state.hintAt = 0; setStatus(SCAN_PROMPT); }
        const now = Date.now();
        const p = state.pending;

        if (p && p.code === code && (now - p.at) <= CONFIRM_WINDOW_MS) {
            p.hits += 1;
            p.at = now;
        } else {
            state.pending = { code: code, format: format, hits: 1, at: now };
        }

        if (state.pending.hits >= confirmationsNeeded(state.pending.format)) {
            const confirmed = state.pending.code;
            state.pending = null;
            emitDetected(confirmed);
        }
    }

    function emitDetected(code) {
        if (!code) return;
        const now = Date.now();

        const visto = state.seen.get(code);
        // La marca se actualiza SIEMPRE, también cuando se descarta: así el
        // reloj cuenta desde que el código deja de verse, no desde que entró.
        state.seen.set(code, now);
        if (visto !== undefined && (now - visto) <= REARM_MS) return;

        // Olvidar lo viejo para que el mapa no crezca en un turno largo.
        for (const [c, t] of state.seen) {
            if (now - t > SEEN_TTL_MS) state.seen.delete(c);
        }

        feedbackSuccess();
        if (typeof state.onDetected === 'function') state.onDetected(code);
    }

    // ---------------------------------------------------------------------
    // 5) EL FRAME GIRADO
    //
    // Hay decodificadores que solo recorren lineas horizontales de la imagen y
    // no leen un codigo acostado. Cuando pasan ROTATE_AFTER_MS sin decodificar
    // NADA, se empieza a alternar frame normal / frame girado 90 grados.
    //
    // No se hace siempre porque girar 2 Mpx por frame cuesta, y no se apaga al
    // volver a leer algo: si se apagara, el codigo girado se leeria a tirones
    // (un frame de cada tanto) y no alcanzaria a juntar las confirmaciones.
    // Se paga solo mientras el cajero esta batallando, que es cuando conviene.
    // ---------------------------------------------------------------------

    // Copia el frame a un lienzo, girado 90 grados o tal cual.
    //
    // El lienzo tambien hace falta SIN girar para ZXing, que decodifica sobre
    // un canvas, no sobre el <video>.
    function drawFrame(video, rotate) {
        const vw = video.videoWidth, vh = video.videoHeight;
        if (!vw || !vh) return null;
        let cv = state.rotCanvas;
        if (!cv) cv = state.rotCanvas = document.createElement('canvas');
        const w = rotate ? vh : vw, h = rotate ? vw : vh;
        if (cv.width !== w || cv.height !== h) { cv.width = w; cv.height = h; }
        const ctx = cv.getContext('2d', { willReadFrequently: true });
        if (!ctx) return null;
        if (rotate) {
            ctx.save();
            ctx.translate(cv.width, 0);
            ctx.rotate(Math.PI / 2);
            ctx.drawImage(video, 0, 0, vw, vh);
            ctx.restore();
        } else {
            ctx.drawImage(video, 0, 0, vw, vh);
        }
        return cv;
    }

    // Que imagen se le pasa al decodificador en este frame.
    function detectionSource() {
        const { video } = els();
        if (!state.rotMode && (Date.now() - state.lastSeenAt) > ROTATE_AFTER_MS) {
            state.rotMode = true;
        }
        state.rotFlip = state.rotMode ? !state.rotFlip : false;

        // El detector nativo lee el <video> directo, que es el camino rapido.
        if (!state.rotFlip && !state.needsCanvas) {
            return { el: video, mapCenter: null, rotated: false };
        }

        const cv = drawFrame(video, state.rotFlip);
        if (!cv) return { el: video, mapCenter: null, rotated: false };
        if (!state.rotFlip) return { el: cv, mapCenter: null, rotated: false };

        // El giro lleva el punto (x, y) del video a (vh - y, x) del lienzo.
        // Esto es la vuelta, para poder comparar contra la mira.
        const vh = video.videoHeight;
        return { el: cv, mapCenter: (c) => ({ x: c.y, y: vh - c.x }), rotated: true };
    }

    function nativeSupported() {
        return 'BarcodeDetector' in window;
    }

    // La camara la abre SIEMPRE este modulo, tambien para ZXing. Antes ZXing la
    // abria por su cuenta con decodeFromConstraints y se quedaba con su propio
    // bucle: por eso ni el contador de frames ni la pasada girada existian en
    // ese camino — que resulto ser justo el que corre en iPhone.
    async function startCamera() {
        const { video } = els();
        state.stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
                // Las barras de un EAN-13 en una etiqueta pequena miden pocos
                // pixeles a 720p: pedir mas resolucion es lo que mas reduce las
                // lecturas equivocadas. Es "ideal", asi que el equipo que no
                // pueda dara lo que tenga.
                width: { ideal: 1920 },
                height: { ideal: 1080 },
            },
        });
        video.srcObject = state.stream;
        video.setAttribute('playsinline', 'true'); // iOS: no toma pantalla completa
        await video.play();
        fitFrameToVideo();

        // Enfoque continuo donde exista: un frame desenfocado es de donde sale
        // la lectura falsa. Si el navegador no lo soporta, no pasa nada.
        try {
            const track = state.stream.getVideoTracks()[0];
            const caps = track && track.getCapabilities ? track.getCapabilities() : null;
            if (caps && caps.focusMode && caps.focusMode.indexOf('continuous') !== -1) {
                await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] });
            }
        } catch (e) {}
    }

    // Bucle unico para los dos motores. `decodeOne(el)` devuelve una lista de
    // candidatos con la forma { rawValue, format, boundingBox }.
    function runLoop(decodeOne) {
        const tick = async () => {
            if (!state.active) return;

            // ZXing decodifica de forma sincrona y lenta; sin freno se come el
            // hilo y el video va a tirones. El nativo no lo necesita.
            if (state.minInterval && (Date.now() - state.lastTickAt) < state.minInterval) {
                state.rafId = requestAnimationFrame(tick);
                return;
            }
            state.lastTickAt = Date.now();

            const source = detectionSource();
            try {
                const found = await decodeOne(source.el);
                if (found && found.length) {
                    // Que devuelva algo — aunque luego se descarte por
                    // verificador o por la mira — significa que la orientacion
                    // no es el problema.
                    state.lastSeenAt = Date.now();
                    const pick = pickCandidate(found, source.mapCenter);
                    if (pick) considerCandidate(pick.code, pick.format);
                }
            } catch (e) {
                // El error NO se traga en silencio. Si el decodificador dejara
                // de aceptar el lienzo, la pasada girada seria un no-op
                // invisible y el sintoma en caja seria "no escanea" a secas,
                // sin nada donde mirar. Se avisa UNA vez por apertura para no
                // llenar la consola a 30 por segundo.
                if (!state.warned) {
                    state.warned = true;
                    console.warn('[EncomiendasScanner] decode(' + (source.rotated ? 'girado' : 'directo') + ') fallo:', e);
                }
            }
            if (state.active) state.rafId = requestAnimationFrame(tick);
        };
        state.rafId = requestAnimationFrame(tick);
    }

    async function runNative() {
        state.needsCanvas = false;
        state.minInterval = 0;
        try {
            state.detector = new window.BarcodeDetector({ formats: FORMATS });
        } catch (e) {
            state.detector = new window.BarcodeDetector(); // formato no soportado -> usar todos
        }
        runLoop((el) => state.detector.detect(el));
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

    // Los mismos formatos de arriba, en el enum de ZXing.
    function zxingHints(Z) {
        const hints = new Map();
        const wanted = FORMATS
            .map((f) => Z.BarcodeFormat[f.toUpperCase()])
            .filter((v) => v !== undefined);
        if (wanted.length) hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, wanted);
        // Sin esto ZXing solo mira la franja central del frame.
        hints.set(Z.DecodeHintType.TRY_HARDER, true);
        return hints;
    }

    // "No encontre nada en este frame" NO es un error: es lo que pasa en la
    // mayoria de los frames.
    //
    // Ojo con como se reconoce. El bundle del CDN va MINIFICADO, asi que
    // `e.name` es una letra suelta ("N") — mirar el nombre no sirve y hace que
    // cada frame vacio se cuente como fallo. Lo que si sobrevive a la
    // minificacion es la clase exportada (instanceof) y la propiedad `kind`
    // que ZXing le pone al constructor.
    function zxingNotFound(Z, e) {
        if (!e) return false;
        if ((Z.NotFoundException && e instanceof Z.NotFoundException)
            || (Z.ChecksumException && e instanceof Z.ChecksumException)
            || (Z.FormatException && e instanceof Z.FormatException)) {
            return true;
        }
        const kind = e.constructor && e.constructor.kind;
        return kind === 'NotFoundException' || kind === 'ChecksumException' || kind === 'FormatException';
    }

    // ZXing devuelve puntos, no un rectangulo. Con ellos se arma uno para que
    // la mira pueda desempatar igual que con el detector nativo.
    function boxFromPoints(points) {
        if (!points || !points.length) return null;
        let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
        for (const p of points) {
            if (!p || typeof p.getX !== 'function') continue;
            const x = p.getX(), y = p.getY();
            if (x < x0) x0 = x; if (x > x1) x1 = x;
            if (y < y0) y0 = y; if (y > y1) y1 = y;
        }
        if (!isFinite(x0)) return null;
        return { x: x0, y: y0, width: x1 - x0, height: y1 - y0 };
    }

    async function runZxing() {
        setStatus('Cargando lector...');
        await loadScript(ZXING_CDN);
        const Z = window.ZXing;
        if (!Z || !Z.BrowserMultiFormatReader) throw new Error('zxing');

        const reader = new Z.BrowserMultiFormatReader(zxingHints(Z));
        state.zxingReader = reader;

        // Camino preferido: nuestra camara + nuestro bucle, armando el bitmap a
        // mano. Es lo que permite probar el frame girado.
        //
        // El bitmap se arma pieza por pieza en vez de usar un ayudante del
        // lector: `decodeFromCanvas` NO existe en esta version (0.21.3) — se
        // comprobo contra el bundle del CDN — mientras que `decodeBitmap` y las
        // tres clases de abajo si estan exportadas.
        if (typeof reader.decodeBitmap === 'function'
            && Z.HTMLCanvasElementLuminanceSource && Z.HybridBinarizer && Z.BinaryBitmap) {
            state.needsCanvas = true;
            state.minInterval = 90;

            // Aqui el giro arranca ENCENDIDO, no tras los 900 ms de espera.
            //
            // ZXing decodifica recorriendo FILAS de pixeles: un codigo acostado
            // en el frame no lo cruza ninguna, y no es que le cueste — no lo lee
            // nunca. Ademas en iOS el frame que llega al lienzo viene girado
            // respecto de lo que el cajero ve en pantalla, asi que "acostado en
            // el frame" es el caso NORMAL, no la excepcion. Esperar 900 ms para
            // empezar a probarlo seria regalar 900 ms en cada escaneo.
            state.rotMode = true;

            await startCamera();
            runLoop((el) => {
                let result = null;
                try {
                    const luminance = new Z.HTMLCanvasElementLuminanceSource(el);
                    const bitmap = new Z.BinaryBitmap(new Z.HybridBinarizer(luminance));
                    result = reader.decodeBitmap(bitmap);
                } catch (e) {
                    if (zxingNotFound(Z, e)) return [];
                    throw e;
                }
                if (!result) return [];
                let format = '';
                try { format = (Z.BarcodeFormat[result.getBarcodeFormat()] || '').toLowerCase(); } catch (e) {}
                return [{
                    rawValue: result.getText(),
                    format: format,
                    boundingBox: boxFromPoints(result.getResultPoints ? result.getResultPoints() : null),
                }];
            });
            return;
        }

        // Respaldo del respaldo: esta version de ZXing no expone las piezas para
        // armar el bitmap. Se queda con su bucle propio — sin frame girado, o
        // sea sin leer codigos acostados, pero leyendo, que es mejor que nada.
        console.warn('[EncomiendasScanner] esta version de ZXing no expone decodeBitmap: '
            + 'se usa su bucle propio, SIN pasada girada (los codigos acostados no se leeran).');
        state.needsCanvas = false;
        await reader.decodeFromConstraints(
            { audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } },
            els().video,
            (result) => {
                if (!result) return;
                let format = '';
                try { format = (Z.BarcodeFormat[result.getBarcodeFormat()] || '').toLowerCase(); } catch (e) {}
                const code = (result.getText() || '').trim();
                if (!checksumOk(code, format)) return;
                considerCandidate(code, format);
            }
        );
    }

    async function open(opts = {}) {
        if (state.active) return;
        state.onDetected = opts.onDetected || null;
        state.onError = opts.onError || null;
        state.seen.clear();
        state.pending = null;
        state.hintAt = 0;
        // Arranca sin girar y con el reloj en cero: el giro entra solo si de
        // verdad pasan ROTATE_AFTER_MS sin decodificar nada.
        state.lastSeenAt = Date.now();
        state.rotMode = false;
        state.rotFlip = false;
        state.lastTickAt = 0;
        state.warned = false;
        invalidateRoi();

        const { overlay, video } = els();
        // ZXing abre la camara por su cuenta, y en movil el stream puede cambiar
        // de tamano al girar el equipo: en los dos casos la caja se reajusta
        // sola. Se engancha una sola vez — el <video> vive en el DOM entre
        // aperturas.
        if (video && !video.__scannerFitBound) {
            video.__scannerFitBound = true;
            video.addEventListener('loadedmetadata', fitFrameToVideo);
            video.addEventListener('resize', fitFrameToVideo);
        }
        if (overlay) {
            mountOnBody(overlay);
            overlay.classList.remove('hidden');
        }
        state.active = true;
        unlockAudio(); // dentro del gesto del toque, para poder sonar luego en móvil
        setStatus('Iniciando cámara...');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            fail('La cámara necesita una conexión segura (HTTPS).');
            return;
        }

        try {
            if (nativeSupported()) {
                await startCamera();
                setStatus(SCAN_PROMPT);
                await runNative();
            } else {
                await runZxing();
                setStatus(SCAN_PROMPT);
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
        state.pending = null;
        state.rotMode = false;
        state.rotFlip = false;
        state.rotCanvas = null; // un lienzo de 2 Mpx no tiene por que sobrevivir al cierre
        invalidateRoi();
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
    window.EncomiendasScanner = { open, close, notify };

    // Los listeners globales se enlazan una sola vez: si no, se acumula uno por
    // cada navegación y la cámara se cierra varias veces.
    if (!window.__scannerBound) {
        window.__scannerBound = true;
        const closeCurrent = () => { if (window.EncomiendasScanner) window.EncomiendasScanner.close(); };
        document.addEventListener('livewire:navigating', closeCurrent);
        window.addEventListener('pagehide', closeCurrent);
    }
})();
