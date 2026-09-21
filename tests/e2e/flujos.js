import { expect } from '@playwright/test';
import { crearEmpresa, entrar, entrarComoSuperadmin, esperarLivewire, salir, visitar } from './apoyo.js';

/**
 * Los movimientos de la operación diaria, en un solo lugar.
 *
 * Abrir la caja, recibir un paquete, armar el camión y entregar son los pasos
 * que todos los escenarios repiten con variantes. Están acá y no copiados en
 * cada archivo porque son los que más van a cambiar —un campo nuevo en la
 * recepción, un botón que se mueve— y conviene un solo lugar donde arreglarlo.
 *
 * Ver PLAN-PRUEBAS-OPERACION.md para qué comprueba cada escenario y por qué.
 */

/** Los billetes y monedas que trae toda empresa nueva, del mayor al menor. */
export const DENOMINACIONES = [20000, 10000, 5000, 2000, 1000, 500, 100, 50, 25, 10, 5];

/**
 * «₡29,000.00» → 29000
 *
 * La pantalla muestra montos formateados y las aserciones necesitan números:
 * comparar cadenas obligaría a escribir el separador de miles en cada prueba y
 * a reescribirlas todas el día que cambie el formato.
 */
export function aNumero(texto) {
  return Number(String(texto).replace(/[^\d,.-]/g, '').replace(/,/g, ''));
}

/** El monto que muestra un elemento marcado con data-test. */
export async function monto(page, marca) {
  return aNumero(await page.locator(`[data-test="${marca}"]`).innerText());
}

/**
 * Elige una opción de un <select> por su texto, sin exigir que coincida entero.
 *
 * Hace falta porque cada pantalla rotula las sedes a su manera: el formulario
 * de guía muestra el nombre («Sede Central») y el tarifario solo el prefijo
 * («SJO»). Buscar por coincidencia parcial deja que las pruebas hablen de la
 * sede sin saber cómo la escribe cada pantalla.
 */
export async function elegirOpcion(page, selector, texto) {
  const select = page.locator(selector);

  // Esperar a que exista de verdad: el formulario suele abrirse con una vuelta
  // al servidor, y enumerar las opciones antes devuelve una lista vacía y un
  // «no hay ninguna opción» que apunta al lado equivocado del problema.
  await select.waitFor({ state: 'visible' });
  await expect(select.locator('option')).not.toHaveCount(0);

  const opciones = await select.locator('option').all();

  for (const opcion of opciones) {
    const etiqueta = (await opcion.innerText()).trim();

    if (etiqueta.includes(texto)) {
      await select.selectOption(await opcion.getAttribute('value'));

      return;
    }
  }

  throw new Error(`No hay ninguna opción que contenga «${texto}» en ${selector}.`);
}

/**
 * Una empresa recién creada, con dos sedes y su administrador ya adentro.
 *
 * Dos sedes porque una encomienda es un traslado: el sistema no deja que origen
 * y destino sean la misma. La empresa nace con una sola.
 *
 * @returns {Promise<{nombre:string, correo:string, clave:string, slug:string, origen:string, destino:string}>}
 */
export async function empresaOperando(page, { origen = 'Sede Central', destino = 'Limón' } = {}) {
  await entrarComoSuperadmin(page);
  const empresa = await crearEmpresa(page, { prefijo: 'SJO' });

  await salir(page);
  await entrar(page, empresa.correo, empresa.clave);

  // La primera sede la crea el alta con el nombre que se le puso; se renombra
  // acá para que los escenarios no dependan de ese detalle.
  await visitar(page, '/branches');
  await page.locator('tbody tr', { hasText: 'Sede de prueba' }).locator('button:has-text("Editar")').click();
  await page.fill('[wire\\:model="name"]', origen);
  await page.click('button:has-text("Guardar")');
  await expect(page.locator('tbody')).toContainText(origen);

  await crearSede(page, { nombre: destino, prefijo: 'LIM', sucursal: '002' });

  // Los prefijos van aparte porque el tarifario rotula las sedes con ellos y
  // no con el nombre.
  return { ...empresa, origen, destino, prefijoOrigen: 'SJO', prefijoDestino: 'LIM' };
}

export async function crearSede(page, { nombre, prefijo, sucursal }) {
  await visitar(page, '/branches');
  await page.click('button:has-text("Nueva sucursal")');
  await page.fill('[wire\\:model="name"]', nombre);
  await page.fill('[wire\\:model="prefix"]', prefijo);
  await page.fill('[wire\\:model="sucursal_code"]', sucursal);
  await page.fill('[wire\\:model="terminal_code"]', '00001');
  await page.click('button:has-text("Guardar")');
  await expect(page.locator('tbody')).toContainText(nombre);
}

/* ────────────────────────────── Caja ────────────────────────────── */

/** Abre el turno de la caja de una sede con su fondo inicial. */
export async function abrirCaja(page, { sede, fondo }) {
  await visitar(page, '/caja');
  await elegirCaja(page, sede);

  await page.fill('[data-test="fondo-inicial"]', String(fondo));
  await page.click('button:has-text("Abrir caja")');

  await expect(page.locator('body')).toContainText('Turno abierto');
}

/**
 * Elige la caja de una sede en el selector.
 *
 * El selector agrupa por sede y todas las cajas se llaman «Caja principal», así
 * que hay que buscar la opción dentro del grupo de esa sede.
 */
export async function elegirCaja(page, sede) {
  await esperarLivewire(page);

  const valor = await page
    .locator(`optgroup[label="${sede}"] option`)
    .first()
    .getAttribute('value');

  await page.locator('[data-test="caja-selector"]').selectOption(valor);
  await esperarLivewire(page);
}

/** Entrada o salida de efectivo. Toda una u otra necesita motivo. */
export async function movimientoDeCaja(page, { tipo, monto: importe, motivo }) {
  await visitar(page, '/caja');
  await page.locator('[wire\\:model="movementType"]').selectOption(tipo);
  await page.fill('[wire\\:model="movementAmount"]', String(importe));
  await page.fill('[wire\\:model="movementReason"]', motivo);
  await page.click('button:has-text("Registrar")');
  await expect(page.locator('body')).toContainText('registrad');
}

/** Lo que el sistema dice que debería haber en la gaveta. */
export async function efectivoEsperado(page) {
  await visitar(page, '/caja');

  return monto(page, 'efectivo-esperado');
}

/**
 * Cierra el turno contando billetes.
 *
 * `conteo` es {denominación: cantidad}. Con `cuadrado: true` se arma solo el
 * conteo que da exactamente lo esperado, que es el caso normal y el que más se
 * repite.
 */
export async function cerrarCaja(page, { conteo = null, cuadrado = false, nota = '' } = {}) {
  await visitar(page, '/caja');

  const esperado = await monto(page, 'efectivo-esperado');

  await page.click('button:has-text("Cerrar turno y hacer arqueo")');
  await expect(page.locator('[data-test="arqueo-esperado"]')).toBeVisible();

  const aContar = cuadrado ? desglosar(esperado) : conteo;

  for (const [valor, cantidad] of Object.entries(aContar ?? {})) {
    await page.fill(`[data-denominacion="${valor}"]`, String(cantidad));
  }

  // El conteo se recalcula en el servidor con cada tecla: esperar el resultado
  // evita leer un total a medio actualizar.
  const contado = Object.entries(aContar ?? {})
    .reduce((suma, [valor, cantidad]) => suma + Number(valor) * Number(cantidad), 0);
  await expect(page.locator('[data-test="arqueo-contado"]')).toContainText(
    contado.toLocaleString('en-US', { minimumFractionDigits: 2 })
  );

  if (nota) {
    await page.fill('[wire\\:model="closingNote"]', nota);
  }

  const diferencia = await monto(page, 'arqueo-diferencia');

  // wire:confirm abre un diálogo del navegador; sin esto el clic queda colgado.
  page.once('dialog', (d) => d.accept());
  await page.click('button:has-text("Confirmar cierre")');

  // El aviso es distinto según cuadre: «Turno cerrado y cuadrado» o el
  // faltante/sobrante con los dos montos. Los dos confirman que cerró.
  await expect(page.locator('[data-test="aviso-caja"]')).toContainText(
    /Turno cerrado y cuadrado|Faltante de|Sobrante de/
  );

  return { esperado, contado, diferencia };
}

/**
 * El conteo de billetes que suma exactamente un monto.
 *
 * De mayor a menor, como cuenta una persona. Los montos de las pruebas son
 * redondos, así que siempre cierra en cero.
 */
export function desglosar(total) {
  let resto = Math.round(total);
  const conteo = {};

  for (const valor of DENOMINACIONES) {
    const cantidad = Math.floor(resto / valor);

    if (cantidad > 0) {
      conteo[valor] = cantidad;
      resto -= cantidad * valor;
    }
  }

  if (resto !== 0) {
    throw new Error(`No se puede armar ₡${total} con las denominaciones disponibles (sobran ₡${resto}).`);
  }

  return conteo;
}

/**
 * Completa la configuración de la empresa.
 *
 * Llena SIEMPRE la identidad fiscal, aunque la prueba solo quiera cambiar el
 * porcentaje de seguro: la pantalla no guarda nada sin cédula, actividad
 * económica y ubicación, porque Hacienda rechaza el comprobante sin eso. Una
 * empresa recién dada de alta no los tiene —son datos que solo el cliente
 * conoce—, así que hay que ponerlos igual que lo haría el administrador el
 * primer día.
 */
export async function configurarEmpresa(page, { seguro = null, claveDeDescuento = null } = {}) {
  await visitar(page, '/settings/company');

  await page.locator('[wire\\:model="identification_type"]').selectOption('02');
  await page.fill('[wire\\:model="identification_number"]', '3101999888');
  await page.fill('[wire\\:model="activity_code"]', '532000');
  await page.fill('[wire\\:model="province"]', '1');
  await page.fill('[wire\\:model="canton"]', '01');
  await page.fill('[wire\\:model="district"]', '01');

  if (seguro !== null) {
    await page.fill('[wire\\:model="insurance_percent"]', String(seguro));
  }

  if (claveDeDescuento !== null) {
    await page.fill('[wire\\:model="discount_authorization_code"]', claveDeDescuento);
  }

  await page.click('button:has-text("Guardar configuración")');

  // El aviso de éxito es un flash de sesión y Livewire no re-renderiza el
  // layout, así que no aparece hasta recargar. La confirmación que sí sirve es
  // que no quedó ningún error de validación en pantalla.
  await expect(page.locator('.error-text')).toHaveCount(0);
}

/* ────────────────────────────── Guías ────────────────────────────── */

/**
 * Recibe una encomienda.
 *
 * Devuelve el código guía y el total que mostró la pantalla, que es lo que las
 * pruebas comparan contra el arqueo.
 *
 * @returns {Promise<{codigo:string, total:number}>}
 */
export async function crearGuia(page, opciones) {
  const {
    origen,
    destino,
    remitente = 'Marta Solano',
    destinatario = 'Jose Fernandez',
    precio = 3500,
    cobro = 'prepaid',
    medioDePago = null,
    tipoDeEnvio = null,
    tamano = null,
    peso = null,
    dimensiones = null,
    valorDeclarado = null,
    domicilio = null,
    descuento = null,
    conFactura = null,
    sinImpuestos = false,
    bultosExtra = [],
  } = opciones;

  await visitar(page, '/invoices-create');

  await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: origen });
  await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: destino });

  await page.fill('[wire\\:model="sender_name"]', remitente);
  await page.fill('[wire\\:model="recipient_name"]', destinatario);

  if (tipoDeEnvio) {
    await page.locator('[wire\\:model\\.live="shipment_type"]').selectOption(tipoDeEnvio);
  }

  if (valorDeclarado !== null) {
    await page.fill('[wire\\:model\\.live="declared_value"]', String(valorDeclarado));
  }

  if (conFactura) {
    await page.locator('[wire\\:model\\.live="wantsInvoice"]').check();
    await page
      .locator('[wire\\:model="recipient_identification_type"]')
      .selectOption(conFactura.tipo ?? '01');
    await page.fill('[wire\\:model="recipient_identification"]', conFactura.cedula);
  }

  if (domicilio) {
    await page.locator('[wire\\:model\\.live="home_delivery"]').check();
    await page.fill('[wire\\:model="delivery_address"]', domicilio.direccion);
    await page.fill('[wire\\:model\\.live="home_delivery_fee"]', String(domicilio.cargo));
  }

  await llenarBulto(page, 0, { tamano, peso, dimensiones, precio });

  for (const [i, extra] of bultosExtra.entries()) {
    await page.click('button:has-text("Agregar paquete")');
    await llenarBulto(page, i + 1, extra);
  }

  // El IVA viene marcado por defecto. Se destilda cuando la prueba necesita una
  // cuenta redonda y lo que está comprobando no es el cálculo del impuesto.
  if (sinImpuestos) {
    for (const casilla of await page.locator('[wire\\:model\\.live="selectedTaxes"]').all()) {
      if (await casilla.isChecked()) {
        await casilla.uncheck();
      }
    }
  }

  if (descuento !== null) {
    await page.fill('[wire\\:model\\.live="discount_amount"]', String(descuento.monto ?? descuento));

    if (descuento.clave) {
      await page.fill('[wire\\:model="discountCode"]', descuento.clave);
    }
  }

  await page.locator(`[wire\\:model\\.live="cobro"][value="${cobro}"]`).check();

  if (medioDePago) {
    await page.locator('[wire\\:model="payment_method"]').selectOption(medioDePago);
  }

  await page.click('button:has-text("Guardar factura")');
  await expect(page).toHaveURL(/\/invoices\/\d+/);

  // El total se lee del detalle YA GUARDADO y no del resumen del formulario.
  // Ese resumen lo recalcula el servidor con cada tecla: leerlo justo antes de
  // guardar puede agarrar el número de la interacción anterior —o el cero del
  // principio—, y el arqueo termina comparándose contra una cifra que nunca
  // existió. Acá es HTML quieto, y es además lo que quedó en la base.
  const codigo = await page.locator('[data-test="codigo-guia"]').innerText();

  return {
    codigo: codigo.trim(),
    total: await monto(page, 'guia-total'),
    subtotal: await monto(page, 'guia-subtotal'),
  };
}

async function llenarBulto(page, indice, { tamano, peso, dimensiones, precio }) {
  const campo = (nombre, vivo = false) =>
    `[wire\\:model${vivo ? '\\.live' : '\\.blur'}="items.${indice}.${nombre}"]`;

  if (tamano) {
    await page.locator(`[wire\\:model="items.${indice}.size"]`).selectOption(tamano);
  }

  if (peso !== null && peso !== undefined) {
    await page.fill(campo('weight'), String(peso));
  }

  if (dimensiones) {
    await page.fill(campo('length_cm'), String(dimensiones.largo));
    await page.fill(campo('width_cm'), String(dimensiones.ancho));
    await page.fill(campo('height_cm'), String(dimensiones.alto));
  }

  if (precio !== null && precio !== undefined) {
    await page.fill(campo('price', true), String(precio));
  }
}

/** Abre una guía por su código desde el listado. */
export async function abrirGuia(page, codigo) {
  await visitar(page, '/invoices');
  await page.fill('[wire\\:model\\.live\\.debounce\\.400ms="search"]', codigo);
  await page.getByRole('link', { name: codigo, exact: true }).first().click();
  await expect(page.locator('[data-test="codigo-guia"]')).toContainText(codigo);
  await esperarLivewire(page);
}

/** Mueve la guía al estado indicado desde su pantalla. */
export async function cambiarEstado(page, etiqueta) {
  page.once('dialog', (d) => d.accept());
  await page.locator(`button:has-text("${etiqueta}")`).first().click();
  await expect(page.locator('body')).toContainText('Estado actualizado');
}

/* ────────────────────────── Tarifario ────────────────────────── */

/**
 * Crea una tarifa.
 *
 * Los tres «cualquiera» son el corazón del tarifario: dejar origen, destino o
 * tipo sin elegir significa «esta tarifa sirve para todos», y es lo que
 * permite tener una tarifa base sin declarar todas las combinaciones de sedes.
 */
export async function crearTarifa(page, {
  nombre,
  origen = null,
  destino = null,
  tipo = null,
  desde,
  hasta = null,
  precio,
  porKgExtra = null,
}) {
  await visitar(page, '/rates');
  await page.click('button:has-text("Nueva tarifa")');

  await page.fill('[wire\\:model="name"]', nombre);

  if (origen) {
    await elegirOpcion(page, '[wire\\:model="origin_branch_id"]', origen);
  }

  if (destino) {
    await elegirOpcion(page, '[wire\\:model="destination_branch_id"]', destino);
  }

  if (tipo) {
    await page.locator('[wire\\:model="shipment_type"]').selectOption(tipo);
  }

  await page.fill('[wire\\:model="min_weight"]', String(desde));
  await page.fill('[wire\\:model="max_weight"]', hasta === null ? '' : String(hasta));
  await page.fill('[wire\\:model="price"]', String(precio));
  await page.fill('[wire\\:model="price_per_extra_kg"]', String(porKgExtra ?? 0));

  await page.click('button:has-text("Guardar")');
  await expect(page.locator('[data-test="tabla-tarifas"]')).toContainText(nombre);
}

/**
 * Usa el probador de la pantalla de Tarifario.
 *
 * Es la herramienta que responde «¿qué tarifa gana y por qué?» sin tener que
 * crear una guía de mentira para averiguarlo.
 *
 * @returns {Promise<{real:number, volumetrico:number, facturable:number, precio:?number, tarifa:?string}>}
 */
export async function cotizar(page, { origen, destino, peso, dimensiones = null }) {
  await visitar(page, '/rates');

  await elegirOpcion(page, '[wire\\:model="probe_origin"]', origen);
  await elegirOpcion(page, '[wire\\:model="probe_destination"]', destino);
  await page.fill('[wire\\:model="probe_weight"]', String(peso));
  await page.fill('[wire\\:model="probe_length"]', dimensiones ? String(dimensiones.largo) : '');
  await page.fill('[wire\\:model="probe_width"]', dimensiones ? String(dimensiones.ancho) : '');
  await page.fill('[wire\\:model="probe_height"]', dimensiones ? String(dimensiones.alto) : '');

  await page.click('button:has-text("Cotizar")');
  await expect(page.locator('[data-test="probe-peso-facturable"]')).toBeVisible();

  const hayPrecio = await page.locator('[data-test="probe-precio"]').count();

  return {
    real: aNumero(await page.locator('[data-test="probe-peso-real"]').innerText()),
    volumetrico: aNumero(await page.locator('[data-test="probe-peso-volumetrico"]').innerText()),
    facturable: aNumero(await page.locator('[data-test="probe-peso-facturable"]').innerText()),
    precio: hayPrecio ? await monto(page, 'probe-precio') : null,
    tarifa: hayPrecio ? (await page.locator('[data-test="probe-tarifa"]').innerText()).trim() : null,
  };
}

/* ───────────────────────── Cierres de envío ───────────────────────── */

/** Arma un cierre de envío y lo deja abierto. */
export async function crearCierre(page, { origen, destino, chofer = 'Chofer Contratado', placa = 'SJB-1234' }) {
  await visitar(page, '/dispatches');
  await page.click('button:has-text("Nuevo cierre")');
  // Acá las sedes se rotulan «SJO — Sede Central», con el prefijo adelante, así
  // que se busca por coincidencia y no por etiqueta exacta.
  await elegirOpcion(page, '[wire\\:model="origin_branch_id"]', origen);
  await elegirOpcion(page, '[wire\\:model="destination_branch_id"]', destino);
  await page.fill('[wire\\:model="driver_name"]', chofer);
  await page.fill('[wire\\:model="vehicle_plate"]', placa);
  await page.click('button:has-text("Crear cierre")');
  await expect(page.locator('body')).toContainText('creado');
}

/**
 * Sube una guía al cierre abierto.
 *
 * No hay aviso de éxito: la confirmación es que la línea se movió de
 * «disponibles» a la lista del cierre, que es lo que ve el despachador.
 */
export async function agregarAlCierre(page, codigo) {
  await page
    .locator('[data-test="guia-disponible"]', { hasText: codigo })
    .locator('button:has-text("Agregar")')
    .click();

  await expect(page.locator('[data-test="guias-del-cierre"]')).toContainText(codigo);
}

/** Baja una guía del cierre: vuelve a estar disponible. */
export async function quitarDelCierre(page, codigo) {
  await page
    .locator('[data-test="guias-del-cierre"] tr', { hasText: codigo })
    .locator('button:has-text("Quitar")')
    .click();

  await expect(page.locator('[data-test="guias-disponibles"]')).toContainText(codigo);
}

export async function despacharCierre(page) {
  page.once('dialog', (d) => d.accept());
  await page.click('button:has-text("Despachar cierre")');
  await expect(page.locator('body')).toContainText('despachado');
}

/** Recibe una guía escaneando —o digitando— su código. */
export async function recibirPorCodigo(page, codigo) {
  await page.fill('[wire\\:model="scanCode"]', codigo);
  await page.click('button:has-text("Recibir")');
}

export async function cerrarRecepcion(page) {
  page.once('dialog', (d) => d.accept());
  await page.click('button:has-text("Cerrar recepción")');
  await expect(page.locator('body')).toContainText('Recepción cerrada');
}

/** Entrega la encomienda a quien la retira. */
export async function entregar(page, { quienRetira, identificacion = null }) {
  // Todo cambio de estado se confirma con un diálogo del navegador; sin
  // aceptarlo, Playwright lo descarta y el formulario de entrega nunca abre.
  page.once('dialog', (d) => d.accept());
  await page.click('button:has-text("Entregado")');
  await expect(page.locator('[wire\\:model="receivedByName"]')).toBeVisible();

  await page.fill('[wire\\:model="receivedByName"]', quienRetira);

  if (identificacion) {
    await page.fill('[wire\\:model="receivedByIdentification"]', identificacion);
  }

  await page.click('button:has-text("Confirmar entrega")');
}
