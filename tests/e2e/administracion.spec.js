import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import { esperarLivewire, sufijoUnico, visitar } from './apoyo.js';
import {
  abrirCaja,
  configurarEmpresa,
  crearGuia,
  efectivoEsperado,
  elegirOpcion,
  empresaOperando,
  entregar,
  monto,
} from './flujos.js';

/**
 * Lo que hace el administrador cuando algo salió mal o hay que rendir cuentas:
 * editar una guía entera, corregir un estado puesto por error, sacar el estado
 * de cuenta de un cliente para cualquier rango de fechas y volver a mandar un
 * comprobante por correo. Más lo que se imprime: el total de bultos en la
 * etiqueta y la clave del comprobante en el recibo.
 *
 * Cada flujo sigue de largo después del cambio —se entrega, se corta, se
 * cobra— porque el riesgo de estas herramientas no es que fallen, es que dejen
 * la guía en un estado del que el flujo normal ya no sabe salir.
 */

const ARTISAN = process.env.E2E_ARTISAN || 'docker exec encomienda_app php artisan';
const DOCKER_APP = process.env.E2E_DOCKER_APP || 'encomienda_app';

function artisan(comando) {
  return execSync(`${ARTISAN} ${comando}`, { stdio: 'pipe' }).toString().trim();
}

/** El id de la guía abierta, sacado de la URL. */
function idDeLaGuia(page) {
  return page.url().match(/\/invoices\/(\d+)/)[1];
}

/** Acepta el wire:confirm y hace clic. */
async function confirmarYClic(page, locator) {
  page.once('dialog', (d) => d.accept());
  await locator.click();
}

async function corregirEstado(page, { a, motivo }) {
  await page.locator('button:has-text("Corregir estado")').first().click();
  const formulario = page.locator('[data-test="corregir-estado"]');
  await expect(formulario).toBeVisible();

  await formulario.locator('[wire\\:model="statusFixTo"]').selectOption({ label: a });
  await formulario.locator('[wire\\:model="statusFixReason"]').fill(motivo);
  await confirmarYClic(page, formulario.locator('button:has-text("Corregir estado")'));

  await expect(page.locator('body')).toContainText(`Estado corregido a "${a}"`);
}

async function crearClienteDeCredito(page, nombre) {
  await visitar(page, '/customers');
  await page.click('button:has-text("Nuevo cliente")');
  await page.fill('[wire\\:model="name"]', nombre);
  // Un cliente de crédito necesita identificación: se le factura al cortar.
  // DIMEX de 12 dígitos al azar, para que la consulta a Hacienda no encuentre
  // a nadie y le cambie el nombre.
  await page.locator('[wire\\:model="identification_type"]').selectOption('03');
  await page.fill('[wire\\:model\\.blur="identification"]', `1${Date.now().toString().slice(-11)}`);
  await page.locator('[wire\\:model\\.live="payment_condition"]').selectOption('credit');
  await page.fill('[wire\\:model="credit_limit"]', '500000');
  await page.click('button:has-text("Guardar")');
  await expect(page.locator('body')).toContainText(nombre);
}

test.describe('Administración', () => {
  /* ─────────────────────────── Etiqueta ─────────────────────────── */

  test('la etiqueta dice el total de bultos, también una por bulto', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 2000,
      sinImpuestos: true,
      bultosExtra: [{ precio: 1500 }],
    });

    const id = idDeLaGuia(page);

    const unica = await (await page.request.get(`/invoices/${id}/etiqueta`)).text();
    expect(unica).toContain('TOTAL: 2 BULTOS');

    const porBulto = await (await page.request.get(`/invoices/${id}/etiqueta?porBulto=1`)).text();
    expect(porBulto.split('TOTAL: 2 BULTOS').length - 1).toBe(2);
  });

  /* ───────────────────────── Editar la guía ───────────────────────── */

  test('el administrador edita la guía completa y la caja sigue cuadrando', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 2000,
      sinImpuestos: true,
    });
    expect(await efectivoEsperado(page)).toBe(10000 + guia.total);

    // Volver a la guía y entrar a editarla desde su botón.
    await visitar(page, '/invoices');
    await page.fill('[wire\\:model\\.live\\.debounce\\.400ms="search"]', guia.codigo);
    await page.getByRole('link', { name: guia.codigo, exact: true }).first().click();
    await esperarLivewire(page);

    await page.click('[data-test="editar-guia"]');
    await expect(page).toHaveURL(/\/invoices\/\d+\/edit/);
    await expect(page.locator('[data-test="aviso-edicion"]')).toContainText(`Editando la guía ${guia.codigo}`);
    await esperarLivewire(page);

    await page.fill('[wire\\:model="recipient_name"]', 'Ana Corregida');
    await page.fill('[wire\\:model="recipient_phone"]', '8888-0000');
    await page.fill('[wire\\:model\\.live="items.0.price"]', '3000');
    await page.click('button:has-text("Guardar factura")');

    await expect(page).toHaveURL(/\/invoices\/\d+$/);
    await expect(page.locator('body')).toContainText('Ana Corregida');
    await expect(page.locator('body')).toContainText('8888-0000');
    expect(await monto(page, 'guia-total')).toBe(3000);
    // El código no cambia: ya está impreso en la etiqueta.
    await expect(page.locator('[data-test="codigo-guia"]')).toHaveText(guia.codigo);

    // Editada en el mismo turno: el cobro se actualizó, no se duplicó.
    expect(await efectivoEsperado(page)).toBe(10000 + 3000);
  });

  /* ──────────────────────── Corregir el estado ──────────────────────── */

  test('corregir un estado puesto por error, entregar y deshacer la entrega', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 2000,
      sinImpuestos: true,
    });

    // Se adelanta: llegó y nadie la escaneó.
    await corregirEstado(page, { a: 'Llegó al destino', motivo: 'Llegó y no se escaneó' });
    await expect(page.locator('body')).toContainText('Corrección de estado: Llegó y no se escaneó');

    // El flujo normal sigue funcionando desde ahí.
    await entregar(page, { quienRetira: 'Jose Fernandez', identificacion: '109990888' });
    await expect(page.locator('body')).toContainText('Entrega registrada');
    await expect(page.locator('body')).toContainText('Evidencia de entrega');

    // Se entregó la equivocada: se deshace y la evidencia desaparece.
    await corregirEstado(page, { a: 'Llegó al destino', motivo: 'Se entregó la guía equivocada' });
    await expect(page.locator('body')).not.toContainText('Evidencia de entrega');

    // Y se puede volver a entregar.
    await entregar(page, { quienRetira: 'Persona Correcta' });
    await expect(page.locator('body')).toContainText('Entrega registrada a nombre de Persona Correcta');
  });

  test('anular no se hace desde corregir estado', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });
    await crearGuia(page, { origen: empresa.origen, destino: empresa.destino, precio: 2000, sinImpuestos: true });

    await page.locator('button:has-text("Corregir estado")').first().click();
    const select = page.locator('[data-test="corregir-estado"] [wire\\:model="statusFixTo"]');
    await expect(select).toBeVisible();
    const opciones = (await select.locator('option').allTextContents()).map((t) => t.trim());

    expect(opciones).not.toContain('Anulado');
    expect(opciones).not.toContain('Recibido'); // es el estado actual
    expect(opciones).toContain('Entregado');
  });

  /* ─────────────────── Estado de cuenta por fechas ─────────────────── */

  test('estado de cuenta por fechas de un cliente de crédito', async ({ page }) => {
    const empresa = await empresaOperando(page);
    const cliente = `Ferreteria ${sufijoUnico()}`;
    await crearClienteDeCredito(page, cliente);

    await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 7000,
      sinImpuestos: true,
      cobro: 'credit',
      clienteRemitente: cliente,
    });
    await expect(page.locator('body')).toContainText('Guía a crédito');

    await visitar(page, '/credito');
    await elegirOpcion(page, '[wire\\:model\\.live="customerId"]', cliente);

    const bloque = page.locator('[data-test="estado-por-fechas"]');
    await expect(bloque).toBeVisible();

    // Por defecto, el mes en curso: la guía de hoy entra.
    const enlace = bloque.locator('a:has-text("Generar PDF")');
    const pdf = await page.request.get(await enlace.getAttribute('href'));
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('pdf');

    // Fechas al revés: se avisa y no hay enlace.
    await bloque.locator('[wire\\:model\\.live="rangoDesde"]').fill('2026-12-31');
    await bloque.locator('[wire\\:model\\.live="rangoHasta"]').fill('2026-01-01');
    await expect(bloque).toContainText('La fecha final no puede ser anterior a la inicial.');
    await expect(bloque.locator('a:has-text("Generar PDF")')).toHaveCount(0);

    // El reporte no cortó nada: el corte sigue teniendo la guía pendiente.
    await page.click('button:has-text("Emitir estado de cuenta")');
    await expect(page.locator('body')).toContainText(/Estado de cuenta EC-\d+ emitido por ₡7,000\.00/);
  });

  /* ─────────────────── Comprobante aceptado ─────────────────── */

  test('comprobante aceptado: clave en el recibo, PDF y reenvío por correo', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await configurarEmpresa(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3000,
      conFactura: { tipo: '01', cedula: '109990888' },
    });
    const id = idDeLaGuia(page);

    const clave = artisan(`e2e:comprobante-aceptado ${guia.codigo}`).split('\n').pop().trim();
    expect(clave).toMatch(/^\d{50}$/);

    await page.reload();
    await esperarLivewire(page);
    await expect(page.locator('body')).toContainText('Aceptado por Hacienda');

    // El recibo del cliente lleva la clave y el consecutivo.
    const recibo = await (await page.request.get(`/invoices/${id}/recibo`)).text();
    expect(recibo).toContain('Clave numérica');
    expect(recibo).toContain(clave);

    // Reenvío a otro correo, como el del contador.
    const correo = `contador.${sufijoUnico()}@pruebas.test`;
    await page.click('button:has-text("Reenviar por correo")');
    const formulario = page.locator('[data-test="reenviar-comprobante"]');
    await expect(formulario).toBeVisible();
    await formulario.locator('[wire\\:model="resendEmail"]').fill(correo);
    await formulario.locator('button:has-text("Enviar")').click();
    await expect(page.locator('body')).toContainText(`enviada a ${correo}`);

    // En local el correo va al log: tiene que estar, con el PDF adjunto.
    const log = execSync(
      `docker exec ${DOCKER_APP} sh -c "grep -c '${correo}' storage/logs/laravel.log"`,
      { stdio: 'pipe' }
    ).toString().trim();
    expect(Number(log)).toBeGreaterThan(0);

    // El PDF quedó generado y se ve.
    await page.reload();
    await esperarLivewire(page);
    const verPdf = page.locator('a:has-text("Ver comprobante PDF")');
    await expect(verPdf).toBeVisible();
    const pdf = await page.request.get(await verPdf.getAttribute('href'));
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('pdf');
    const cuerpo = await pdf.body();
    expect(cuerpo.subarray(0, 4).toString()).toBe('%PDF');
    expect(cuerpo.length).toBeLessThan(200_000);
  });

  test('un correo mal escrito no se reenvía', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await configurarEmpresa(page);
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    const guia = await crearGuia(page, {
      origen: empresa.origen,
      destino: empresa.destino,
      precio: 3000,
      conFactura: { tipo: '01', cedula: '109990888' },
    });
    artisan(`e2e:comprobante-aceptado ${guia.codigo}`);

    await page.reload();
    await esperarLivewire(page);
    await page.click('button:has-text("Reenviar por correo")');
    const formulario = page.locator('[data-test="reenviar-comprobante"]');
    await formulario.locator('[wire\\:model="resendEmail"]').fill('esto-no-es-correo');
    await formulario.locator('button:has-text("Enviar")').click();

    await expect(formulario).toBeVisible();
    await expect(page.locator('body')).not.toContainText('enviada a esto-no-es-correo');
  });
});
