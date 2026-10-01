<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guías sin conexión</title>
    {{-- Solo el CSS: esta pantalla la sirve el service worker sin red, y
         Livewire/Alpine necesitan al servidor para todo. --}}
    @vite(['resources/css/app.css'])
    <script>
        if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
    </script>
    <script src="/js/guias-offline.js"></script>
</head>
{{--
    Pantalla para seguir recibiendo encomiendas sin internet (ver MODO-OFFLINE.md).

    TODO su estado sale de localStorage (EncOffline, public/js/guias-offline.js):
    se sirve desde el cache del service worker y no puede preguntarle nada al
    servidor para funcionar. Al cambiar este HTML hay que subir la versión del
    cache en public/sw-guias.js, o los equipos que solo la abren sin conexión
    se quedan con la vieja.

    Lo que NO hace sin conexión: imprimir la etiqueta (el código guía lo asigna
    el servidor al sincronizar), buscar clientes registrados salvo los de
    crédito, ni emitir factura electrónica (sale tiquete).
--}}
<body class="min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 text-base">

<header class="sticky top-0 z-20 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
    <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-lg font-bold">Guías sin conexión</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400" id="snap-info"></p>
        </div>
        <div class="flex items-center gap-2">
            <span id="estado-red" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-sm font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
                <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Sin conexión
            </span>
            <button type="button" id="btn-volver" class="btn-secondary !py-1.5 !px-3 text-sm">Volver al sistema</button>
        </div>
    </div>
</header>

<main class="max-w-4xl mx-auto p-4 space-y-6">

    <div id="aviso-volvio" class="hidden flex items-start justify-between gap-3 flex-wrap p-4 rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200">
        <span>Volvió la conexión. Las guías se están subiendo solas; podés terminar lo que estabas haciendo.</span>
        <button type="button" class="font-semibold underline" data-volver>Volver al sistema</button>
    </div>

    <div id="sin-snapshot" class="hidden p-4 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200">
        Este equipo no tiene las tarifas guardadas: hay que abrir el sistema una vez con internet antes
        de poder recibir encomiendas sin conexión.
    </div>

    <div id="mensaje" class="hidden p-4 rounded-lg border"></div>

    {{-- Pendientes: lo primero que hay que ver al volver la red. --}}
    <section id="pendientes" class="hidden card space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-lg font-semibold">Pendientes de este equipo</h2>
            <button type="button" id="btn-sincronizar" class="btn-primary !py-1.5 !px-3 text-sm">Subir ahora</button>
        </div>

        <div id="bloque-listas" class="hidden space-y-2">
            <h3 class="font-medium">Sincronizadas — falta imprimir la etiqueta</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                El paquete no tiene etiqueta todavía. Imprimila y pegala antes de que salga.
            </p>
            <ul id="lista-listas" class="divide-y divide-gray-200 dark:divide-gray-700"></ul>
        </div>

        <div id="bloque-cola" class="hidden space-y-2">
            <h3 class="font-medium">Por subir</h3>
            <ul id="lista-cola" class="divide-y divide-gray-200 dark:divide-gray-700"></ul>
        </div>

        <div id="bloque-fallidas" class="hidden space-y-2">
            <h3 class="font-medium text-red-700 dark:text-red-300">Rechazadas</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                El servidor no las aceptó. Revisá el motivo: si se corrigió, reintentá; si no, registrala de nuevo en línea y descartala.
            </p>
            <ul id="lista-fallidas" class="divide-y divide-gray-200 dark:divide-gray-700"></ul>
        </div>
    </section>

    <form id="form" class="space-y-6" novalidate>
        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Ruta</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="label">Origen</label>
                    <input type="text" id="origen" class="input" disabled>
                </div>
                <div>
                    <label class="label">Ruta (opcional)</label>
                    <select id="ruta" class="input"></select>
                </div>
                <div>
                    <label class="label">Destino</label>
                    <select id="destino" class="input"></select>
                    <p class="error-text hidden" data-error="destino"></p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="label">Tipo de envío</label>
                    <select id="tipo-envio" class="input"></select>
                </div>
            </div>
        </div>

        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Cobro</h2>
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="cobro" value="prepaid" checked> Paga ahora</label>
                <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="cobro" value="collect"> Por cobrar (paga quien retira)</label>
                <label class="flex items-center gap-2 cursor-pointer" id="opcion-credito"><input type="radio" name="cobro" value="credit"> A crédito</label>
            </div>
            <div id="bloque-medio" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="label">Medio de pago</label>
                    <select id="medio" class="input"></select>
                </div>
            </div>
            <div id="bloque-credito" class="hidden space-y-2">
                <label class="label">Cliente de crédito (remitente)</label>
                <select id="cliente-credito" class="input"></select>
                <p class="error-text hidden" data-error="credito"></p>
                <p id="credito-info" class="text-sm text-gray-500 dark:text-gray-400"></p>
            </div>
            <p id="aviso-caja" class="hidden text-sm text-amber-700 dark:text-amber-300"></p>
        </div>

        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Remitente</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="label">Nombre</label>
                    <input type="text" id="sender_name" class="input" maxlength="150">
                    <p class="error-text hidden" data-error="sender_name"></p>
                </div>
                <div><label class="label">Teléfono</label><input type="text" id="sender_phone" class="input" maxlength="30"></div>
                <div><label class="label">Identificación</label><input type="text" id="sender_identification" class="input" maxlength="20"></div>
            </div>
        </div>

        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Destinatario</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Nombre</label>
                    <input type="text" id="recipient_name" class="input" maxlength="150">
                    <p class="error-text hidden" data-error="recipient_name"></p>
                </div>
                <div><label class="label">Teléfono</label><input type="text" id="recipient_phone" class="input" maxlength="30"></div>
                <div>
                    <label class="label">Identificación <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <input type="text" id="recipient_identification" class="input" maxlength="20" inputmode="numeric">
                </div>
                <div>
                    <label class="label">Correo electrónico</label>
                    <input type="email" id="recipient_email" class="input">
                    <p class="error-text hidden" data-error="recipient_email"></p>
                </div>
            </div>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="home_delivery" class="checkbox"> Entrega a domicilio
            </label>
            <div id="bloque-domicilio" class="hidden grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="label">Dirección exacta</label>
                    <input type="text" id="delivery_address" class="input" maxlength="255">
                    <p class="error-text hidden" data-error="delivery_address"></p>
                </div>
                <div>
                    <label class="label">Cargo por domicilio</label>
                    <input type="number" id="home_delivery_fee" class="input" min="0" step="0.01" value="0">
                </div>
            </div>
        </div>

        <div class="card space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Paquetes</h2>
                <button type="button" id="btn-agregar" class="btn-secondary !py-1.5 !px-3 text-sm">+ Agregar bulto</button>
            </div>
            <div id="bultos" class="space-y-4"></div>
            <p class="error-text hidden" data-error="items"></p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="label">Valor declarado</label>
                    <input type="number" id="declared_value" class="input" min="0" step="0.01" value="0">
                    <p class="text-xs text-gray-500 mt-1" id="seguro-info"></p>
                </div>
            </div>
        </div>

        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Descuento y notas</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="label">Descuento</label>
                    <input type="number" id="discount_amount" class="input" min="0" step="0.01" value="0">
                </div>
                <div id="bloque-clave" class="hidden">
                    <label class="label">Clave de autorización</label>
                    <input type="password" id="discount_code" class="input" autocomplete="off">
                    <p class="error-text hidden" data-error="discount"></p>
                </div>
            </div>
            <div>
                <label class="label">Notas</label>
                <textarea id="notes" rows="2" class="input" maxlength="1000"></textarea>
            </div>
        </div>

        <div class="card space-y-2">
            <table class="w-full text-base">
                <tbody id="totales"></tbody>
            </table>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Sale como Tiquete Electrónico; el comprobante se emite cuando la guía se sincroniza.
            </p>
            <button type="submit" id="btn-guardar" class="btn-primary w-full">Guardar y imprimir comprobante provisional</button>
        </div>
    </form>
</main>

{{-- Plantilla de un bulto: el JS la clona. Vive en el HTML y no en el JS para
     que Tailwind vea sus clases al compilar. --}}
<template id="tpl-bulto">
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-3" data-bulto>
        <div class="flex items-center justify-between">
            <span class="font-medium" data-numero></span>
            <button type="button" class="text-sm text-red-600 dark:text-red-400 hover:underline" data-quitar>Quitar</button>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
            <div class="col-span-2">
                <label class="label">Tipo</label>
                <select class="input" data-campo="package_type_id"></select>
            </div>
            <div><label class="label">Peso (kg)</label><input type="number" min="0" step="0.01" class="input" data-campo="weight"></div>
            <div><label class="label">Largo (cm)</label><input type="number" min="0" step="0.1" class="input" data-campo="length_cm"></div>
            <div><label class="label">Ancho (cm)</label><input type="number" min="0" step="0.1" class="input" data-campo="width_cm"></div>
            <div><label class="label">Alto (cm)</label><input type="number" min="0" step="0.1" class="input" data-campo="height_cm"></div>
            <div class="col-span-2 sm:col-span-4"><label class="label">Descripción</label><input type="text" maxlength="255" class="input" data-campo="description"></div>
            <div class="col-span-2">
                <label class="label">Precio</label>
                <input type="number" min="0" step="0.01" class="input" data-campo="price">
                <p class="text-xs text-gray-500 mt-1" data-cotizacion></p>
            </div>
        </div>
    </div>
</template>

<template id="tpl-fila">
    <li class="py-2 flex items-start justify-between gap-3 flex-wrap">
        <div>
            <div class="font-medium" data-titulo></div>
            <div class="text-sm text-gray-500 dark:text-gray-400" data-detalle></div>
        </div>
        <div class="flex items-center gap-3 text-sm" data-acciones></div>
    </li>
</template>
<template id="tpl-link"><a class="font-semibold text-brand-700 dark:text-brand-300 underline" target="_blank" rel="noopener"></a></template>
<template id="tpl-boton"><button type="button" class="font-semibold text-gray-700 dark:text-gray-200 underline"></button></template>
<template id="tpl-fila-total"><tr><td class="py-1" data-concepto></td><td class="py-1 text-right" data-monto></td></tr></template>

<script>
(function () {
    'use strict';

    var O = window.EncOffline;
    var snap = O.snapshot();
    var $ = function (id) { return document.getElementById(id); };
    var r2 = function (n) { return Math.round((Number(n) + Number.EPSILON) * 100) / 100; };
    var num = function (v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; };
    var colones = function (n) {
        return '₡' + Number(n || 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    /* ------------------------------ red -------------------------------- */

    var enLinea = false, okSeguidos = 0;

    function pintarRed() {
        var e = $('estado-red');
        e.className = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-sm font-medium '
            + (enLinea ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'
                       : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300');
        e.innerHTML = '<span class="w-1.5 h-1.5 rounded-full ' + (enLinea ? 'bg-green-500' : 'bg-red-500') + '"></span> '
            + (enLinea ? 'Con conexión' : 'Sin conexión');
        $('aviso-volvio').classList.toggle('hidden', !enLinea);
    }

    // No se redirige solo al volver la red: el cajero puede estar a mitad de
    // una guía. Se avisa y se sube la cola.
    function latir() {
        var ctrl = new AbortController();
        var t = setTimeout(function () { ctrl.abort(); }, 5000);
        fetch('/__ping', { cache: 'no-store', signal: ctrl.signal })
            .then(function () {
                okSeguidos++;
                if (okSeguidos >= 2 && !enLinea) { enLinea = true; pintarRed(); subir(); O.refrescarSnapshot(); }
            })
            .catch(function () { okSeguidos = 0; if (enLinea) { enLinea = false; pintarRed(); } })
            .then(function () { clearTimeout(t); });
    }

    function volver() {
        sessionStorage.setItem('enc_vuelta', String(Date.now()));
        location.href = '/invoices-create';
    }
    $('btn-volver').addEventListener('click', volver);
    document.querySelector('[data-volver]').addEventListener('click', volver);

    /* ---------------------------- mensajes ------------------------------ */

    function mensaje(texto, tipo) {
        var m = $('mensaje');
        m.textContent = texto;
        m.className = 'p-4 rounded-lg border ' + (tipo === 'error'
            ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200'
            : 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200');
        m.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function error(campo, texto) {
        var p = document.querySelector('[data-error="' + campo + '"]');
        if (p) { p.textContent = texto; p.classList.remove('hidden'); }
    }

    function limpiarErrores() {
        document.querySelectorAll('[data-error]').forEach(function (p) { p.classList.add('hidden'); });
        $('mensaje').classList.add('hidden');
    }

    /* --------------------------- pendientes ----------------------------- */

    function fila(titulo, detalle, acciones) {
        var li = $('tpl-fila').content.firstElementChild.cloneNode(true);
        li.querySelector('[data-titulo]').textContent = titulo;
        li.querySelector('[data-detalle]').textContent = detalle;
        acciones.forEach(function (a) { li.querySelector('[data-acciones]').appendChild(a); });
        return li;
    }

    function link(texto, href) {
        var a = $('tpl-link').content.firstElementChild.cloneNode(true);
        a.textContent = texto; a.href = href;
        return a;
    }

    function boton(texto, alClic) {
        var b = $('tpl-boton').content.firstElementChild.cloneNode(true);
        b.textContent = texto; b.addEventListener('click', alClic);
        return b;
    }

    function pintarPendientes() {
        var listas = O.listas(), cola = O.cola(), fallidas = O.fallidas();

        $('pendientes').classList.toggle('hidden', !listas.length && !cola.length && !fallidas.length);
        $('bloque-listas').classList.toggle('hidden', !listas.length);
        $('bloque-cola').classList.toggle('hidden', !cola.length);
        $('bloque-fallidas').classList.toggle('hidden', !fallidas.length);

        var ul = $('lista-listas'); ul.innerHTML = '';
        listas.forEach(function (g) {
            ul.appendChild(fila(g.code + ' · ' + (g.destinatario || ''),
                'Comprobante provisional ' + (g.referencia || '') + (g.esperando_caja ? ' · pendiente de pago en caja' : ''),
                [link('Imprimir etiqueta', g.etiqueta), boton('Ya la pegué', function () { O.marcarEtiquetaImpresa(g.client_uuid); pintarPendientes(); })]));
        });

        ul = $('lista-cola'); ul.innerHTML = '';
        cola.forEach(function (g) {
            ul.appendChild(fila((g.offline_reference || '') + ' · ' + g.recipient_name + ' · ' + colones(g.total),
                g.ultimo_error ? 'Último intento: ' + g.ultimo_error : 'Se sube sola al volver la conexión.', []));
        });

        ul = $('lista-fallidas'); ul.innerHTML = '';
        fallidas.forEach(function (g) {
            ul.appendChild(fila((g.offline_reference || '') + ' · ' + g.recipient_name + ' · ' + colones(g.total),
                'Motivo: ' + (g.motivo || '—'),
                [boton('Reintentar', function () { O.reintentarFallida(g.client_uuid); pintarPendientes(); subir(); }),
                 boton('Descartar', function () {
                     if (confirm('¿Descartar ' + g.offline_reference + '? No se va a registrar en el sistema.')) {
                         O.descartarFallida(g.client_uuid); pintarPendientes();
                     }
                 })]));
        });
    }

    function subir() {
        O.sincronizar().then(function (r) {
            pintarPendientes();
            // Siguiente tanda solo si esta avanzó: con guías en `error` la cola
            // nunca se vacía y reintentar sin pausa martillaría al servidor.
            if (r && r.subidas > 0 && r.pendientes > 0) setTimeout(subir, 1500);
        });
    }
    $('btn-sincronizar').addEventListener('click', subir);
    window.addEventListener('storage', pintarPendientes);

    /* ---------------------------- snapshot ------------------------------ */

    if (!snap || !Array.isArray(snap.sedes)) {
        $('sin-snapshot').classList.remove('hidden');
        $('form').classList.add('hidden');
        pintarPendientes();
        latir(); setInterval(latir, 5000);
        return;
    }

    var at = O.snapshotAt();
    $('snap-info').textContent = (snap.usuario ? snap.usuario.name + ' · ' : '')
        + 'Tarifas del ' + (at ? new Date(at).toLocaleString('es-CR') : '—');

    var porId = function (lista, id) { return (lista || []).filter(function (x) { return String(x.id) === String(id); })[0]; };
    var origen = porId(snap.sedes, snap.usuario.branch_id);
    $('origen').value = origen ? origen.name : '—';

    function opciones(select, items, vacio) {
        select.innerHTML = '';
        if (vacio !== undefined) { var o = document.createElement('option'); o.value = ''; o.textContent = vacio; select.appendChild(o); }
        items.forEach(function (it) {
            var op = document.createElement('option');
            op.value = it[0]; op.textContent = it[1];
            select.appendChild(op);
        });
    }

    opciones($('destino'), snap.sedes.filter(function (s) { return s.id !== snap.usuario.branch_id; })
        .map(function (s) { return [s.id, s.name]; }), 'Elegí la sede de destino');
    opciones($('ruta'), (snap.rutas || []).filter(function (r) { return r.origin === snap.usuario.branch_id; })
        .map(function (r) { return [r.id, r.name]; }), '— Sin ruta —');
    opciones($('tipo-envio'), Object.keys(snap.tipos_envio || {}).map(function (k) { return [k, snap.tipos_envio[k]]; }));
    opciones($('medio'), Object.keys(snap.medios_pago || {}).map(function (k) { return [k, snap.medios_pago[k]]; }));

    var clientes = snap.clientes_credito || [];
    if (!clientes.length) $('opcion-credito').classList.add('hidden');
    opciones($('cliente-credito'), clientes.map(function (c) { return [c.id, c.name + (c.identification ? ' · ' + c.identification : '')]; }), 'Elegí el cliente');

    if (snap.clave_descuento) $('bloque-clave').classList.remove('hidden');

    /* ----------------------------- bultos ------------------------------- */

    // Último precio que propuso el tarifario por bulto: solo ese se pisa al
    // recotizar. Uno digitado a mano manda (igual que InvoiceForm::cotizar).
    function agregarBulto() {
        var div = $('tpl-bulto').content.firstElementChild.cloneNode(true);
        var sel = div.querySelector('[data-campo="package_type_id"]');
        opciones(sel, (snap.tipos_bulto || []).map(function (t) { return [t.id, t.name]; }));
        div.querySelector('[data-quitar]').addEventListener('click', function () {
            if (document.querySelectorAll('[data-bulto]').length > 1) { div.remove(); numerar(); recalcular(); }
        });
        div.addEventListener('input', function (e) {
            var campo = e.target.getAttribute('data-campo');
            if (['weight', 'length_cm', 'width_cm', 'height_cm'].indexOf(campo) >= 0) cotizar();
            recalcular();
        });
        $('bultos').appendChild(div);
        numerar();
        cotizar();
    }

    function numerar() {
        document.querySelectorAll('[data-bulto]').forEach(function (b, i) {
            b.querySelector('[data-numero]').textContent = 'Bulto ' + (i + 1);
        });
    }

    function bultos() {
        return Array.prototype.map.call(document.querySelectorAll('[data-bulto]'), function (b) {
            var v = function (c) { return b.querySelector('[data-campo="' + c + '"]').value.trim(); };
            var n = function (c) { var x = v(c); return x === '' ? null : num(x); };
            return {
                package_type_id: parseInt(v('package_type_id'), 10) || null,
                weight: n('weight'), length_cm: n('length_cm'), width_cm: n('width_cm'), height_cm: n('height_cm'),
                description: v('description') || null,
                price: v('price') === '' ? null : num(v('price')),
            };
        });
    }

    /* ---------------------------- tarifario ----------------------------- */

    // Misma regla que App\Services\Tarifario. OJO con el desempate: entre dos
    // igual de específicas gana la de min_weight MÁS BAJO (banda más ancha),
    // que es lo que hace sortByDesc([especificidad, -min_weight]) en el
    // servidor aunque su comentario diga lo contrario. Se copia el
    // comportamiento, no el comentario: si no, el precio sugerido sin conexión
    // sería distinto del de en línea.
    function pesoVolumetrico(l, a, h) {
        var d = num(snap.divisor_volumetrico);
        if (!l || !a || !h || d <= 0) return 0;
        return r2(l * a * h / d);
    }

    function tarifa(destino, peso, tipo) {
        var o = snap.usuario.branch_id;
        var esp = function (t) { return (t.origin ? 4 : 0) + (t.destination ? 2 : 0) + (t.shipment ? 1 : 0); };

        return (snap.tarifas || [])
            .filter(function (t) {
                return (t.origin === null || t.origin === o)
                    && (t.destination === null || String(t.destination) === String(destino))
                    && (t.shipment === null || t.shipment === tipo)
                    && peso >= t.min && (t.max === null || peso < t.max);
            })
            .sort(function (x, y) { return (esp(y) - esp(x)) || (x.min - y.min); })[0] || null;
    }

    function precioPara(t, peso) {
        if (t.max !== null || t.extra_kg <= 0) return r2(t.price);
        return r2(t.price + Math.max(0, Math.ceil(peso - t.min)) * t.extra_kg);
    }

    function cotizar() {
        var destino = $('destino').value, tipo = $('tipo-envio').value;

        document.querySelectorAll('[data-bulto]').forEach(function (b) {
            var g = function (c) { return num(b.querySelector('[data-campo="' + c + '"]').value); };
            var facturable = r2(Math.max(g('weight'), pesoVolumetrico(g('length_cm'), g('width_cm'), g('height_cm'))));
            var info = b.querySelector('[data-cotizacion]');
            var precio = b.querySelector('[data-campo="price"]');

            var t = destino ? tarifa(destino, facturable, tipo) : null;
            if (!t) {
                info.textContent = destino ? 'Sin tarifa para esta ruta y peso: digitá el precio.' : '';
                return;
            }

            var sugerido = precioPara(t, facturable);
            var anterior = b.getAttribute('data-sugerido');
            var loPusoElSistema = precio.value === '' || (anterior !== null && Math.abs(num(precio.value) - num(anterior)) < 0.01);

            if (loPusoElSistema) {
                precio.value = sugerido;
                b.setAttribute('data-sugerido', String(sugerido));
            }
            info.textContent = 'Tarifa: ' + colones(sugerido) + ' (' + facturable + ' kg facturables)';
        });
    }

    /* ----------------------------- totales ------------------------------ */

    function cobro() { return document.querySelector('input[name="cobro"]:checked').value; }

    // Mismo cálculo que InvoiceForm: seguro y domicilio entran ANTES del
    // impuesto. El servidor comprueba que cuadre (GuiasOfflineController::noCuadra).
    function calcular() {
        var subtotal = r2(bultos().reduce(function (s, b) { return s + (b.price || 0); }, 0));
        var declarado = num($('declared_value').value);
        var pct = num(snap.porcentaje_seguro);
        var seguro = declarado > 0 && pct > 0 ? r2(declarado * pct / 100) : 0;
        var domicilio = $('home_delivery').checked ? Math.max(0, num($('home_delivery_fee').value)) : 0;
        var descuento = num($('discount_amount').value);
        var base = r2(subtotal + seguro + domicilio - descuento);
        var impuestos = snap.impuestos || [];
        var porcentaje = impuestos.reduce(function (s, t) { return s + num(t.percent); }, 0);
        var impuesto = r2(base * porcentaje / 100);

        return { subtotal: subtotal, seguro: seguro, domicilio: domicilio, descuento: descuento,
                 base: base, impuestos: impuestos, impuesto: impuesto, total: r2(base + impuesto) };
    }

    function recalcular() {
        var c = calcular();
        var tb = $('totales'); tb.innerHTML = '';
        var linea = function (concepto, monto, fuerte) {
            var tr = $('tpl-fila-total').content.firstElementChild.cloneNode(true);
            tr.querySelector('[data-concepto]').textContent = concepto;
            tr.querySelector('[data-monto]').textContent = monto;
            if (fuerte) tr.classList.add('font-bold', 'text-lg');
            tb.appendChild(tr);
        };
        linea('Bultos', colones(c.subtotal));
        if (c.seguro) linea('Seguro', colones(c.seguro));
        if (c.domicilio) linea('Entrega a domicilio', colones(c.domicilio));
        if (c.descuento) linea('Descuento', '-' + colones(c.descuento));
        linea('Impuesto', colones(c.impuesto));
        linea('TOTAL', colones(c.total), true);

        $('seguro-info').textContent = c.seguro ? 'Seguro: ' + colones(c.seguro) : '';
        pintarCredito();
    }

    /* ------------------------------ crédito ----------------------------- */

    // Saldo del snapshot MÁS lo que este equipo ya le cargó sin conexión: si
    // no, dos guías seguidas pasarían cada una por separado y juntas no.
    function disponible(cliente) {
        var enCola = O.cola().filter(function (g) { return g.cobro === 'credit' && String(g.sender_customer_id) === String(cliente.id); })
            .reduce(function (s, g) { return s + num(g.total); }, 0);
        return r2(num(cliente.limite) - num(cliente.saldo) - enCola);
    }

    function pintarCredito() {
        var c = porId(clientes, $('cliente-credito').value);
        $('credito-info').textContent = c
            ? (num(c.limite) > 0 ? 'Disponible aprox.: ' + colones(Math.max(0, disponible(c))) + ' de ' + colones(c.limite) : 'Sin límite configurado.')
              + ' Se vuelve a comprobar al sincronizar.'
            : '';
    }

    function pintarCobro() {
        var c = cobro();
        $('bloque-credito').classList.toggle('hidden', c !== 'credit');
        $('bloque-medio').classList.toggle('hidden', c !== 'prepaid');

        var aviso = '';
        if (c === 'prepaid' && !snap.usuario.puede_cobrar) aviso = 'Tu usuario no cobra: la guía queda pendiente de pago en caja.';
        else if (c === 'prepaid' && !snap.usuario.caja_abierta) aviso = 'La última vez que hubo conexión no tenías caja abierta. Para subir esta guía vas a tener que abrirla.';
        $('aviso-caja').textContent = aviso;
        $('aviso-caja').classList.toggle('hidden', !aviso);
    }

    document.querySelectorAll('input[name="cobro"]').forEach(function (r) { r.addEventListener('change', pintarCobro); });

    $('cliente-credito').addEventListener('change', function () {
        var c = porId(clientes, this.value);
        if (c) {
            $('sender_name').value = c.name || '';
            $('sender_phone').value = c.phone || '';
            $('sender_identification').value = c.identification || '';
        }
        pintarCredito();
    });

    $('ruta').addEventListener('change', function () {
        var r = porId(snap.rutas, this.value);
        if (r) { $('destino').value = r.destination; cotizar(); recalcular(); }
    });
    $('destino').addEventListener('change', function () {
        // La ruta tiene que decir la verdad: si ya no coincide se suelta.
        var r = porId(snap.rutas, $('ruta').value);
        if (r && String(r.destination) !== this.value) $('ruta').value = '';
        cotizar(); recalcular();
    });
    $('tipo-envio').addEventListener('change', function () { cotizar(); recalcular(); });
    $('home_delivery').addEventListener('change', function () {
        $('bloque-domicilio').classList.toggle('hidden', !this.checked);
        recalcular();
    });
    ['declared_value', 'home_delivery_fee', 'discount_amount'].forEach(function (id) { $(id).addEventListener('input', recalcular); });
    $('btn-agregar').addEventListener('click', function () { agregarBulto(); recalcular(); });

    /* ------------------------------ clave ------------------------------- */

    var hexABytes = function (hex) { return Uint8Array.from(hex.match(/.{2}/g).map(function (b) { return parseInt(b, 16); })); };
    var bytesAHex = function (b) { return Array.from(b).map(function (x) { return x.toString(16).padStart(2, '0'); }).join(''); };

    // Recalcula el PBKDF2 de la clave digitada y lo compara con el verificador
    // del snapshot (CompanySetting::verificadorDeDescuento). Tarda un momento a
    // propósito: son 210.000 iteraciones.
    function claveValida(clave) {
        var v = snap.clave_descuento;
        if (!v) return Promise.resolve(true);
        if (!clave || !window.crypto || !crypto.subtle) return Promise.resolve(false);
        return crypto.subtle.importKey('raw', new TextEncoder().encode(clave), 'PBKDF2', false, ['deriveBits'])
            .then(function (key) {
                return crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: hexABytes(v.salt), iterations: v.iterations }, key, 256);
            })
            .then(function (bits) { return bytesAHex(new Uint8Array(bits)) === v.hash; })
            .catch(function () { return false; });
    }

    /* ------------------------------ guardar ----------------------------- */

    function validar(c) {
        var ok = true;
        var falla = function (campo, t) { error(campo, t); ok = false; };

        if (!$('destino').value) falla('destino', 'Elegí la sede de destino.');
        if (!$('sender_name').value.trim()) falla('sender_name', 'El nombre del remitente es obligatorio.');
        if (!$('recipient_name').value.trim()) falla('recipient_name', 'El nombre del destinatario es obligatorio.');

        var correo = $('recipient_email').value.trim();
        if (correo && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) falla('recipient_email', 'El correo no es válido.');

        if ($('home_delivery').checked && !$('delivery_address').value.trim()) {
            falla('delivery_address', 'Para entregar a domicilio hace falta la dirección exacta.');
        }

        var bs = bultos();
        if (!bs.length || bs.some(function (b) { return !b.package_type_id || b.price === null || b.price < 0; })) {
            falla('items', 'Cada bulto necesita tipo y precio.');
        }

        if (c.base < 0) falla('discount', 'El descuento es mayor que el cobro.');

        if (cobro() === 'credit') {
            var cli = porId(clientes, $('cliente-credito').value);
            if (!cli) falla('credito', 'Para dejar la guía a crédito elegí el cliente: el saldo se le carga a alguien.');
            else if (num(cli.limite) > 0 && c.total > disponible(cli)) {
                falla('credito', '«' + cli.name + '» no tiene crédito suficiente: le quedan ' + colones(Math.max(0, disponible(cli)))
                    + ' y esta guía suma ' + colones(c.total) + '.');
            }
        }

        return ok;
    }

    $('form').addEventListener('submit', function (e) {
        e.preventDefault();
        limpiarErrores();

        var c = calcular();
        if (!validar(c)) { mensaje('Revisá los campos marcados.', 'error'); return; }

        var boton = $('btn-guardar');
        boton.disabled = true;

        var clave = c.descuento > 0 ? claveValida($('discount_code').value) : Promise.resolve(true);

        clave.then(function (valida) {
            if (!valida) {
                error('discount', 'La clave de autorización no es correcta. Sin ella no se puede aplicar un descuento.');
                mensaje('Revisá los campos marcados.', 'error');
                return;
            }

            var cli = cobro() === 'credit' ? porId(clientes, $('cliente-credito').value) : null;
            var guia = {
                client_uuid: O.uuid(),
                offline_reference: O.siguienteReferencia(origen ? origen.prefix : ''),
                created_at: new Date().toISOString(),
                sold_by: snap.usuario.id,
                pickup_branch_id: snap.usuario.branch_id,
                delivery_branch_id: parseInt($('destino').value, 10),
                shipping_route_id: $('ruta').value ? parseInt($('ruta').value, 10) : null,
                shipment_type: $('tipo-envio').value || null,
                sender_name: $('sender_name').value.trim(),
                sender_phone: $('sender_phone').value.trim() || null,
                sender_identification: $('sender_identification').value.trim() || null,
                sender_customer_id: cli ? cli.id : null,
                recipient_name: $('recipient_name').value.trim(),
                recipient_phone: $('recipient_phone').value.trim() || null,
                recipient_identification: $('recipient_identification').value.replace(/\D/g, '') || null,
                recipient_email: $('recipient_email').value.trim() || null,
                declared_value: num($('declared_value').value),
                insurance_fee: c.seguro,
                home_delivery: $('home_delivery').checked,
                delivery_address: $('home_delivery').checked ? $('delivery_address').value.trim() : null,
                home_delivery_fee: c.domicilio,
                discount_amount: c.descuento,
                notes: $('notes').value.trim() || null,
                cobro: cobro(),
                payment_method: cobro() === 'prepaid' ? $('medio').value : 'cash',
                items: bultos(),
                taxes: c.impuestos.map(function (t) { return { id: t.id, percent: t.percent }; }),
                subtotal: c.subtotal,
                tax_total: c.impuesto,
                total: c.total,
            };

            if (!O.encolar(guia)) {
                mensaje('No se pudo guardar en este equipo (almacenamiento lleno). La guía NO quedó registrada.', 'error');
                return;
            }

            imprimirProvisional(guia, c, cli);
            mensaje('Guía ' + guia.offline_reference + ' guardada en este equipo. Se sube sola al volver la conexión; '
                + 'ahí se imprime la etiqueta del paquete.');
            reiniciar();
            pintarPendientes();
            if (enLinea) subir();
        }).then(function () { boton.disabled = false; });
    });

    function reiniciar() {
        $('form').reset();
        $('bultos').innerHTML = '';
        $('bloque-domicilio').classList.add('hidden');
        agregarBulto();
        pintarCobro();
        recalcular();
    }

    /* ------------------------ comprobante provisional ------------------- */

    // Se imprime en un iframe y no en una ventana nueva: sin conexión, un
    // bloqueador de ventanas emergentes dejaría al cliente sin papel.
    // Estilos en línea: este documento no tiene el CSS de la aplicación.
    function imprimirProvisional(g, c, cli) {
        var p = snap.papel || { ancho: 80, matriz: false, ancho_util: 80 };
        var e = function (t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };
        var fila = function (a, b) { return '<tr><td>' + e(a) + '</td><td style="text-align:right">' + e(b) + '</td></tr>'; };
        var destino = porId(snap.sedes, g.delivery_branch_id);
        var tipos = {}; (snap.tipos_bulto || []).forEach(function (t) { tipos[t.id] = t.name; });
        var estado = g.cobro === 'collect' ? 'POR COBRAR AL ENTREGAR'
            : g.cobro === 'credit' ? 'A CRÉDITO'
            : !snap.usuario.puede_cobrar ? 'PENDIENTE DE PAGO EN CAJA'
            : 'PAGADO · ' + ((snap.medios_pago || {})[g.payment_method] || '');

        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            + '@page{size:' + p.ancho + 'mm auto;margin:0}'
            + 'body{width:' + p.ancho_util + 'mm;margin:0 auto;padding:3mm 0;font-family:' + (p.matriz ? 'Tahoma,Arial,sans-serif;font-weight:bold' : '"Courier New",monospace') + ';font-size:' + (p.matriz ? '13px' : '11px') + ';color:#000}'
            + '.c{text-align:center}.r{border-top:1px dashed #000;margin:2mm 0}table{width:100%;border-collapse:collapse}'
            + '.g{font-size:16px;font-weight:bold}.box{border:2px solid #000;padding:1.5mm;font-weight:bold;text-align:center;margin:2mm 0}'
            + '</style></head><body>'
            + '<div class="c g">' + e(snap.empresa.nombre) + '</div>'
            + (snap.empresa.cedula ? '<div class="c">Céd. ' + e(snap.empresa.cedula) + '</div>' : '')
            + '<div class="box">COMPROBANTE PROVISIONAL<br>' + e(g.offline_reference) + '</div>'
            + '<div class="c">' + e(new Date(g.created_at).toLocaleString('es-CR')) + '</div>'
            + '<div class="c" style="font-size:9px">Registrado sin conexión. El código de guía definitivo<br>se asigna al sincronizar.</div>'
            + '<div class="r"></div>'
            + '<div><b>Remitente</b><br>' + e(g.sender_name) + (g.sender_phone ? '<br>' + e(g.sender_phone) : '') + '<br>' + e(origen ? origen.name : '') + '</div>'
            + '<div class="r"></div>'
            + '<div><b>Destinatario</b><br>' + e(g.recipient_name) + (g.recipient_phone ? '<br>' + e(g.recipient_phone) : '') + '<br>' + e(destino ? destino.name : '')
            + (g.home_delivery ? '<br>A domicilio: ' + e(g.delivery_address) : '') + '</div>'
            + '<div class="r"></div>'
            + '<table>' + g.items.map(function (b, i) { return fila((i + 1) + '. ' + (tipos[b.package_type_id] || 'Bulto') + (b.description ? ' · ' + b.description : ''), b.weight ? b.weight + ' kg' : ''); }).join('') + '</table>'
            + '<div class="r"></div>'
            + '<table>' + fila('Bultos', colones(c.subtotal))
            + (c.seguro ? fila('Seguro', colones(c.seguro)) : '')
            + (c.domicilio ? fila('Domicilio', colones(c.domicilio)) : '')
            + (c.descuento ? fila('Descuento', '-' + colones(c.descuento)) : '')
            + fila('Impuesto', colones(c.impuesto))
            + '<tr class="g"><td>TOTAL</td><td style="text-align:right">' + e(colones(c.total)) + '</td></tr></table>'
            + '<div class="box">' + e(estado) + (cli ? '<br>' + e(cli.name) : '') + '</div>'
            + '<div class="c" style="margin-top:3mm">Consérvelo: con este número se ubica su encomienda.</div>'
            + '</body></html>';

        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
        document.body.appendChild(iframe);
        var doc = iframe.contentWindow.document;
        doc.open(); doc.write(html); doc.close();
        setTimeout(function () {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
            setTimeout(function () { iframe.remove(); }, 60000);
        }, 250);
    }

    /* ------------------------------ arranque ---------------------------- */

    agregarBulto();
    pintarCobro();
    recalcular();
    pintarPendientes();
    latir();
    setInterval(latir, 5000);
    setInterval(pintarPendientes, 10000);
})();
</script>
</body>
</html>
