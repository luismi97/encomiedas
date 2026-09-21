import { test, expect } from '@playwright/test';
import { crearEmpresa, entrar, entrarComoSuperadmin, esperarLivewire, salir, visitar } from './apoyo.js';

/**
 * Lo que una empresa ve y lo que no, comprobado en el navegador.
 *
 * Las pruebas de PHP ya cubren el ámbito global, pero no cubren el camino real:
 * hay un error de aislamiento que solo aparece con una petición HTTP de verdad
 * —el contexto resolviéndose antes de que exista la sesión— y que en PHPUnit no
 * se ve, porque ahí el usuario está puesto desde el principio. Esta prueba es
 * la que lo agarra.
 *
 * Es el riesgo central del cambio: una sola instalación para todos los
 * clientes significa que una consulta sin filtrar le muestra a un
 * transportista los envíos y las cédulas de su competencia.
 */
test.describe('Aislamiento entre empresas', () => {
  /** Da de alta un cliente desde la pantalla de Clientes. */
  async function crearCliente(page, nombre, identificacion) {
    await visitar(page, '/customers');
    await page.click('button:has-text("Nuevo cliente")');
    await page.fill('[wire\\:model="name"]', nombre);
    await page.fill('[wire\\:model="identification"]', identificacion);
    await page.click('button:has-text("Guardar")');
    await expect(page.locator('body')).toContainText(nombre);
  }

  /** Una cédula distinta en cada corrida: la unicidad es por empresa, no por sistema. */
  function cedula() {
    return String(Math.floor(1e8 + Math.random() * 9e8));
  }

  test('cada empresa ve solo sus clientes y sus sedes', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const primera = await crearEmpresa(page, { prefijo: 'AAA' });
    const segunda = await crearEmpresa(page, { prefijo: 'BBB' });

    const cedulaA = cedula();
    const cedulaB = cedula();

    await salir(page);
    await entrar(page, primera.correo, primera.clave);
    await crearCliente(page, 'Cliente de la primera', cedulaA);

    await salir(page);
    await entrar(page, segunda.correo, segunda.clave);
    await crearCliente(page, 'Cliente de la segunda', cedulaB);

    // La segunda no ve nada de la primera.
    await visitar(page, '/customers');
    await expect(page.locator('body')).toContainText('Cliente de la segunda');
    await expect(page.locator('body')).not.toContainText('Cliente de la primera');

    // Y la primera tampoco ve nada de la segunda.
    await salir(page);
    await entrar(page, primera.correo, primera.clave);
    await visitar(page, '/customers');
    await expect(page.locator('body')).toContainText('Cliente de la primera');
    await expect(page.locator('body')).not.toContainText('Cliente de la segunda');
  });

  /**
   * El listado de sedes es la señal más clara de que el filtro está puesto: si
   * el aislamiento no funciona, ahí aparecen las sedes de TODOS los clientes
   * del sistema, incluidas las de la empresa de demostración.
   */
  test('el listado de sedes trae una sola sede, la propia', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);
    await visitar(page, '/branches');

    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody')).toContainText('Sede de prueba');
  });

  /**
   * La misma cédula registrada en dos empresas.
   *
   * Un mismo comerciante le compra a dos transportistas, y ninguno tiene por
   * qué enterarse del otro. Con el índice único global que había antes, el
   * segundo en registrarlo no podía.
   */
  test('dos empresas pueden registrar al mismo cliente', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const primera = await crearEmpresa(page);
    const segunda = await crearEmpresa(page);
    const compartida = cedula();

    await salir(page);
    await entrar(page, primera.correo, primera.clave);
    await crearCliente(page, 'Ferretería El Clavo', compartida);

    await salir(page);
    await entrar(page, segunda.correo, segunda.clave);
    await crearCliente(page, 'Ferretería El Clavo', compartida);

    await visitar(page, '/customers');
    await expect(page.locator('tbody')).toContainText('Ferretería El Clavo');
  });

  /**
   * Pedir por URL una guía de otra empresa no la muestra.
   *
   * Es el intento directo: el id viaja en la dirección, y basta cambiarlo. El
   * aislamiento tiene que responder «no existe», no el contenido.
   */
  test('una guía de otra empresa no se abre por URL', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);

    // La 1 es de la empresa de demostración, que este administrador no opera.
    const respuesta = await page.goto('/invoices/1');

    expect(respuesta.status()).toBe(404);
  });

  test('el aislamiento sigue puesto tras recargar la pantalla', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);

    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);

    // Recargar es lo que delata un contexto que se resolvió una sola vez y
    // quedó mal: la primera carga se ve bien y la siguiente muestra de más.
    for (let i = 0; i < 2; i++) {
      await visitar(page, '/invoices');
      await esperarLivewire(page);
      await expect(page.locator('body')).not.toContainText('SJ-ALA-');
    }
  });
});
