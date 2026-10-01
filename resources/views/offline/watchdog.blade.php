{{--
    Watchdog del modo sin conexión (copia del de retailpos, layouts/app.blade.php).

    Corre en el <head> de TODAS las pantallas del personal:
      - registra el service worker que sirve /guias-offline sin red;
      - guarda el snapshot (tarifas, sedes, clientes de crédito) y lo refresca;
      - detecta la caída de la red y manda a /guias-offline;
      - sube la cola de guías hechas sin conexión cuando vuelve la red;
      - avisa de lo pendiente: guías por subir y etiquetas por imprimir.

    No confía en navigator.onLine (en muchos equipos no cambia al apagar el
    wifi): late contra /__ping, engancha el hook `request` de Livewire y vigila
    las navegaciones que se cuelgan.

    La condición sale de ModoOffline::habilitado(), la misma que usa el
    controlador. No duplicarla.
--}}
<script src="/js/guias-offline.js"></script>
<script>
    (function () {
        var HABILITADO = @json(\App\Support\ModoOffline::habilitado());
        var PAGE = '/guias-offline';
        var O = window.EncOffline;

        if (!HABILITADO) {
            // Apagado: borrar el snapshot y soltar el service worker. La COLA
            // no se toca: son guías de paquetes que ya se recibieron.
            try {
                localStorage.removeItem(O.claves.snap);
                localStorage.removeItem(O.claves.snapAt);
            } catch (e) {}

            if ('serviceWorker' in navigator && navigator.serviceWorker.controller
                && !sessionStorage.getItem('enc_sw_limpio')) {
                sessionStorage.setItem('enc_sw_limpio', '1');
                Promise.all([
                    navigator.serviceWorker.getRegistrations().then(function (rs) {
                        return Promise.all(rs.map(function (r) {
                            return (r.active && /sw-guias\.js/.test(r.active.scriptURL)) ? r.unregister() : null;
                        }));
                    }).catch(function () {}),
                    (window.caches ? caches.keys().then(function (ks) {
                        return Promise.all(ks.map(function (k) { return /^enc-guias-offline/.test(k) ? caches.delete(k) : null; }));
                    }).catch(function () {}) : null),
                ]).then(function () { location.reload(); });
            }
            return;
        }
        try { sessionStorage.removeItem('enc_sw_limpio'); } catch (e) {}

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw-guias.js').catch(function () {});
            navigator.serviceWorker.ready.then(function (reg) {
                if (reg.active) reg.active.postMessage('enc-precache');
            }).catch(function () {});
        }

        /* ---------------------------- detección ---------------------------- */

        var yendo = false, sondeando = false, fallos = 0;

        function irOffline() {
            if (yendo || !O.snapshot() || location.pathname === PAGE) return;

            // Gracia de 30 s al volver de la pantalla offline: deja que la red
            // se asiente y corta el ida y vuelta.
            var vuelta = parseInt(sessionStorage.getItem('enc_vuelta') || '0', 10);
            if (Date.now() - vuelta < 30000) return;

            // Cortacircuito: más de 4 rebotes en un minuto es otra cosa, y es
            // mejor dejar al usuario donde está que atraparlo en un bucle.
            var t0 = parseInt(sessionStorage.getItem('enc_rebote_t0') || '0', 10);
            var n = (Date.now() - t0 > 60000) ? 0 : parseInt(sessionStorage.getItem('enc_rebote_n') || '0', 10);
            if (n === 0) sessionStorage.setItem('enc_rebote_t0', String(Date.now()));
            sessionStorage.setItem('enc_rebote_n', String(++n));
            if (n > 4) return;

            yendo = true;
            location.href = PAGE;
        }

        // Cualquier respuesta del servidor prueba que hay red, aunque sea un
        // 500: se mide la conexión, no la aplicación. Hacen falta DOS fallos
        // seguidos: un tropiezo suelto no es quedarse sin internet.
        function sondear() {
            if (sondeando || yendo || document.hidden) return;
            sondeando = true;
            var ctrl = new AbortController();
            var t = setTimeout(function () { ctrl.abort(); }, 5000);

            fetch('/__ping', { cache: 'no-store', signal: ctrl.signal })
                .then(function () { fallos = 0; })
                .catch(function () { if (++fallos >= 2) irOffline(); })
                .then(function () { clearTimeout(t); sondeando = false; });
        }

        setInterval(sondear, 6000);
        window.addEventListener('offline', function () { setTimeout(sondear, 1000); });

        // NO se sobrescribe window.fetch: rompe wire:navigate. Se usa el hook
        // oficial de Livewire para enterarse de un clic que no llegó.
        document.addEventListener('livewire:init', function () {
            try {
                Livewire.hook('request', function (h) {
                    if (h && h.fail) h.fail(function () { sondear(); });
                });
            } catch (e) {}
        });

        var navTimer = null;
        document.addEventListener('livewire:navigate', function () { navTimer = setTimeout(sondear, 3500); });
        document.addEventListener('livewire:navigated', function () { clearTimeout(navTimer); });

        /* ----------------------- snapshot y cola ---------------------------- */

        O.refrescarSnapshot();
        setInterval(O.refrescarSnapshot, 60000);

        function subir() { O.sincronizar().then(pintarAvisos); }
        subir();
        setInterval(subir, 60000);
        window.addEventListener('online', function () { setTimeout(subir, 2000); });

        /* ----------------------------- avisos ------------------------------- */

        // Rellena los huecos que dejan el layout (franja general) y la caja
        // (aviso al cerrar). La cola solo la conoce este navegador.
        function pintarAvisos() {
            var cola = O.cola().length, listas = O.listas().length, fallidas = O.fallidas().length;

            document.querySelectorAll('[data-offline-aviso]').forEach(function (box) {
                var partes = [];
                if (cola) partes.push(cola + (cola === 1 ? ' guía hecha sin conexión todavía no se subió' : ' guías hechas sin conexión todavía no se subieron'));
                if (listas) partes.push(listas + (listas === 1 ? ' guía sincronizada espera su etiqueta' : ' guías sincronizadas esperan su etiqueta'));
                if (fallidas) partes.push(fallidas + (fallidas === 1 ? ' guía fue rechazada' : ' guías fueron rechazadas'));
                box.querySelector('[data-texto]').textContent = partes.join(' · ') + '.';
                box.classList.toggle('hidden', partes.length === 0);
            });

            // El efectivo de una guía sin subir no está en el arqueo todavía:
            // cerrar la caja así lo deja fuera del turno en que se cobró.
            document.querySelectorAll('[data-offline-caja]').forEach(function (box) {
                box.querySelector('[data-texto]').textContent = cola + (cola === 1
                    ? ' guía cobrada sin conexión todavía no se subió: su cobro no está en este arqueo.'
                    : ' guías cobradas sin conexión todavía no se subieron: sus cobros no están en este arqueo.');
                box.classList.toggle('hidden', cola === 0);
            });

            pintarEstado();
        }

        /* ¿Está listo este equipo para trabajar sin red? Hacen falta las tres
           piezas, y se comprueban de verdad: el punto es enterarse ANTES de que
           se caiga el internet, no con el cliente enfrente. */
        function pintarEstado() {
            var cajas = document.querySelectorAll('[data-offline-status]');
            if (!cajas.length) return;

            var faltan = [];
            if (!('serviceWorker' in navigator) || !navigator.serviceWorker.controller) faltan.push('el service worker');
            if (!O.snapshot()) faltan.push('las tarifas');

            var pintar = function () {
                cajas.forEach(function (c) {
                    c.querySelector('[data-listo]').classList.toggle('hidden', faltan.length > 0);
                    c.querySelector('[data-preparando]').classList.toggle('hidden', faltan.length === 0);
                    c.title = faltan.length ? 'Falta: ' + faltan.join(', ') : '';
                });
            };

            if (!window.caches) return pintar();
            caches.match(PAGE).then(function (hit) {
                if (!hit) faltan.push('la pantalla offline');
                pintar();
            }).catch(pintar);
        }

        document.addEventListener('DOMContentLoaded', pintarAvisos);
        document.addEventListener('livewire:navigated', pintarAvisos);
        document.addEventListener('livewire:init', function () {
            try { Livewire.hook('morphed', pintarAvisos); } catch (e) {}
        });
        setInterval(pintarAvisos, 5000);
    })();
</script>
