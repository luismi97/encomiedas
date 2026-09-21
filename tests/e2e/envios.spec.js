import { test, expect } from '@playwright/test';
import { esperarLivewire, visitar } from './apoyo.js';
import { abrirCaja, abrirGuia, crearGuia, crearSede, crearTarifa, empresaOperando, monto } from './flujos.js';

/**
 * Envíos: qué se manda y cómo se identifica.
 *
 * El tipo de envío, el tamaño, el peso y las dimensiones son lo que describe el
 * bulto; el código guía es lo que lo identifica frente al cliente y en la
 * etiqueta pegada al paquete.
 *
 * Ver PLAN-PRUEBAS-OPERACION.md, escenario 2.
 */
test.describe('Envíos', () => {
  /* ─────────────────── 2.1 y 2.2 El bulto ─────────────────── */

  /**
   * Los tres tipos de envío y para qué sirven.
   *
   * Paquete, sobre y documento no cambian nada de cómo se trata la guía: lo que
   * cambian es QUÉ TARIFA aplica, y por lo tanto cuánto se cobra. Un sobre a
   * Limón no puede costar lo mismo que una caja.
   *
   * Se prueba por el precio y no por una etiqueta en pantalla porque el tipo de
   * envío no se muestra en el detalle de la guía: su único efecto visible es el
   * que el tarifario propone.
   */
  test('el tipo de envío decide cuál tarifa aplica', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await crearTarifa(page, { nombre: 'Cualquier bulto', desde: 0, hasta: 10, precio: 5000 });
    await crearTarifa(page, { nombre: 'Solo sobres', tipo: 'envelope', desde: 0, hasta: 10, precio: 1200 });
    await crearTarifa(page, { nombre: 'Solo documentos', tipo: 'document', desde: 0, hasta: 10, precio: 900 });
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    // Sin tarifa propia, el paquete cae en la general.
    const esperado = { package: '5000', envelope: '1200', document: '900' };

    for (const [tipo, precio] of Object.entries(esperado)) {
      await visitar(page, '/invoices-create');
      await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
      await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
      await page.locator('[wire\\:model\\.live="shipment_type"]').selectOption(tipo);
      await page.fill('[wire\\:model\\.blur="items.0.weight"]', '2');
      await page.locator('[wire\\:model="sender_name"]').click(); // dispara el blur

      // toHaveValue y no inputValue(): la cotización va y vuelve del servidor,
      // así que hay que esperar el resultado en vez de leer lo que haya.
      await expect(page.locator('[wire\\:model\\.live="items.0.price"]')).toHaveValue(precio);
    }
  });

  test('el tamaño y el peso quedan guardados en el bulto', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 4000,
      tamano: 'XL',
      peso: 12.5,
      sinImpuestos: true,
    });

    await abrirGuia(page, guia.codigo);
    await expect(page.locator('body')).toContainText('XL');
    await expect(page.locator('body')).toContainText('12.5');
  });

  /**
   * Varios bultos en una guía.
   *
   * Es lo normal cuando alguien manda tres cajas al mismo destinatario: una
   * guía, un código, tres bultos. El subtotal es la suma.
   */
  test('una guía puede llevar varios bultos y suma sus precios', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3000,
      sinImpuestos: true,
      bultosExtra: [
        { precio: 2000, tamano: 'S' },
        { precio: 1500, tamano: 'M' },
      ],
    });

    expect(guia.subtotal).toBe(6500);

    await abrirGuia(page, guia.codigo);
    // Tres renglones de paquete en el detalle.
    await expect(page.locator('body')).toContainText('6,500.00');
  });

  /* ─────────────────── 2.4 El código guía ─────────────────── */

  /**
   * El código dice de dónde sale y a dónde va.
   *
   * `SJO-LIM-00001` es «de la sede SJO a la sede LIM, guía número 1 de esa
   * ruta». Es lo que el cliente lee por teléfono y lo que se escanea en el
   * camión, así que tiene que ser corto y decir algo.
   */
  test('el código se arma con los prefijos de las dos sedes', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 2000,
      sinImpuestos: true,
    });

    expect(guia.codigo).toBe('SJO-LIM-00001');
  });

  /**
   * El consecutivo es por ruta, no por empresa.
   *
   * Cada par de sedes lleva su propia numeración: la primera guía de vuelta es
   * la número 1 aunque de ida ya vayan tres.
   */
  test('cada ruta lleva su propio consecutivo', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    const ida1 = await crearGuia(page, { origen: empresa.origen, destino: empresa.destino, precio: 1000, sinImpuestos: true });
    const ida2 = await crearGuia(page, { origen: empresa.origen, destino: empresa.destino, precio: 1000, sinImpuestos: true });

    expect(ida1.codigo).toBe('SJO-LIM-00001');
    expect(ida2.codigo).toBe('SJO-LIM-00002');

    // La vuelta arranca de nuevo. Se cobra por cobrar porque la caja abierta es
    // la de origen y esta guía sale de la otra sede.
    const vuelta = await crearGuia(page, {
      origen: empresa.destino,
      destino: empresa.origen,
      precio: 1000,
      cobro: 'collect',
      sinImpuestos: true,
    });

    expect(vuelta.codigo).toBe('LIM-SJO-00001');
  });

  /** Una tercera sede abre una tercera numeración. */
  test('una sede nueva estrena su propia numeración', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await crearSede(page, { nombre: 'Pérez Zeledón', prefijo: 'PZ', sucursal: '003' });
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    const aLimon = await crearGuia(page, { origen: empresa.origen, destino: empresa.destino, precio: 1000, sinImpuestos: true });
    const aPerez = await crearGuia(page, { origen: empresa.origen, destino: 'Pérez Zeledón', precio: 1000, sinImpuestos: true });

    expect(aLimon.codigo).toBe('SJO-LIM-00001');
    expect(aPerez.codigo).toBe('SJO-PZ-00001');
  });

  /**
   * Origen y destino iguales no es un envío.
   *
   * Y además rompe el código: `SJO-SJO-00001` no significa nada.
   */
  test('no se puede enviar de una sede a sí misma', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.origen });
    await page.fill('[wire\\:model="sender_name"]', 'Marta Solano');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');
    await page.fill('[wire\\:model\\.live="items.0.price"]', '2000');
    await esperarLivewire(page);

    await page.click('button:has-text("Guardar factura")');

    await expect(page.locator('body')).toContainText('distinta de la de origen');
    await expect(page).not.toHaveURL(/\/invoices\/\d+/);
  });

  /* ─────────────────── Lo que se imprime ─────────────────── */

  /**
   * La etiqueta y el recibo son papel que sale de verdad: la etiqueta se pega
   * al paquete y el recibo se lo lleva el cliente con el QR de seguimiento.
   */
  test('la etiqueta y el recibo se generan con el código de la guía', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 2000,
      sinImpuestos: true,
    });

    const id = page.url().match(/\/invoices\/(\d+)/)[1];

    for (const documento of ['etiqueta', 'recibo']) {
      const respuesta = await page.request.get(`/invoices/${id}/${documento}`);

      expect(respuesta.status()).toBe(200);
      expect(await respuesta.text()).toContain(guia.codigo);
    }
  });

  test('la factura en PDF se descarga', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 2000,
      sinImpuestos: true,
    });

    const id = page.url().match(/\/invoices\/(\d+)/)[1];
    const respuesta = await page.request.get(`/invoices/${id}/pdf`);

    expect(respuesta.status()).toBe(200);
    expect(respuesta.headers()['content-type']).toContain('pdf');
  });

  /** El listado encuentra la guía por su código y por el nombre de quien la recibe. */
  test('el listado filtra por código y por destinatario', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      destinatario: 'Yolanda Campos',
      precio: 2000,
      sinImpuestos: true,
    });

    await visitar(page, '/invoices');
    const buscador = '[wire\\:model\\.live\\.debounce\\.400ms="search"]';

    await page.fill(buscador, guia.codigo);
    await expect(page.locator('body')).toContainText('Yolanda Campos');

    await page.fill(buscador, 'Yolanda');
    await expect(page.locator('body')).toContainText(guia.codigo);

    await page.fill(buscador, 'no-existe-este-texto');
    await expect(page.locator('body')).not.toContainText(guia.codigo);
  });

  /** El monto de la guía no cambia solo: el detalle muestra lo que se guardó. */
  test('el detalle muestra el mismo total que se cobró', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 7000,
      sinImpuestos: true,
    });

    await abrirGuia(page, guia.codigo);
    expect(await monto(page, 'guia-total')).toBe(guia.total);
  });
});
