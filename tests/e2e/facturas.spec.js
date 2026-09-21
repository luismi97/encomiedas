import { test, expect } from '@playwright/test';
import { esperarLivewire, visitar } from './apoyo.js';
import {
  abrirCaja,
  abrirGuia,
  configurarEmpresa,
  crearGuia,
  efectivoEsperado,
  empresaOperando,
  monto,
} from './flujos.js';

/**
 * Facturas: a dónde va la plata y qué comprobante sale.
 *
 * Tres decisiones se cruzan en el mostrador y hay que probarlas por separado,
 * porque cada una manda el dinero a un lugar distinto: cómo se cobra, qué
 * comprobante se emite y con qué medio se paga.
 *
 * Ver PLAN-PRUEBAS-OPERACION.md, escenario 1.
 */
test.describe('Facturas', () => {
  /* ─────────────────── 1.1 Qué pasa con el dinero ─────────────────── */

  test('contado: el cobro entra al arqueo de origen', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 4000,
      cobro: 'prepaid',
      medioDePago: 'cash',
      sinImpuestos: true,
    });

    await expect(page.locator('body')).toContainText('Contado');
    expect(await efectivoEsperado(page)).toBe(10000 + guia.total);
  });

  /**
   * El «por cobrar» no es un ingreso de esta sede.
   *
   * Esa plata nunca pasó por el mostrador de origen: registrarla acá dejaría el
   * arqueo con un ingreso que no está en la gaveta, y el cajero cerraría con un
   * faltante inventado.
   */
  test('por cobrar: NO entra al arqueo de origen', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 4000,
      cobro: 'collect',
      sinImpuestos: true,
    });

    await expect(page.locator('body')).toContainText('POR COBRAR');
    expect(await efectivoEsperado(page)).toBe(10000);
  });

  /** Una guía por cobrar no necesita caja abierta: no se cobra nada todavía. */
  test('por cobrar: se puede crear sin caja abierta', async ({ page }) => {
    const empresa = await empresaOperando(page);

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 4000,
      cobro: 'collect',
      sinImpuestos: true,
    });

    expect(guia.codigo).toMatch(/^SJO-LIM-\d+$/);
  });

  /**
   * A crédito hace falta un cliente registrado.
   *
   * No se le puede fiar a un nombre escrito a mano en el mostrador: sin cliente
   * no hay a quién cobrarle después, ni saldo que mover.
   */
  test('crédito: exige remitente registrado como cliente de crédito', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
    await page.fill('[wire\\:model="sender_name"]', 'Cliente Sin Registrar');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');
    await page.fill('[wire\\:model\\.live="items.0.price"]', '4000');

    const credito = page.locator('[wire\\:model\\.live="cobro"][value="credit"]');

    // El sistema puede deshabilitar la opción o rechazarla al guardar; las dos
    // formas son válidas, lo que no puede es dejar pasar la guía.
    if (await credito.isDisabled()) {
      await expect(page.locator('body')).toContainText('Requiere elegir un remitente con convenio');

      return;
    }

    await credito.check();
    await page.click('button:has-text("Guardar factura")');
    await expect(page.locator('body')).toContainText('clientes');
    await expect(page).not.toHaveURL(/\/invoices\/\d+/);
  });

  /* ─────────────────── 1.2 Qué comprobante sale ─────────────────── */

  test('por defecto sale tiquete electrónico', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3000,
      sinImpuestos: true,
    });

    await expect(page.locator('body')).toContainText('Tiquete electrónico');
  });

  test('con cédula del receptor sale factura electrónica', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3000,
      sinImpuestos: true,
      conFactura: { tipo: '01', cedula: '109990888' },
    });

    await expect(page.locator('body')).toContainText('Factura electrónica');
  });

  /**
   * Marcar factura sin cédula no se deja pasar.
   *
   * Hacienda no acepta una factura electrónica sin receptor identificado: el
   * comprobante se rechazaría después de emitido, con el consecutivo ya
   * consumido. Mejor que no salga.
   */
  test('factura electrónica sin cédula se rechaza y explica por qué', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
    await page.fill('[wire\\:model="sender_name"]', 'Marta Solano');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');
    await page.fill('[wire\\:model\\.live="items.0.price"]', '3000');
    await page.locator('[wire\\:model\\.live="wantsInvoice"]').check();
    await esperarLivewire(page);

    await page.click('button:has-text("Guardar factura")');

    await expect(page.locator('body')).toContainText('identificación del receptor');
    await expect(page).not.toHaveURL(/\/invoices\/\d+/);
  });

  /* ─────────────────── 1.3 Cómo se paga ─────────────────── */

  for (const [medio, etiqueta] of [
    ['cash', 'Efectivo'],
    ['card', 'Tarjeta'],
    ['sinpe', 'SINPE Móvil'],
    ['transfer', 'Transferencia'],
    ['other', 'Otro'],
  ]) {
    test(`medio de pago: ${etiqueta}`, async ({ page }) => {
      const empresa = await empresaOperando(page);
      await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

      await crearGuia(page, {
        origen: empresa.origen,
        destino: empresa.destino,
        precio: 2500,
        cobro: 'prepaid',
        medioDePago: medio,
        sinImpuestos: true,
      });

      await visitar(page, '/caja');
      await expect(page.locator('body')).toContainText(etiqueta);

      // Solo el efectivo está en la gaveta. Los demás se cobran, pero el arqueo
      // no los cuenta: si los sumara, todo cierre daría faltante.
      expect(await monto(page, 'efectivo-esperado')).toBe(medio === 'cash' ? 12500 : 10000);
    });
  }

  /* ─────────────────── 1.4 Cargos y descuentos ─────────────────── */

  test('el IVA del 13 % se suma al total', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 10000,
    });

    expect(guia.subtotal).toBe(10000);
    expect(guia.total).toBe(11300);
    await expect(page.locator('body')).toContainText('IVA general');
  });

  test('el descuento resta del total', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 10000,
      descuento: 2000,
      sinImpuestos: true,
    });

    expect(guia.total).toBe(8000);
    // La pantalla lo muestra restando («-₡2,000.00»), y así se lee.
    expect(await monto(page, 'guia-descuento')).toBe(-2000);
  });

  /**
   * «A domicilio» sin dirección es una promesa sin destino: el paquete queda
   * sin a dónde llevarlo y nadie se entera hasta que el chofer sale.
   */
  test('entrega a domicilio suma el cargo y exige dirección', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
    await page.fill('[wire\\:model="sender_name"]', 'Marta Solano');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');
    await page.fill('[wire\\:model\\.live="items.0.price"]', '5000');
    await page.locator('[wire\\:model\\.live="home_delivery"]').check();
    await esperarLivewire(page);
    await page.fill('[wire\\:model\\.live="home_delivery_fee"]', '2500');

    // Sin dirección no pasa.
    await page.click('button:has-text("Guardar factura")');
    await expect(page).not.toHaveURL(/\/invoices\/\d+/);

    await page.fill('[wire\\:model="delivery_address"]', 'Barrio Escalante, casa esquinera azul');
    await page.click('button:has-text("Guardar factura")');
    await expect(page).toHaveURL(/\/invoices\/\d+/);

    // El subtotal son SOLO los bultos; el domicilio es un cargo aparte que
    // entra a la base gravable: (5.000 + 2.500) × 1,13 = 8.475.
    expect(await monto(page, 'guia-subtotal')).toBe(5000);
    expect(await monto(page, 'guia-total')).toBe(8475);
  });

  /**
   * El seguro es una política de la empresa, no de la guía.
   *
   * El porcentaje nace en cero: cada cliente decide si asegura y cuánto cobra.
   * Por eso la prueba lo configura primero, igual que haría el administrador el
   * primer día.
   */
  test('con seguro configurado, el valor declarado agrega su cargo', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await configurarEmpresa(page, { seguro: 2 });
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
    await page.fill('[wire\\:model="sender_name"]', 'Marta Solano');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');
    await page.fill('[wire\\:model\\.live="items.0.price"]', '5000');
    await page.fill('[wire\\:model\\.live="declared_value"]', '100000');
    await esperarLivewire(page);

    // 2 % de los ₡100.000 declarados. El sistema trae 7 % de fábrica; la
    // prueba lo bajó a 2 %, así que este número también comprueba que la
    // configuración de la empresa mandó sobre el valor por defecto.
    await expect(page.locator('[data-test="resumen-seguro"]')).toContainText('2,000.00');

    await page.click('button:has-text("Guardar factura")');
    await expect(page).toHaveURL(/\/invoices\/\d+/);

    // (5.000 del bulto + 2.000 de seguro) × 1,13.
    expect(await monto(page, 'guia-total')).toBe(7910);
  });

  /**
   * Con clave configurada, descontar exige autorización.
   *
   * Es el control contra el descuento «de confianza» que un cajero se hace a sí
   * mismo: sin la clave no se aplica, y lo que queda registrado es quién
   * autorizó.
   */
  test('con clave configurada, el descuento sin clave se rechaza', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await configurarEmpresa(page, { claveDeDescuento: 'clave-del-jefe' });
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
    await page.fill('[wire\\:model="sender_name"]', 'Marta Solano');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');
    await page.fill('[wire\\:model\\.live="items.0.price"]', '10000');
    await page.fill('[wire\\:model\\.live="discount_amount"]', '2000');
    await esperarLivewire(page);

    await page.click('button:has-text("Guardar factura")');
    await expect(page).not.toHaveURL(/\/invoices\/\d+/);

    await page.fill('[wire\\:model="discountCode"]', 'clave-del-jefe');
    await page.click('button:has-text("Guardar factura")');
    await expect(page).toHaveURL(/\/invoices\/\d+/);

    expect(await monto(page, 'guia-descuento')).toBe(-2000);
  });

  /* ─────────────────── El comprobante queda listo ─────────────────── */

  /**
   * Sin certificado no se transmite, y la pantalla lo dice.
   *
   * Es el estado normal de una empresa recién dada de alta: opera y cobra, pero
   * no emite. Lo que no puede pasar es que parezca lista y falle al primer
   * comprobante.
   */
  test('sin certificado cargado, la guía avisa qué falta para emitir', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3000,
      sinImpuestos: true,
    });

    await abrirGuia(page, guia.codigo);
    await expect(page.locator('body')).toContainText(/certificado|facturación electrónica/i);
  });
});
