/**
 * Núcleo del modo sin conexión: la cola de guías y su sincronización.
 *
 * Lo usan las DOS caras del modo offline, y por eso vive en un solo archivo:
 *   - el watchdog del layout (resources/views/offline/watchdog.blade.php), que
 *     sube la cola desde cualquier pantalla con conexión;
 *   - la pantalla /guias-offline, que la llena sin conexión y la sube al volver.
 * Si cada una tuviera su copia, tarde o temprano una trataría distinto un
 * resultado del servidor y una guía se perdería o se subiría dos veces.
 *
 * Sin clases de Tailwind a propósito: este archivo no lo escanea el build.
 * El service worker lo sirve CACHE-FIRST: al cambiarlo hay que subir la
 * versión del cache en public/sw-guias.js.
 *
 * Claves de localStorage:
 *   enc_offline_snapshot      sedes, tarifas, impuestos, clientes de crédito
 *   enc_offline_snapshot_at   cuándo se guardó (ms)
 *   enc_offline_cola          guías por subir
 *   enc_offline_fallidas      rechazadas para siempre (se ven y se descartan a mano)
 *   enc_offline_listas        sincronizadas cuya etiqueta todavía no se imprimió
 *   enc_offline_seq           consecutivo LOCAL del comprobante provisional
 */
(function (global) {
    'use strict';

    var K = {
        snap: 'enc_offline_snapshot',
        snapAt: 'enc_offline_snapshot_at',
        cola: 'enc_offline_cola',
        fallidas: 'enc_offline_fallidas',
        listas: 'enc_offline_listas',
        seq: 'enc_offline_seq',
    };

    var URL_DATA = '/guias-offline/data';
    var URL_SYNC = '/guias-offline/sync';
    var TANDA = 50;

    function leer(clave, porDefecto) {
        try {
            var v = JSON.parse(localStorage.getItem(clave) || 'null');
            return v === null ? porDefecto : v;
        } catch (e) {
            return porDefecto;
        }
    }

    function escribir(clave, valor) {
        try {
            localStorage.setItem(clave, JSON.stringify(valor));
            return true;
        } catch (e) {
            return false;
        }
    }

    function uuid() {
        if (global.crypto && crypto.randomUUID) return crypto.randomUUID();
        // Navegadores viejos o contexto no seguro: v4 a mano.
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    var sincronizando = false;

    var api = {
        claves: K,
        leer: leer,
        escribir: escribir,
        uuid: uuid,

        snapshot: function () { return leer(K.snap, null); },
        snapshotAt: function () { return parseInt(localStorage.getItem(K.snapAt) || '0', 10); },
        cola: function () { return leer(K.cola, []); },
        fallidas: function () { return leer(K.fallidas, []); },
        listas: function () { return leer(K.listas, []); },

        /**
         * Baja el snapshot y lo guarda. Solo guarda lo que ES un snapshot: el
         * servidor responde {enabled:false} cuando el modo está apagado, y
         * guardar eso borraría el que sí servía.
         */
        refrescarSnapshot: function () {
            return fetch(URL_DATA, { headers: { 'Accept': 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { return r.ok && !r.redirected ? r.json() : null; })
                .then(function (d) {
                    if (!d || d.enabled !== true || !Array.isArray(d.sedes)) return false;
                    if (!escribir(K.snap, d)) return false;
                    localStorage.setItem(K.snapAt, String(Date.now()));
                    return true;
                })
                .catch(function () { return false; });
        },

        /** Número del comprobante provisional: OFF-<prefijo>-<n>. */
        siguienteReferencia: function (prefijo) {
            var n = parseInt(localStorage.getItem(K.seq) || '0', 10) + 1;
            localStorage.setItem(K.seq, String(n));
            return 'OFF-' + (prefijo || 'X') + '-' + n;
        },

        /** Encola una guía. Relee antes de escribir: puede haber otra pestaña. */
        encolar: function (guia) {
            var cola = leer(K.cola, []);
            cola.push(guia);
            return escribir(K.cola, cola);
        },

        descartarFallida: function (uuid) {
            escribir(K.fallidas, leer(K.fallidas, []).filter(function (g) { return g.client_uuid !== uuid; }));
        },

        /** La fallida vuelve a la cola, por si lo que la rechazaba se corrigió. */
        reintentarFallida: function (uuid) {
            var g = leer(K.fallidas, []).filter(function (x) { return x.client_uuid === uuid; })[0];
            if (!g) return;
            delete g.motivo;
            api.descartarFallida(uuid);
            api.encolar(g);
        },

        marcarEtiquetaImpresa: function (uuid) {
            escribir(K.listas, leer(K.listas, []).filter(function (g) { return g.client_uuid !== uuid; }));
        },

        /**
         * Sube la cola. Devuelve {subidas, pendientes, fallidas} o null si no
         * hubo red.
         *
         * Cada resultado se trata según el contrato de GuiasOfflineController:
         * synced/duplicate salen de la cola, failed sale a la lista de
         * fallidas, error SE QUEDA (es algo que alguien puede corregir).
         *
         * Toda escritura relee la cola después del await: una guía encolada
         * mientras subía la tanda no puede perderse.
         */
        sincronizar: function () {
            var cola = leer(K.cola, []);
            if (!cola.length || sincronizando) return Promise.resolve(null);
            sincronizando = true;

            var tanda = cola.slice(0, TANDA);

            // Token fresco: el de la página guardada por el service worker
            // puede tener horas.
            return fetch(URL_DATA + '?light=1', { headers: { 'Accept': 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { return r.ok && !r.redirected ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.csrf) return null;
                    return fetch(URL_SYNC, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': d.csrf },
                        body: JSON.stringify({ guias: tanda.map(function (g) { var c = Object.assign({}, g); delete c.ultimo_error; return c; }) }),
                    });
                })
                .then(function (res) { return res && res.ok ? res.json() : null; })
                .then(function (body) {
                    if (!body || !Array.isArray(body.results)) return null;

                    var porUuid = {};
                    body.results.forEach(function (r) { porUuid[r.client_uuid] = r; });

                    var listas = leer(K.listas, []);
                    var fallidas = leer(K.fallidas, []);
                    var subidas = 0;

                    var resto = leer(K.cola, []).filter(function (g) {
                        var r = porUuid[g.client_uuid];
                        if (!r) return true;

                        if (r.status === 'synced' || r.status === 'duplicate') {
                            subidas++;
                            var yaEsta = listas.some(function (l) { return l.client_uuid === g.client_uuid; });
                            if (!yaEsta) listas.push({
                                client_uuid: g.client_uuid,
                                referencia: g.offline_reference,
                                code: r.code,
                                etiqueta: r.etiqueta,
                                ver: r.ver,
                                destinatario: g.recipient_name,
                                esperando_caja: !!r.awaiting_cashier,
                            });
                            return false;
                        }

                        if (r.status === 'failed') {
                            g.motivo = r.reason || 'Rechazada';
                            fallidas.push(g);
                            return false;
                        }

                        g.ultimo_error = r.reason || 'Se reintenta sola.';
                        return true;
                    });

                    escribir(K.cola, resto);
                    escribir(K.listas, listas);
                    escribir(K.fallidas, fallidas);

                    return { subidas: subidas, pendientes: resto.length, fallidas: fallidas.length };
                })
                .catch(function () { return null; })
                .then(function (r) { sincronizando = false; return r; });
        },
    };

    global.EncOffline = api;
})(window);
