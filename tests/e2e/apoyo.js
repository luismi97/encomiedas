import { expect } from '@playwright/test';

/**
 * Lo que todas las pruebas de navegador necesitan hacer: entrar, dar de alta
 * una empresa y meterse en ella.
 *
 * Está acá y no repetido en cada archivo porque son los pasos que más van a
 * cambiar —un campo nuevo en el alta, un botón que se mueve— y conviene tener
 * un solo lugar donde arreglarlo.
 */

export const SUPERADMIN = {
  correo: process.env.E2E_SUPERADMIN || 'superadmin@encomienda.test',
  clave: process.env.E2E_SUPERADMIN_PASSWORD || 'password',
};

/**
 * Un sufijo distinto en cada corrida.
 *
 * Las pruebas corren contra la base de desarrollo, que no se reinicia: sin
 * esto, la segunda corrida chocaría contra el correo del administrador que
 * creó la primera.
 */
export function sufijoUnico() {
  return `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4).toString(36)}`;
}

/**
 * Espera a que Livewire esté escuchando.
 *
 * Su script se carga aparte y se engancha cuando termina. Playwright, en
 * cambio, hace clic apenas el botón existe en el HTML —que es antes—, y ese
 * primer clic se pierde sin error ni rastro: el botón está, el clic ocurre y no
 * pasa absolutamente nada. Es la falla más desconcertante de estas pruebas y se
 * evita esperando acá.
 */
export async function esperarLivewire(page) {
  await page.waitForFunction(
    () => window.Livewire && window.Livewire.all().length > 0,
    null,
    { timeout: 15_000 }
  );
}

/** Navega y espera a que la pantalla esté viva, no solo dibujada. */
export async function visitar(page, ruta) {
  await page.goto(ruta);
  await esperarLivewire(page);
}

export async function entrar(page, correo, clave) {
  await page.goto('/login');
  await page.fill('input[name="login"]', correo);
  await page.fill('input[name="password"]', clave);
  await page.click('[data-login-submit]');
}

export async function entrarComoSuperadmin(page) {
  await entrar(page, SUPERADMIN.correo, SUPERADMIN.clave);
  await expect(page).toHaveURL(/\/superadmin\/empresas/);
  await esperarLivewire(page);
}

export async function salir(page) {
  await page.click('button:has-text("Salir")');
  await expect(page).toHaveURL(/\/login/);
}

/**
 * Da de alta una empresa desde el panel y devuelve sus datos.
 *
 * @returns {Promise<{nombre:string, correo:string, clave:string, prefijo:string}>}
 */
export async function crearEmpresa(page, { nombre, prefijo = 'SJ' } = {}) {
  const sufijo = sufijoUnico();
  const datos = {
    nombre: nombre || `Pruebas ${sufijo}`,
    correo: `admin.${sufijo}@pruebas.test`,
    clave: 'clave-de-prueba',
    prefijo,
  };

  await esperarLivewire(page);
  await page.click('button:has-text("Nueva empresa")');
  await expect(page.locator('[data-test="formulario-empresa"]')).toBeVisible();

  await page.fill('[data-test="empresa-nombre"]', datos.nombre);
  await page.fill('[data-test="admin-nombre"]', 'Administrador de prueba');
  await page.fill('[data-test="admin-correo"]', datos.correo);
  await page.fill('[data-test="admin-clave"]', datos.clave);
  await page.fill('[data-test="sede-nombre"]', 'Sede de prueba');
  await page.fill('[data-test="sede-prefijo"]', datos.prefijo);

  await page.click('button:has-text("Crear empresa")');
  await expect(page.locator('[data-test="aviso"]')).toContainText('lista para operar');

  // El identificador lo arma el servidor (y lo numera si choca), así que se lee
  // de la fila en vez de deducirlo del nombre: es el que va en la URL pública.
  const fila = await filaDeEmpresa(page, datos.nombre);
  datos.slug = await fila.getAttribute('data-empresa');

  return datos;
}

/**
 * La fila de una empresa, buscándola primero.
 *
 * El listado está paginado y ordenado por nombre: buscar la fila a ojo funciona
 * las primeras corridas y después la empresa recién creada aparece en la página
 * tres. Filtrar es además lo que haría una persona.
 */
export async function filaDeEmpresa(page, nombre) {
  await esperarLivewire(page);
  await page.fill('[data-test="buscar-empresa"]', nombre);

  const fila = page.locator('[data-test="empresa-fila"]', { hasText: nombre });
  await expect(fila).toHaveCount(1);

  return fila;
}

/** Entra a una empresa desde el panel (suplantación). */
export async function entrarAEmpresa(page, nombre) {
  const fila = await filaDeEmpresa(page, nombre);
  await fila.locator('[data-test="entrar-empresa"]').click();
  await expect(page.locator('[data-test="aviso-suplantacion"]')).toBeVisible();
}
