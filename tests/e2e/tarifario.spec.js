import { test, expect } from '@playwright/test';
import { visitar } from './apoyo.js';
import { abrirCaja, cotizar, crearTarifa, empresaOperando, monto } from './flujos.js';

/**
 * El tarifario: cuánto se cobra por llevar un paquete, y por qué.
 *
 * Una tarifa dice: «de ESTA sede a ESTA otra, para ESTE tipo de envío, entre
 * ESTE y ESTE peso, se cobra ESTO». Cualquiera de las tres primeras
 * condiciones se puede dejar en blanco, y eso significa «para todas»: así se
 * tiene una tarifa base sin declarar las 30 combinaciones de sedes.
 *
 * Cuando el cajero pesa un paquete, el sistema busca cuál tarifa aplica y
 * propone el precio. Es una propuesta: el cajero puede pisarla.
 *
 * Cada prueba de acá es un ejemplo de una regla. Leerlas en orden es la forma
 * más rápida de entender el tarifario.
 *
 * Ver PLAN-PRUEBAS-OPERACION.md, escenario 5.
 */
test.describe('Tarifario', () => {
  /**
   * Regla 0: sin tarifas no se inventa un precio.
   *
   * Devolver cero sería peor que no devolver nada: la guía saldría gratis y
   * nadie se enteraría. El sistema avisa y el cajero digita el precio.
   */
  test('sin tarifas configuradas, avisa que hay que digitar el precio', async ({ page }) => {
    const empresa = await empresaOperando(page);

    const resultado = await cotizar(page, { origen: empresa.prefijoOrigen, destino: empresa.prefijoDestino, peso: 3 });

    expect(resultado.precio).toBeNull();
    await expect(page.locator('body')).toContainText('No hay tarifa configurada');
  });

  /**
   * Regla 1: una tarifa sin ruta sirve para todas las rutas.
   *
   * Es la tarifa base, la que se configura primero. Dejando origen y destino
   * en «—», cubre cualquier combinación de sedes.
   */
  test('una tarifa sin ruta aplica a cualquier ruta', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await crearTarifa(page, { nombre: 'Base nacional', desde: 0, hasta: 5, precio: 2500 });

    const ida = await cotizar(page, { origen: empresa.prefijoOrigen, destino: empresa.prefijoDestino, peso: 3 });
    const vuelta = await cotizar(page, { origen: empresa.prefijoDestino, destino: empresa.prefijoOrigen, peso: 3 });

    expect(ida.precio).toBe(2500);
    expect(vuelta.precio).toBe(2500);
    expect(ida.tarifa).toBe('Base nacional');
  });

  /**
   * Regla 2: las bandas de peso no se pisan en el borde.
   *
   * El extremo de arriba es EXCLUSIVO. Con bandas 0–1 y 1–5, un kilo exacto
   * cae en la segunda y solo en la segunda: si el borde fuera inclusivo, el
   * mismo paquete tendría dos precios válidos y ganaría el azar.
   */
  test('un kilo exacto cae en la banda de arriba, no en las dos', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await crearTarifa(page, { nombre: 'Hasta 1 kg', desde: 0, hasta: 1, precio: 1500 });
    await crearTarifa(page, { nombre: 'De 1 a 5 kg', desde: 1, hasta: 5, precio: 3000 });

    const ruta = { origen: empresa.prefijoOrigen, destino: empresa.prefijoDestino };

    expect((await cotizar(page, { ...ruta, peso: 0.9 })).tarifa).toBe('Hasta 1 kg');
    expect((await cotizar(page, { ...ruta, peso: 1 })).tarifa).toBe('De 1 a 5 kg');
    expect((await cotizar(page, { ...ruta, peso: 4.99 })).tarifa).toBe('De 1 a 5 kg');
  });

  /**
   * Regla 3: la banda sin tope cobra por kilo excedente.
   *
   * Es la de arriba de todas, la que atrapa los paquetes pesados. Deja «hasta»
   * en blanco y pone un precio por kilo: se cobra el precio base más los kilos
   * que pasen del mínimo, redondeados hacia arriba —nadie cobra medio kilo—.
   */
  test('la banda sin tope suma por cada kilo de más', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await crearTarifa(page, {
      nombre: 'De 5 kg en adelante',
      desde: 5,
      hasta: null,
      precio: 5000,
      porKgExtra: 800,
    });

    const ruta = { origen: empresa.prefijoOrigen, destino: empresa.prefijoDestino };

    // 5 kg justos: solo el precio base.
    expect((await cotizar(page, { ...ruta, peso: 5 })).precio).toBe(5000);
    // 8 kg: base + 3 kilos de más.
    expect((await cotizar(page, { ...ruta, peso: 8 })).precio).toBe(5000 + 3 * 800);
    // 8,2 kg: el excedente se redondea hacia arriba, son 4 kilos.
    expect((await cotizar(page, { ...ruta, peso: 8.2 })).precio).toBe(5000 + 4 * 800);
  });

  /**
   * Regla 4: se cobra por el peso volumétrico cuando es mayor.
   *
   * Una caja de almohadas no pesa nada y ocupa medio camión. La industria cobra
   * por el mayor entre lo que marca la balanza y lo que ocupa: largo × ancho ×
   * alto dividido entre 5.000.
   */
  test('una caja grande y liviana se cobra por su volumen', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await crearTarifa(page, { nombre: 'Hasta 5 kg', desde: 0, hasta: 5, precio: 2000 });
    await crearTarifa(page, { nombre: 'De 5 a 20 kg', desde: 5, hasta: 20, precio: 6000 });

    // 40 × 40 × 40 = 64.000 cm³ ÷ 5.000 = 12,8 kg volumétricos, contra 2 kg reales.
    const resultado = await cotizar(page, {
      origen: empresa.prefijoOrigen,
      destino: empresa.prefijoDestino,
      peso: 2,
      dimensiones: { largo: 40, ancho: 40, alto: 40 },
    });

    expect(resultado.real).toBe(2);
    expect(resultado.volumetrico).toBe(12.8);
    expect(resultado.facturable).toBe(12.8);
    expect(resultado.tarifa).toBe('De 5 a 20 kg');
    expect(resultado.precio).toBe(6000);
  });

  /** Si el paquete pesa más de lo que ocupa, manda la balanza. */
  test('un paquete denso se cobra por su peso real', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await crearTarifa(page, { nombre: 'Base', desde: 0, hasta: 50, precio: 4000 });

    const resultado = await cotizar(page, {
      origen: empresa.prefijoOrigen,
      destino: empresa.prefijoDestino,
      peso: 30,
      dimensiones: { largo: 20, ancho: 20, alto: 20 }, // 1,6 kg volumétricos
    });

    expect(resultado.volumetrico).toBe(1.6);
    expect(resultado.facturable).toBe(30);
  });

  /**
   * Regla 5: la tarifa más específica le gana a la general.
   *
   * Cada condición declarada suma: la ruta pesa más que el tipo de envío. Así
   * se pone un precio especial para una ruta cara sin tocar la tarifa base.
   */
  test('la tarifa de una ruta concreta le gana a la general', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await crearTarifa(page, { nombre: 'Base nacional', desde: 0, hasta: 20, precio: 3000 });
    await crearTarifa(page, {
      nombre: 'Ruta al Caribe',
      origen: empresa.prefijoOrigen,
      destino: empresa.prefijoDestino,
      desde: 0,
      hasta: 20,
      precio: 7000,
    });

    const ruta = await cotizar(page, { origen: empresa.prefijoOrigen, destino: empresa.prefijoDestino, peso: 3 });
    const otraDireccion = await cotizar(page, { origen: empresa.prefijoDestino, destino: empresa.prefijoOrigen, peso: 3 });

    expect(ruta.tarifa).toBe('Ruta al Caribe');
    expect(ruta.precio).toBe(7000);

    // La tarifa de ruta es de ida: la vuelta cae en la base. Si se quiere en
    // los dos sentidos, hay que declararla dos veces.
    expect(otraDireccion.tarifa).toBe('Base nacional');
  });

  /**
   * Con la misma especificidad gana la banda MÁS ANCHA.
   *
   * OJO: esto contradice lo que dice el comentario del código, que promete que
   * gana «la de rango más estrecho». Con una base 0–20 y una promoción 3–5
   * encima, la promoción NUNCA se aplica. Está probado acá para dejar la
   * conducta real por escrito; si la intención era la contraria, el arreglo
   * está en Tarifario::buscar() y esta prueba tiene que cambiar con él.
   */
  test('entre dos tarifas igual de específicas gana la de banda más ancha', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await crearTarifa(page, { nombre: 'Ancha 0 a 20', desde: 0, hasta: 20, precio: 3000 });
    await crearTarifa(page, { nombre: 'Estrecha 3 a 5', desde: 3, hasta: 5, precio: 9000 });

    const resultado = await cotizar(page, { origen: empresa.prefijoOrigen, destino: empresa.prefijoDestino, peso: 4 });

    expect(resultado.tarifa).toBe('Ancha 0 a 20');
    expect(resultado.precio).toBe(3000);
  });

  /** Una tarifa desactivada deja de aplicar, sin borrarla. */
  test('desactivar una tarifa la saca de circulación', async ({ page }) => {
    const empresa = await empresaOperando(page);

    await crearTarifa(page, { nombre: 'Base nacional', desde: 0, hasta: 20, precio: 3000 });
    await crearTarifa(page, {
      nombre: 'Promoción de verano',
      origen: empresa.prefijoOrigen,
      destino: empresa.prefijoDestino,
      desde: 0,
      hasta: 20,
      precio: 1500,
    });

    expect((await cotizar(page, { origen: empresa.prefijoOrigen, destino: empresa.prefijoDestino, peso: 3 })).tarifa)
      .toBe('Promoción de verano');

    // El estado es el propio botón: dice «Activa» y al pulsarlo pasa a
    // «Inactiva». No hay un botón «Desactivar» aparte.
    await visitar(page, '/rates');
    const fila = page.locator('[data-test="tabla-tarifas"] tr', { hasText: 'Promoción de verano' });
    await fila.locator('button:has-text("Activa")').click();
    await expect(fila).toContainText('Inactiva');

    expect((await cotizar(page, { origen: empresa.prefijoOrigen, destino: empresa.prefijoDestino, peso: 3 })).tarifa)
      .toBe('Base nacional');
  });

  /* ─────────── El tarifario dentro del mostrador ─────────── */

  /**
   * Lo que el cajero ve: al poner el peso, el precio aparece solo.
   *
   * Es todo el punto del tarifario. Sin él, cada cajero cobra lo que recuerda.
   */
  test('en la guía, el precio se propone solo al poner el peso', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await crearTarifa(page, { nombre: 'De 1 a 5 kg', desde: 1, hasta: 5, precio: 3200 });
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
    await page.fill('[wire\\:model="sender_name"]', 'Marta Solano');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');

    // Solo el peso: el precio lo pone el tarifario.
    await page.fill('[wire\\:model\\.blur="items.0.weight"]', '3');
    await page.locator('[wire\\:model="recipient_name"]').click(); // dispara el blur

    await expect(page.locator('[wire\\:model\\.live="items.0.price"]')).toHaveValue('3200');
    await expect(page.locator('body')).toContainText('Precio sugerido');
  });

  /**
   * Un precio digitado a mano no se pierde al corregir el peso.
   *
   * Es un acuerdo puntual con el cliente, no un descuido: si recotizar lo
   * pisara, el cajero perdería el arreglo sin enterarse.
   */
  test('el precio digitado a mano sobrevive a recotizar', async ({ page }) => {
    const empresa = await empresaOperando(page);
    await crearTarifa(page, { nombre: 'De 1 a 5 kg', desde: 1, hasta: 5, precio: 3200 });
    await abrirCaja(page, { sede: empresa.origen, fondo: 10000 });

    await visitar(page, '/invoices-create');
    await page.locator('[wire\\:model\\.live="pickup_branch_id"]').selectOption({ label: empresa.origen });
    await page.locator('[wire\\:model\\.live="delivery_branch_id"]').selectOption({ label: empresa.destino });
    await page.fill('[wire\\:model="sender_name"]', 'Marta Solano');
    await page.fill('[wire\\:model="recipient_name"]', 'Jose Fernandez');

    await page.fill('[wire\\:model\\.blur="items.0.weight"]', '3');
    await page.locator('[wire\\:model="recipient_name"]').click();
    await expect(page.locator('[wire\\:model\\.live="items.0.price"]')).toHaveValue('3200');

    // El cajero acuerda 2.500 con el cliente.
    await page.fill('[wire\\:model\\.live="items.0.price"]', '2500');

    // Y después corrige el peso: el acuerdo sigue en pie.
    await page.fill('[wire\\:model\\.blur="items.0.weight"]', '4');
    await page.locator('[wire\\:model="recipient_name"]').click();

    await expect(page.locator('[wire\\:model\\.live="items.0.price"]')).toHaveValue('2500');

    await page.click('button:has-text("Guardar factura")');
    await expect(page).toHaveURL(/\/invoices\/\d+/);
    expect(await monto(page, 'guia-subtotal')).toBe(2500);
  });
});
