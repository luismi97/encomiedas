import { test, expect } from '@playwright/test';
import { visitar } from './apoyo.js';
import {
  abrirCaja,
  abrirGuia,
  agregarAlCierre,
  cambiarEstado,
  cerrarRecepcion,
  crearCierre,
  crearGuia,
  crearSede,
  despacharCierre,
  efectivoEsperado,
  empresaOperando,
  entregar,
  quitarDelCierre,
  recibirPorCodigo,
} from './flujos.js';

/**
 * El recorrido completo de un paquete: del mostrador al destinatario.
 *
 * Recibir, alistar, cargar el camión, despacharlo, recibirlo del otro lado y
 * entregarlo. Cada paso deja rastro, y el que cobra al final tiene que entrar
 * al arqueo de la sede que entrega, no al de la que recibió el paquete.
 *
 * Ver PLAN-PRUEBAS-OPERACION.md, escenario 3.
 */
test.describe('Manifiestos y entregas', () => {
  /** Una empresa con caja abierta y una guía lista para viajar. */
  async function guiaListaParaViajar(page, { cobro = 'prepaid', precio = 3000 } = {}) {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio,
      cobro,
      sinImpuestos: true,
    });

    await abrirGuia(page, guia.codigo);
    await cambiarEstado(page, 'Listo para envío');

    return { empresa, guia };
  }

  /* ─────────────────── 3.1 Armar y despachar ─────────────────── */

  test('una guía recién recibida está «Recibido» y pasa a «Listo para envío»', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3000,
      sinImpuestos: true,
    });

    await abrirGuia(page, guia.codigo);
    await expect(page.locator('body')).toContainText('Recibido');

    await cambiarEstado(page, 'Listo para envío');
    await expect(page.locator('body')).toContainText('Listo para envío');
  });

  /**
   * En el camión solo suben las guías de ESA ruta.
   *
   * Es lo que impide cargar en el camión de Limón un paquete que va a Pérez
   * Zeledón. Una guía recién recibida SÍ se puede cargar sin marcarla «Listo»
   * antes: en el mostrador se recibe y se manda en el mismo movimiento, y
   * obligar al paso intermedio solo agregaría clics.
   */
  test('al cierre solo suben las guías de esa ruta', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearSede(page, { nombre: 'Pérez Zeledón', prefijo: 'PZ', sucursal: '003' });

    // Una guía para OTRO destino: no tiene nada que hacer en este camión.
    const aOtroDestino = await crearGuia(page, {
      origen: empresa.origen,
      destino: 'Pérez Zeledón',
      precio: 1000,
      sinImpuestos: true,
    });

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });

    await expect(page.locator('[data-test="guias-disponibles"]')).toContainText(guia.codigo);
    await expect(page.locator('[data-test="guias-disponibles"]')).not.toContainText(aOtroDestino.codigo);
  });

  /**
   * Una guía no puede ir en dos camiones a la vez.
   *
   * Después de subirla a un cierre desaparece de los disponibles: si apareciera,
   * el mismo paquete se despacharía dos veces y el segundo manifiesto llegaría
   * con un faltante que nadie puede explicar.
   */
  test('una guía ya cargada no vuelve a ofrecerse', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);

    await expect(page.locator('[data-test="guias-disponibles"]')).not.toContainText(guia.codigo);
  });

  test('una guía se sube y se baja del cierre', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await quitarDelCierre(page, guia.codigo);
  });

  test('despachar pone todas sus guías en camino', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino, chofer: 'Randall Mora', placa: 'CL-8899' });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);

    await abrirGuia(page, guia.codigo);
    await expect(page.locator('body')).toContainText('Enviado');
  });

  /* ─────────────────── 3.2 Recibir en destino ─────────────────── */

  /**
   * Recepción por código: es lo que hace el escáner.
   *
   * El despachador dispara la pistola contra la etiqueta y la línea se marca
   * sola. Digitarlo a mano es el mismo camino, para cuando la etiqueta viene
   * rayada.
   */
  test('la guía se recibe escaneando su código', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);

    await recibirPorCodigo(page, guia.codigo);
    await expect(page.locator('[data-test="guias-del-cierre"]')).toContainText('Recibida');

    await cerrarRecepcion(page);

    await abrirGuia(page, guia.codigo);
    await expect(page.locator('body')).toContainText('Llegó al destino');
  });

  /** Un código que no va en ese camión no se recibe. */
  test('un código ajeno al cierre se rechaza', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);

    await recibirPorCodigo(page, 'SJO-LIM-99999');

    await expect(page.locator('body')).toContainText(/no|No/);
    await expect(page.locator('[data-test="guias-del-cierre"]')).not.toContainText('Recibida');
  });

  /* ─────────────────── 3.3 Entregar ─────────────────── */

  /** El recorrido entero, de punta a punta. */
  test('el paquete llega y se entrega con constancia de quién retiró', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);
    await recibirPorCodigo(page, guia.codigo);
    await cerrarRecepcion(page);

    await abrirGuia(page, guia.codigo);
    await entregar(page, { quienRetira: 'Yolanda Campos', identificacion: '108880777' });

    await expect(page.locator('body')).toContainText('Entregado');
    await expect(page.locator('body')).toContainText('Yolanda Campos');

    // Y el recorrido queda completo en la bitácora de la guía.
    for (const paso of ['Recibido', 'Listo para envío', 'Enviado', 'Llegó al destino', 'Entregado']) {
      await expect(page.locator('body')).toContainText(paso);
    }
  });

  /** Sin nombre de quien retira no hay constancia de la entrega. */
  test('la entrega exige el nombre de quien retira', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);
    await recibirPorCodigo(page, guia.codigo);
    await cerrarRecepcion(page);

    await abrirGuia(page, guia.codigo);
    // Todo cambio de estado se confirma antes de abrir su formulario.
    page.once('dialog', (d) => d.accept());
    await page.click('button:has-text("Entregado")');
    await expect(page.locator('[wire\\:model="receivedByName"]')).toBeVisible();
    await page.fill('[wire\\:model="receivedByName"]', '');
    await page.click('button:has-text("Confirmar entrega")');

    await expect(page.locator('body')).toContainText('nombre de quien retira');
  });

  /**
   * El «por cobrar» se cobra al entregar, y se cobra en DESTINO.
   *
   * Es la pieza que cierra el circuito del dinero: la plata no pasó por el
   * mostrador de origen, así que su arqueo no la vio; entra al de la sede que
   * entrega, que es donde la persona la pone sobre el mostrador.
   */
  test('el por cobrar entra al arqueo de destino al entregar', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page, { cobro: 'collect', precio: 4000 });

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);
    await recibirPorCodigo(page, guia.codigo);
    await cerrarRecepcion(page);

    // El arqueo de origen no la vio pasar: solo tiene su fondo inicial.
    expect(await efectivoEsperado(page)).toBe(20000);

    // La caja de destino arranca en cero.
    await abrirCaja(page, { sede: empresa.destino, fondo: 0 });

    await abrirGuia(page, guia.codigo);
    await entregar(page, { quienRetira: 'Yolanda Campos' });
    await expect(page.locator('body')).toContainText('Entregado');

    await visitar(page, '/caja');
    await expect(page.locator('body')).toContainText('Turno abierto');
    expect(await efectivoEsperado(page)).toBe(4000);
  });

  /**
   * Un «por cobrar» sin caja abierta no se entrega.
   *
   * Y se corta ANTES de mover nada: si el paquete saliera y el cobro no
   * entrara a ningún arqueo, la plata desaparecería sin dejar rastro.
   */
  test('un por cobrar sin caja en destino no se puede entregar', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page, { cobro: 'collect', precio: 4000 });

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);
    await recibirPorCodigo(page, guia.codigo);
    await cerrarRecepcion(page);

    // Sin abrir la caja de destino.
    await abrirGuia(page, guia.codigo);
    await entregar(page, { quienRetira: 'Yolanda Campos' });

    await expect(page.locator('body')).toContainText('POR COBRAR');
    await expect(page.locator('body')).toContainText('caja abierta');
    await expect(page.locator('body')).toContainText('Llegó al destino');
  });

  /** Una incidencia queda registrada y después se resuelve. */
  test('se registra una incidencia de entrega y se resuelve', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);
    await recibirPorCodigo(page, guia.codigo);
    await cerrarRecepcion(page);

    await abrirGuia(page, guia.codigo);
    await page.click('button:has-text("Reportar incidencia")');
    await page.fill('[wire\\:model="incidentDescription"]', 'Nadie atendió en la casa, se deja aviso.');
    await page.click('button:has-text("Registrar")');

    await expect(page.locator('body')).toContainText('Nadie atendió');

    await page.click('button:has-text("Marcar resuelta")');
    await expect(page.locator('body')).toContainText('Resuelta');
  });

  /**
   * Anular deja constancia de por qué.
   *
   * Una guía anulada sin motivo es una guía que nadie puede explicar tres meses
   * después, cuando el cliente reclama.
   */
  test('anular exige motivo y queda firmado en la bitácora', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3000,
      sinImpuestos: true,
    });

    await abrirGuia(page, guia.codigo);
    page.once('dialog', (d) => d.accept());
    await page.click('button:has-text("Anulado")');
    await expect(page.locator('[wire\\:model="cancelReason"]')).toBeVisible();
    await page.fill('[wire\\:model="cancelReason"]', 'El cliente se arrepintió antes de que saliera.');

    page.once('dialog', (d) => d.accept());
    await page.click('button:has-text("Confirmar anulación")');

    await expect(page.locator('body')).toContainText('Anulado');
    await expect(page.locator('body')).toContainText('se arrepintió');
  });

  /* ─────────────────── El seguimiento público ─────────────────── */

  /**
   * Lo que ve el destinatario sin entrar al sistema.
   *
   * Todo el recorrido, y NADA de datos personales completos ni montos: aunque
   * alguien recorra consecutivos a la fuerza, no saca nada aprovechable.
   */
  test('el rastreo público muestra el recorrido sin exponer datos', async ({ page }) => {
    const { empresa, guia } = await guiaListaParaViajar(page);

    await crearCierre(page, { origen: empresa.origen, destino: empresa.destino });
    await agregarAlCierre(page, guia.codigo);
    await despacharCierre(page);
    await recibirPorCodigo(page, guia.codigo);
    await cerrarRecepcion(page);

    await page.goto(`/rastreo/${empresa.slug}/${guia.codigo}`);

    await expect(page.locator('body')).toContainText(guia.codigo);
    await expect(page.locator('body')).toContainText('Llegó al destino');
    await expect(page.locator('body')).toContainText(empresa.origen);
    // Nombre parcial del destinatario, sin apellido completo ni montos.
    await expect(page.locator('body')).toContainText('Jose F.');
    await expect(page.locator('body')).not.toContainText('Jose Fernandez');
    await expect(page.locator('body')).not.toContainText('3,000');
  });
});
