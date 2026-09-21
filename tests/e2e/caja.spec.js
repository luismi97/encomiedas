import { test, expect } from '@playwright/test';
import { visitar } from './apoyo.js';
import {
  abrirCaja,
  cerrarCaja,
  crearGuia,
  desglosar,
  efectivoEsperado,
  empresaOperando,
  monto,
  movimientoDeCaja,
} from './flujos.js';

/**
 * La caja: abrir, operar, cerrar y que cuadre.
 *
 * Es el escenario que más importa, porque es donde el error cuesta plata. La
 * afirmación central es una sola: **lo que el sistema dice que hay en la gaveta
 * es lo que hay en la gaveta**.
 *
 * Ver PLAN-PRUEBAS-OPERACION.md, escenario 4.
 */
test.describe('Caja', () => {
  test('abrir el turno con su fondo inicial', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    await expect(page.locator('body')).toContainText('Turno abierto');
    expect(await monto(page, 'efectivo-esperado')).toBe(20000);
  });

  /**
   * Sin caja abierta, un cobro de contado no entraría a ningún arqueo: la plata
   * quedaría sin registrar. Se rechaza antes de guardar.
   */
  test('sin caja abierta no se puede cobrar de contado', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
    await page.fill('[wire\\:model="sender_name"]', 'Marta Solano');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');
    await page.fill('[wire\\:model\\.live="items.0.price"]', '3500');
    await page.click('button:has-text("Guardar factura")');

    await expect(page.locator('body')).toContainText('No tenés una caja abierta');
    await expect(page).not.toHaveURL(/\/invoices\/\d+/);
  });

  test('un cobro en efectivo entra al arqueo', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3500,
      cobro: 'prepaid',
      medioDePago: 'cash',
    });

    // 20.000 de fondo + 3.955 de la guía (3.500 + 13 % de IVA).
    expect(await efectivoEsperado(page)).toBe(23955);
  });

  /**
   * La tarjeta no está en la gaveta.
   *
   * Sumarla al efectivo esperado haría que todo arqueo diera faltante por el
   * monto de lo cobrado con tarjeta, y el cajero terminaría respondiendo por
   * plata que nunca tocó.
   */
  test('un cobro con tarjeta no suma al efectivo esperado', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 12000,
      cobro: 'prepaid',
      medioDePago: 'card',
    });

    expect(await efectivoEsperado(page)).toBe(20000);
    await expect(page.locator('body')).toContainText('Tarjeta');
  });

  test('las entradas suman y las salidas restan', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    await movimientoDeCaja(page, { tipo: 'in', monto: 2000, motivo: 'Reposición de sencillo' });
    expect(await efectivoEsperado(page)).toBe(22000);

    await movimientoDeCaja(page, { tipo: 'out', monto: 1500, motivo: 'Pago de mensajería' });
    expect(await efectivoEsperado(page)).toBe(20500);
  });

  /**
   * Un faltante sin explicación no se puede aclarar al día siguiente: quedó una
   * salida de efectivo sin decir a dónde fue.
   */
  test('toda entrada o salida exige motivo', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    await visitar(page, '/caja');
    await page.locator('[wire\\:model="movementType"]').selectOption('out');
    await page.fill('[wire\\:model="movementAmount"]', '500');
    await page.click('button:has-text("Registrar")');

    await expect(page.locator('[data-test="aviso-caja"]')).toContainText('motivo');
    expect(await efectivoEsperado(page)).toBe(20000);
  });

  test('el arqueo cuadrado cierra sin diferencia', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    const cierre = await cerrarCaja(page, { cuadrado: true });

    expect(cierre.diferencia).toBe(0);
    await expect(page.locator('[data-test="aviso-caja"]')).toContainText('cuadrado');
  });

  test('un faltante sale marcado con su monto', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    // Se cuentan 15.000 de los 20.000 que debería haber.
    const cierre = await cerrarCaja(page, {
      conteo: desglosar(15000),
      nota: 'Faltan ₡5.000, se revisa con el supervisor.',
    });

    expect(cierre.diferencia).toBe(-5000);
    await expect(page.locator('[data-test="aviso-caja"]')).toContainText('Faltante de ₡5,000.00');
  });

  test('un sobrante también sale marcado', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    const cierre = await cerrarCaja(page, {
      conteo: desglosar(22000),
      nota: 'Sobran ₡2.000, se revisa el vuelto del día.',
    });

    expect(cierre.diferencia).toBe(2000);
    await expect(page.locator('[data-test="aviso-caja"]')).toContainText('Sobrante de ₡2,000.00');
  });

  /**
   * El turno completo, con todo mezclado.
   *
   * Es la prueba que resume el plan entero: cinco guías con condiciones de
   * cobro distintas, una entrada y una salida, y al final la gaveta tiene que
   * tener exactamente lo que el sistema dice.
   */
  test('un turno con todo mezclado cierra cuadrado', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 20000 });

    const ruta = { origen: empresa.origen, destino: empresa.destino };

    // Sin impuestos para que la cuenta sea legible: lo que se está probando acá
    // es a dónde va cada cobro, no cómo se calcula el IVA.
    const contado1 = await crearGuia(page, { ...ruta, precio: 3500, cobro: 'prepaid', medioDePago: 'cash', sinImpuestos: true });
    const contado2 = await crearGuia(page, { ...ruta, precio: 5000, cobro: 'prepaid', medioDePago: 'cash', sinImpuestos: true });
    const tarjeta = await crearGuia(page, { ...ruta, precio: 12000, cobro: 'prepaid', medioDePago: 'card', sinImpuestos: true });
    const porCobrar = await crearGuia(page, { ...ruta, precio: 4000, cobro: 'collect', sinImpuestos: true });

    await movimientoDeCaja(page, { tipo: 'in', monto: 2000, motivo: 'Reposición de sencillo' });
    await movimientoDeCaja(page, { tipo: 'out', monto: 1500, motivo: 'Pago de mensajería' });

    // 20.000 + 3.500 + 5.000 + 2.000 − 1.500. La tarjeta no está en la gaveta y
    // el por cobrar se cobra del otro lado.
    const esperado = 20000 + contado1.total + contado2.total + 2000 - 1500;

    expect(await efectivoEsperado(page)).toBe(esperado);

    await visitar(page, '/caja');
    await expect(page.locator('body')).toContainText(`Tarjeta: ₡${tarjeta.total.toLocaleString('en-US', { minimumFractionDigits: 2 })}`);

    const cierre = await cerrarCaja(page, { cuadrado: true });

    expect(cierre.esperado).toBe(esperado);
    expect(cierre.contado).toBe(esperado);
    expect(cierre.diferencia).toBe(0);
    await expect(page.locator('[data-test="aviso-caja"]')).toContainText('cuadrado');

    // Y la guía por cobrar sigue viva, esperando el cobro en destino.
    expect(porCobrar.codigo).toMatch(/^SJO-LIM-/);
  });

  test('el desglose de billetes suma exactamente lo esperado', async ({ page }) => {
    // No toca la aplicación: fija la aritmética de la ayuda que arma el conteo,
    // porque si esa se equivoca, todos los arqueos de arriba mienten.
    expect(desglosar(29000)).toEqual({ 20000: 1, 5000: 1, 2000: 2 });
    expect(desglosar(23955)).toEqual({ 20000: 1, 2000: 1, 1000: 1, 500: 1, 100: 4, 50: 1, 5: 1 });

    const total = Object.entries(desglosar(29000))
      .reduce((suma, [valor, cantidad]) => suma + Number(valor) * cantidad, 0);
    expect(total).toBe(29000);
  });
});
