import { test, expect } from '@playwright/test';
import { crearEmpresa, entrar, entrarComoSuperadmin, salir, visitar } from './apoyo.js';

/**
 * El día uno de un cliente nuevo: de la empresa recién creada a una guía
 * impresa y rastreable.
 *
 * Es lo que de verdad importa comprobar del cambio a multiempresa: que una
 * empresa dada de alta desde el panel —sin instalar nada, sin tocar la
 * consola— pueda operar de punta a punta con lo suyo. Las pruebas de PHP
 * verifican las piezas; esta verifica que encajan.
 */
test.describe('Operación de una empresa nueva', () => {
  /** Una segunda sede: una encomienda es un traslado, necesita origen y destino. */
  async function crearSegundaSede(page, { nombre, prefijo, sucursal }) {
    await visitar(page, '/branches');
    await page.click('button:has-text("Nueva sucursal")');
    await page.fill('[wire\\:model="name"]', nombre);
    await page.fill('[wire\\:model="prefix"]', prefijo);
    await page.fill('[wire\\:model="sucursal_code"]', sucursal);
    await page.fill('[wire\\:model="terminal_code"]', '00001');
    await page.click('button:has-text("Guardar")');
    await expect(page.locator('body')).toContainText(nombre);
  }

  /**
   * Una guía por cobrar. Se elige «por cobrar» a propósito: el cobro de contado
   * exige una caja abierta, y eso es otro recorrido —el del cajero—, no el de
   * comprobar que la empresa nueva puede recibir un paquete.
   */
  async function crearGuia(page, { origen, destino, remitente, destinatario, precio }) {
    await visitar(page, '/invoices-create');

    // Por nombre y no por posición: el selector ordena alfabéticamente, así que
    // «Limón» queda antes que «Sede de prueba» y el código guía salía al revés.
    await page
      .locator('[wire\\:model\\.live="pickup_branch_id"]')
      .selectOption({ label: origen });
    await page
      .locator('[wire\\:model\\.live="delivery_branch_id"]')
      .selectOption({ label: destino });

    await page.fill('[wire\\:model="sender_name"]', remitente);
    await page.fill('[wire\\:model="recipient_name"]', destinatario);
    await page.fill('[wire\\:model\\.live="items.0.price"]', String(precio));

    await page.locator('[wire\\:model\\.live="cobro"][value="collect"]').check();
    await page.click('button:has-text("Guardar factura")');
  }

  test('una empresa nueva recibe una encomienda y la guía queda rastreable', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page, { prefijo: 'SJO' });

    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);

    await crearSegundaSede(page, { nombre: 'Limón', prefijo: 'LIM', sucursal: '002' });
    await crearGuia(page, {
      origen: 'Sede de prueba',
      destino: 'Limón',
      remitente: 'Marta Solano',
      destinatario: 'Jose Fernandez',
      precio: 3500,
    });

    // El código guía se arma con los dos prefijos y el consecutivo de ESTA
    // empresa: la primera guía de un cliente nuevo es la número uno.
    await expect(page.locator('body')).toContainText('SJO-LIM-00001');

    await visitar(page, '/invoices');
    await expect(page.locator('body')).toContainText('SJO-LIM-00001');
    await expect(page.locator('body')).toContainText('Jose Fernandez');

    // Y el destinatario la puede seguir sin entrar al sistema.
    await page.goto(`/rastreo/${empresa.slug}/SJO-LIM-00001`);
    await expect(page.locator('body')).toContainText('SJO-LIM-00001');
    await expect(page.locator('body')).toContainText('Jose F.');
    // El portal es público: nada de datos personales completos ni montos.
    await expect(page.locator('body')).not.toContainText('Jose Fernandez');
    await expect(page.locator('body')).not.toContainText('3500');
  });

  test('el consecutivo de cada empresa arranca en uno', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const primera = await crearEmpresa(page, { prefijo: 'SJO' });
    const segunda = await crearEmpresa(page, { prefijo: 'SJO' });

    for (const empresa of [primera, segunda]) {
      await salir(page);
      await entrar(page, empresa.correo, empresa.clave);
      await crearSegundaSede(page, { nombre: 'Limón', prefijo: 'LIM', sucursal: '002' });
      await crearGuia(page, {
        origen: 'Sede de prueba',
        destino: 'Limón',
        remitente: 'Remitente',
        destinatario: 'Destinatario',
        precio: 1000,
      });

      // Mismos prefijos en las dos empresas, y las dos numeran desde uno: que
      // la guía de un cliente salga con el número 48 porque otro ya emitió 47
      // sería, como mínimo, una conversación incómoda.
      await expect(page.locator('body')).toContainText('SJO-LIM-00001');
    }
  });

  test('el portal público no encuentra un código inventado', async ({ page }) => {
    await page.goto('/rastreo/NO-EXISTE-99999');

    await expect(page.locator('body')).toContainText('No encontramos ninguna encomienda');
  });

  test('el buscador del portal público lleva a la guía', async ({ page }) => {
    await page.goto('/rastreo');
    await page.fill('input[name="codigo"]', 'NO-EXISTE-99999');
    await page.click('button:has-text("Consultar")');

    await expect(page.locator('body')).toContainText('No encontramos ninguna encomienda');
  });
});
