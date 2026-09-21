import { test, expect } from '@playwright/test';
import { entrar, entrarComoSuperadmin, salir, SUPERADMIN } from './apoyo.js';

/**
 * Entrar y salir, que es lo primero que alguien hace y lo primero que se rompe.
 *
 * Las pruebas de PHP ya cubren la lógica; acá lo que se comprueba es que el
 * formulario de verdad envíe, que la sesión sobreviva a la redirección y que el
 * menú que aparece sea el del rol que entró.
 */
test.describe('Acceso al sistema', () => {
  test('rechaza credenciales que no existen', async ({ page }) => {
    await entrar(page, 'nadie@ninguna-parte.test', 'inventada');

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('body')).toContainText('no coinciden');
  });

  test('el superadministrador entra a su panel de empresas', async ({ page }) => {
    await entrarComoSuperadmin(page);

    await expect(page.locator('h1')).toContainText('Empresas');
    await expect(page.locator('[data-test="tabla-empresas"]')).toBeVisible();
  });

  /**
   * El superadministrador no tiene pantallas de operación.
   *
   * No es una restricción arbitraria: no pertenece a ninguna empresa, así que
   * un listado de guías no sabría cuáles mostrarle.
   */
  test('el superadministrador no ve el menú de operación', async ({ page }) => {
    await entrarComoSuperadmin(page);

    await expect(page.locator('nav')).not.toContainText('Facturas / Encomiendas');
    await expect(page.locator('nav')).not.toContainText('Caja');
  });

  test('entrar a una pantalla de empresa lo devuelve al panel', async ({ page }) => {
    await entrarComoSuperadmin(page);
    await page.goto('/invoices');

    await expect(page).toHaveURL(/\/superadmin\/empresas/);
    await expect(page.locator('body')).toContainText('Esa pantalla es de una empresa');
  });

  test('salir cierra la sesión', async ({ page }) => {
    await entrarComoSuperadmin(page);
    await salir(page);

    await page.goto('/superadmin/empresas');
    await expect(page).toHaveURL(/\/login/);
  });

  test('sin sesión, cualquier pantalla manda al login', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login/);
  });

  test('la contraseña no viaja en la URL', async ({ page }) => {
    await entrar(page, SUPERADMIN.correo, SUPERADMIN.clave);

    expect(page.url()).not.toContain(SUPERADMIN.clave);
  });
});
