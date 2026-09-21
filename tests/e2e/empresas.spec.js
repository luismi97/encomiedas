import { test, expect } from '@playwright/test';
import { crearEmpresa, entrar, entrarAEmpresa, entrarComoSuperadmin, esperarLivewire, filaDeEmpresa, salir, visitar } from './apoyo.js';

/**
 * El recorrido que reemplaza a instalar el sistema por cliente.
 *
 * Un cliente nuevo tiene que quedar operando desde el navegador, sin consola y
 * sin despliegue: se llena un formulario, se le dicta un acceso y adentro ya
 * están su sede, su caja y su IVA. Estas pruebas recorren eso de punta a punta.
 */
test.describe('Alta y control de empresas', () => {
  test('el superadministrador da de alta una empresa lista para operar', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    await expect(await filaDeEmpresa(page, empresa.nombre)).toBeVisible();

    // Y el acceso que se le dicta al cliente funciona de verdad.
    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);

    await expect(page).toHaveURL(/\/dashboard/);
  });

  /**
   * «Lista para operar» es literal: sin sede no se crea una guía, sin caja no
   * se cobra y sin IVA hay que marcarlo renglón por renglón. Eso es justo lo
   * que antes se olvidaba al instalar a mano.
   */
  test('la empresa nueva trae su sede, su caja y su IVA', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);
    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);

    await visitar(page, '/branches');
    await expect(page.locator('body')).toContainText('Sede de prueba');

    await visitar(page, '/cash-registers');
    await expect(page.locator('body')).toContainText('Caja principal');

    await visitar(page, '/taxes');
    await expect(page.locator('body')).toContainText('IVA general');

    await visitar(page, '/package-types');
    await expect(page.locator('body')).toContainText('Paquete');
  });

  /**
   * Lo que el sistema no puede inventar queda pendiente, y la pantalla dice
   * cuál: el certificado y las credenciales de ATV son del contribuyente.
   */
  test('la facturación electrónica queda pendiente y se explica', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);
    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);

    await visitar(page, '/settings/company');
    await expect(page.locator('body')).toContainText('certificado');
  });

  test('suplantar entra a la empresa y se puede volver', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    await entrarAEmpresa(page, empresa.nombre);
    await expect(page.locator('[data-test="aviso-suplantacion"]')).toContainText(empresa.nombre);

    // Adentro se opera con las pantallas normales de la empresa.
    await visitar(page, '/invoices');
    await expect(page).toHaveURL(/\/invoices/);

    await page.click('button:has-text("Volver al panel")');
    await expect(page).toHaveURL(/\/superadmin\/empresas/);
    await expect(page.locator('[data-test="aviso-suplantacion"]')).toHaveCount(0);
  });

  /**
   * Suspender es lo que se hace con un cliente que deja de pagar: no puede
   * entrar, pero no se le borra nada. Sus comprobantes están transmitidos a
   * Hacienda y tienen que poder consultarse.
   */
  test('una empresa suspendida no puede entrar', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    const fila = await filaDeEmpresa(page, empresa.nombre);
    await fila.locator('[data-test="suspender-empresa"]').click();
    await expect(page.locator('[data-test="aviso"]')).toContainText('suspendida');
    await expect(fila).toContainText('Suspendida');

    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('body')).toContainText('suspendida');
  });

  test('reactivar la deja entrar otra vez', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    const fila = await filaDeEmpresa(page, empresa.nombre);
    await fila.locator('[data-test="suspender-empresa"]').click();
    await expect(fila).toContainText('Suspendida');
    await fila.locator('[data-test="suspender-empresa"]').click();
    await expect(fila).toContainText('Activa');

    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);
    await expect(page).toHaveURL(/\/dashboard/);
  });

  test('no se puede dar de alta dos veces el mismo correo', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    await esperarLivewire(page);
    await page.click('button:has-text("Nueva empresa")');
    await page.fill('[data-test="empresa-nombre"]', 'Otra empresa');
    await page.fill('[data-test="admin-nombre"]', 'Otro');
    await page.fill('[data-test="admin-correo"]', empresa.correo);
    await page.fill('[data-test="admin-clave"]', 'clave-de-prueba');
    await page.fill('[data-test="sede-nombre"]', 'Sede');
    await page.click('button:has-text("Crear empresa")');

    await expect(page.locator('[data-test="formulario-empresa"]')).toContainText(
      'ya tiene cuenta en el sistema'
    );
  });

  /**
   * Soporte: el cliente perdió el acceso y no tiene a mano el correo de
   * recuperación. No se puede mostrar la contraseña anterior —está cifrada—,
   * se reemplaza y se le dicta la nueva.
   */
  test('se le puede reescribir la contraseña al administrador de un cliente', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    const fila = await filaDeEmpresa(page, empresa.nombre);
    await fila.locator('button:has-text("Contraseña")').click();

    const formulario = page.locator('[data-test="clave-admin"]');
    await expect(formulario).toContainText(empresa.correo);
    await formulario.locator('input[name="password"]').fill('otra-clave-nueva');
    await formulario.locator('button:has-text("Cambiar")').click();

    await expect(page.locator('body')).toContainText('actualizada');

    await salir(page);
    await entrar(page, empresa.correo, 'otra-clave-nueva');
    await expect(page).toHaveURL(/\/dashboard/);
  });

  /**
   * Eliminar se lleva todo y no se deshace, así que pide escribir el nombre.
   * Para un cliente que se va, lo correcto es suspenderlo.
   */
  test('eliminar exige escribir el nombre exacto', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    const fila = await filaDeEmpresa(page, empresa.nombre);
    await fila.locator('button:has-text("Eliminar")').first().click();

    const confirmacion = page.locator('[data-test="confirmar-borrado"]');
    await expect(confirmacion).toBeVisible();

    await confirmacion.locator('input').fill('nombre equivocado');
    await confirmacion.locator('button:has-text("Eliminar definitivamente")').click();
    await expect(page.locator('[data-test="aviso"]')).toContainText('no coincide');

    await page
      .locator('[data-test="confirmar-borrado"] input')
      .fill(empresa.nombre);
    await page
      .locator('[data-test="confirmar-borrado"] button:has-text("Eliminar definitivamente")')
      .click();

    await expect(page.locator('[data-test="aviso"]')).toContainText('eliminada');
    await expect(
      page.locator('[data-test="empresa-fila"]', { hasText: empresa.nombre })
    ).toHaveCount(0);
  });
});
