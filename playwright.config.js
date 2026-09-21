import { defineConfig, devices } from '@playwright/test';

/**
 * Pruebas de navegador.
 *
 * Corren contra la aplicación levantada de verdad (docker compose up), no
 * contra un servidor de mentira: lo que se quiere comprobar acá es justamente
 * lo que las pruebas de PHP no ven —que Livewire responda, que el menú lleve a
 * donde dice y que la sesión sobreviva a cambiar de empresa—.
 *
 *   docker compose up -d
 *   npm run test:e2e
 *
 * Necesitan Node 20 o superior (Playwright no arranca en 18). Con nvm:
 *   nvm use 20 && npm run test:e2e
 *
 * No reinician la base: cada prueba crea su propia empresa con un nombre único
 * y trabaja adentro. Es lo que las hace seguras de correr contra el entorno de
 * desarrollo con datos que a alguien le importan.
 */
export default defineConfig({
  testDir: './tests/e2e',
  // Un solo trabajador: las pruebas comparten la base de desarrollo, y dos
  // navegadores dando de alta empresas a la vez se pisan los consecutivos.
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  // El reporte HTML siempre: trae el rastro, el video y las capturas de cada
  // prueba, y se puede recorrer paso a paso lo que hizo el navegador. Queda en
  // playwright-report/ y se abre con «npx playwright show-report».
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
  ],

  globalSetup: './tests/e2e/global-setup.js',

  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8090',
    locale: 'es-CR',
    timezoneId: 'America/Costa_Rica',
    // Solo del intento que falla: guardar el rastro de todo llena el disco sin
    // que nadie lo mire.
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15_000,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
