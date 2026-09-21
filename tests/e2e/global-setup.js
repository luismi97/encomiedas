import { execSync } from 'node:child_process';

/**
 * Antes de la primera prueba: que la aplicación esté arriba y que exista el
 * superadministrador con la contraseña que las pruebas esperan.
 *
 * Falla temprano y explicando qué falta. Sin esto, una aplicación apagada se
 * manifiesta como veinte pruebas que no encuentran un botón, y el rato se va
 * buscando el botón en vez de levantando el contenedor.
 */
const BASE_URL = process.env.E2E_BASE_URL || 'http://localhost:8090';

/** Cómo llegar a artisan. Por defecto, el contenedor de docker compose. */
const ARTISAN =
  process.env.E2E_ARTISAN || 'docker exec encomienda_app php artisan';

async function esperarLaAplicacion(intentos = 30) {
  for (let i = 0; i < intentos; i++) {
    try {
      const respuesta = await fetch(`${BASE_URL}/login`, { redirect: 'manual' });
      if (respuesta.status < 500) return;
    } catch {
      // todavía no responde
    }
    await new Promise((r) => setTimeout(r, 1000));
  }

  throw new Error(
    `La aplicación no responde en ${BASE_URL}.\n` +
      'Levantala con «docker compose up -d» (o apuntá E2E_BASE_URL a donde esté corriendo).'
  );
}

export default async function globalSetup() {
  await esperarLaAplicacion();

  try {
    execSync(`${ARTISAN} e2e:preparar`, { stdio: 'pipe' });
  } catch (error) {
    throw new Error(
      'No se pudo preparar el superadministrador de pruebas.\n' +
        `Comando: ${ARTISAN} e2e:preparar\n` +
        'Si la aplicación no corre en docker, poné E2E_ARTISAN con la forma de llamar a artisan.\n\n' +
        (error.stdout?.toString() || '') +
        (error.stderr?.toString() || '')
    );
  }
}
