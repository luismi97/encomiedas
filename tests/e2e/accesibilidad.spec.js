import { test, expect } from '@playwright/test';
import { crearEmpresa, entrar, entrarComoSuperadmin, salir, visitar } from './apoyo.js';

/**
 * Agrandar la letra de todo el sistema.
 *
 * Se mide el tamaño de <html>, que es la base de todos los rem: si esa base
 * crece, crecen el texto, los campos y los botones juntos.
 */
test.describe('Accesibilidad', () => {
  const base = (page) => page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));

  test('la letra se agranda, se recuerda y vuelve a la normal', async ({ page }) => {
    await entrarComoSuperadmin(page);
    const empresa = await crearEmpresa(page);
    await salir(page);
    await entrar(page, empresa.correo, empresa.clave);
    await visitar(page, '/invoices-create');

    const normal = await base(page);
    const boton = page.getByRole('button', { name: 'Tamaño de letra' });
    await expect(boton).toHaveAttribute('aria-expanded', 'false');

    await boton.click();
    await expect(boton).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('radio', { name: /Muy grande/ }).check();
    expect(await base(page)).toBeCloseTo(normal * 1.5, 1);

    // Se recuerda al recargar y en otra pantalla, aplicado antes de pintar.
    await visitar(page, '/invoices');
    expect(await base(page)).toBeCloseTo(normal * 1.5, 1);

    // Con teclado: Esc cierra el panel y devuelve el foco al botón.
    await boton.click();
    await page.keyboard.press('Escape');
    await expect(boton).toHaveAttribute('aria-expanded', 'false');
    await expect(boton).toBeFocused();

    await boton.click();
    await page.getByRole('radio', { name: /Normal/ }).check();
    expect(await base(page)).toBeCloseTo(normal, 1);
  });
});
