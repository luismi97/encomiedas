# Multiempresa · Encomiendas CR

Cómo una sola instalación atiende a varias empresas cliente. Escrito para quien
instala, vende y da soporte al sistema — no para el cajero, que no se entera de
nada de esto.

---

## Qué cambió

Antes, cada cliente nuevo era una instalación aparte: su base de datos, su
despliegue, su dominio y su ronda de actualizaciones. Ahora es una fila en una
tabla: se llena un formulario y el cliente ya puede entrar.

Todo lo demás sigue igual desde adentro. El cajero ve las mismas pantallas, el
código guía se ve igual y los comprobantes salen igual. La empresa es una capa
por debajo que nadie de la operación diaria tiene que conocer.

---

## Los dos tipos de acceso

| | Superadministrador | Administrador de empresa |
|---|---|---|
| Pertenece a | el sistema | una empresa |
| `company_id` | vacío | la suya |
| Ve | el listado de empresas | solo lo de la suya |
| Entra a | `/superadmin/empresas` | el tablero de operación |
| Para qué | dar de alta clientes, suspender, dar soporte | operar |

El superadministrador **no tiene pantallas de operación**. No es una
restricción arbitraria: no pertenece a ninguna empresa, así que un listado de
guías no sabría cuáles mostrarle. Si intenta entrar a una, se le devuelve a su
panel.

Para ver lo que ve un cliente está **Entrar** (suplantación): inicia sesión como
el administrador de esa empresa, con sus mismas pantallas y sus mismos datos.
Mientras dura, una franja ámbar arriba dice en cuál está y ofrece volver.

---

## Dar de alta un cliente

### Desde el panel

1. Entrar como superadministrador → **Empresas** → **Nueva empresa**.
2. Nombre de la empresa (lo demás es opcional).
3. El **primer acceso**: nombre, correo y contraseña inicial del administrador.
   Es lo que se le dicta al cliente; que la cambie al entrar.
4. La **primera sede**: nombre y, si se quiere, el prefijo del código guía
   (`SJ` en `SJ-LIM-00005`). Sin sede no se puede crear ninguna guía.

Queda armado de una vez: la sede, su caja, el IVA general, los tipos de bulto y
las denominaciones del arqueo.

### Desde la consola

```bash
php artisan empresa:crear \
    --nombre="Transportes López" \
    --admin-nombre="Ana López" \
    --admin-correo=ana@lopez.cr \
    --prefijo=SJ
```

Sin `--admin-clave` genera una y la muestra **una sola vez**.

### Lo que queda pendiente

El certificado digital `.p12`, su PIN y las credenciales de ATV. Son del
contribuyente y el sistema no los puede inventar. Hasta que el cliente los
cargue en *Configuración de la empresa*, opera guías y cobra con normalidad,
pero no emite comprobantes electrónicos — y la pantalla dice exactamente qué le
falta.

---

## Suspender, vencer y eliminar

**Suspender** es lo que se le hace a un cliente que deja de pagar. No puede
entrar, y a quien tenga la sesión abierta se le corta en la siguiente pantalla.
No se borra nada: sus comprobantes están transmitidos a Hacienda y tienen que
poder consultarse. Reactivar lo devuelve todo.

**Vencer** es lo mismo con fecha: el campo *Vence el* cierra la puerta solo,
llegado el día. Vacío = sin vencimiento.

**Eliminar** se lleva todo y no se deshace: guías, comprobantes, cajas y
usuarios. Existe para deshacer una empresa cargada por error, no para dar de
baja a un cliente. Por eso pide escribir el nombre exacto.

---

## Qué se separa y qué se comparte

Cada empresa tiene **lo suyo**: sedes, cajas, usuarios, clientes, tarifas,
tipos de bulto, impuestos, guías, cotizaciones, cierres, crédito, bitácora,
configuración fiscal y consecutivos.

Dos consecuencias que conviene tener claras:

- **Los prefijos de sede se repiten sin problema.** Dos transportistas pueden
  llamar `SJ` a su sede de San José. Cada uno numera desde el uno: la primera
  guía de un cliente nuevo es `SJ-LIM-00001`, aunque otro ya haya emitido mil.
- **El código guía dejó de ser único en el sistema.** Por eso el QR del recibo
  ahora lleva la empresa en la dirección:
  `/rastreo/transportes-lopez/SJ-LIM-00005`. Los recibos impresos antes siguen
  funcionando: si el código existe en una sola empresa se muestra, y si existe
  en varias el portal pregunta cuál.

Se comparte solo lo que no es de nadie: el correo y el nombre de usuario son
únicos en todo el sistema, porque son con lo que se entra y no habría a cuál de
dos empresas autenticar.

---

## Instalar desde cero

```bash
php artisan sistema:instalar
```

Deja dos accesos y los muestra una vez: el superadministrador y el
administrador de la primera empresa. Se pueden fijar por `.env`
(`SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`);
si se dejan vacíos, se generan.

### Actualizar una instalación que ya venía funcionando

```bash
php artisan migrate
```

La migración crea la empresa con el nombre que ya tenía la configuración fiscal
y le pasa todo lo existente. La operación no se entera: los mismos usuarios, las
mismas guías, los mismos consecutivos. Después, `php artisan db:seed --class=
Database\\Seeders\\ProduccionSeeder` agrega el superadministrador si falta.

La migración se puede volver a correr sin miedo: son veinte `ALTER TABLE`
seguidos y MySQL no los envuelve en una transacción, así que está escrita para
retomar donde quedó si el proceso muere a mitad de camino.

---

## Trabajo de fondo

El worker de la cola y las tareas programadas recorren **todas** las empresas,
una por una, fijando la empresa antes de cada pasada. Importa: si el envío a
Hacienda no lo hiciera, firmaría el comprobante de un cliente con el certificado
de otro.

Para atender a una sola:

```bash
php artisan hacienda:poll --empresa=transportes-lopez
php artisan credito:corte --empresa=3
php artisan guias:desecho --empresa=transportes-lopez
```

Si una empresa falla —certificado vencido, credenciales malas—, se reporta y se
sigue con las demás.

---

## Cómo está hecho el aislamiento

Una columna `company_id` y un ámbito global de Eloquent que la aplica en cada
consulta. Va ahí y no en cada pantalla porque es donde no se puede olvidar: una
pantalla nueva queda aislada sin que su autor tenga que acordarse.

Las piezas, por si hay que tocarlas:

| Archivo | Qué hace |
|---|---|
| `app/Support/CompanyContext.php` | qué empresa está operando |
| `app/Scopes/CompanyScope.php` | recorta cada consulta |
| `app/Models/Concerns/BelongsToCompany.php` | marca un modelo y llena `company_id` |
| `app/Rules/DeLaEmpresa.php` | valida que un id del formulario sea propio |
| `app/Http/Middleware/RequiresCompany.php` | la puerta entre los dos mundos |
| `app/Services/CompanyProvisioner.php` | el alta |
| `app/Services/CompanyEraser.php` | la baja definitiva |

Tres trampas que ya costaron una vez y conviene no repetir:

1. **`Rule::exists` no pasa por Eloquent.** Consulta la tabla en crudo y se
   salta el ámbito global. Para validar un id que llega de un formulario hay que
   usar `DeLaEmpresa::en('branches')`, no `exists:branches,id`.
2. **Las relaciones de `Company` van sin ámbito.** `$empresa->users()` tiene que
   traer los de ESA empresa, no los de la que esté operando.
3. **Autenticar no puede filtrar por empresa.** El correo del login puede ser de
   cualquier cliente; de eso se encarga `App\Auth\UserProviderSinEmpresa`.

---

## Probar

```bash
php artisan test                    # 780 pruebas: lógica, aislamiento, alta y baja
npm run test:e2e                    # 24 pruebas de navegador (Playwright)
```

Las de navegador necesitan la aplicación arriba (`docker compose up -d`) y
**Node 20 o superior** — Playwright no arranca en 18:

```bash
nvm use 20 && npm run test:e2e
```

No reinician la base: crean sus propias empresas con nombres únicos y barren las
de corridas anteriores al empezar. Son seguras de correr contra el entorno de
desarrollo.

Cubren lo que las pruebas de PHP no ven: que Livewire responda, que la sesión
sobreviva a cambiar de empresa y que el aislamiento siga puesto en una petición
HTTP de verdad. No es teórico — el error más grave de todo este cambio (el
contexto resolviéndose antes de que existiera la sesión, que dejaba el filtro
apagado en **todas** las peticiones del navegador) pasaba las 772 pruebas de PHP
y lo agarró Playwright.
