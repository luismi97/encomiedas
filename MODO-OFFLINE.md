# Modo sin conexión · Encomiendas CR

Cómo los cajeros siguen recibiendo encomiendas cuando se cae el internet, y
cómo esas guías entran al sistema al volver. Escrito para quien mantiene y da
soporte al sistema. Es una adaptación del POS offline de retailpos: mismas
piezas, mismo contrato de sincronización.

---

## Qué hace y qué no

Sin internet, el cajero sigue en el navegador y registra la guía completa:
ruta, remitente, destinatario, bultos con el precio del tarifario, seguro,
domicilio, descuento con clave, y cobro de contado, por cobrar o a crédito. Se
imprime un **comprobante provisional** (`OFF-SJ-7`) y la guía queda en el
navegador. Al volver la red se sube sola y queda **idéntica a una hecha en
línea**: mismo código guía, misma bitácora, mismo cobro en caja, mismo
comprobante electrónico al entregarse.

Lo que **no** hace sin conexión:

| | Por qué |
|---|---|
| Imprimir la etiqueta del paquete | El código guía (`SJ-LIM-00005`) lo asigna el servidor con un consecutivo por ruta. La etiqueta se imprime al sincronizar, desde la lista «Sincronizadas — falta imprimir la etiqueta». El paquete no puede salir antes de todas formas: los cierres de envío son en línea. |
| Buscar clientes registrados | Solo se guardan los de crédito (hace falta elegir a quién cargarle el saldo). Los demás se digitan. |
| Factura electrónica | Sale tiquete. El receptor se elige en línea. |
| Asignar repartidor, editar o anular | Son operaciones sobre guías que ya existen en el servidor. |

## Cómo se enciende

**Configuración → Modo sin conexión**, por empresa. Apagado por defecto.

Aplica a quien cumpla todo esto (`App\Support\ModoOffline::habilitado()`):
empresa con el modo encendido, rol administrador o cajero, y sede asignada. Sin
sede no hay origen para la guía ni caja donde registrar el cobro.

⚠️ **No duplicar esa condición.** La usan el controlador y el watchdog. Si
divergen, un usuario termina con las tarifas en el navegador y redirigido a una
pantalla que el servidor le rechaza.

Requiere **HTTPS** (o `localhost`): sin eso el navegador no registra el service
worker ni ofrece Web Crypto para la clave de descuentos.

El formulario de Nueva guía muestra si el equipo está listo («Listo para
trabajar sin conexión»). Hacen falta tres cosas, y el indicador comprueba las
tres de verdad: el service worker controlando la pestaña, la pantalla offline
en su cache y las tarifas en `localStorage`. Un equipo recién encendido
necesita abrir el sistema una vez con internet.

## Las piezas

| Pieza | Archivo | Rol |
|---|---|---|
| Interruptor | `app/Support/ModoOffline.php` | Única fuente de verdad |
| Watchdog | `resources/views/offline/watchdog.blade.php` (en el `<head>` del layout) | Registra el SW, guarda el snapshot, detecta la caída, redirige, sube la cola, pinta los avisos |
| Núcleo de la cola | `public/js/guias-offline.js` | Cola, snapshot y sincronización. Lo usan el watchdog y la pantalla: una sola copia |
| Service worker | `public/sw-guias.js` | Sirve `/guias-offline` sin red; redirige ahí cualquier navegación que falle |
| Pantalla | `resources/views/offline/guias.blade.php` | Formulario en JS puro: sin Livewire ni Alpine |
| Servidor | `app/Http/Controllers/GuiasOfflineController.php` | `page()` / `data()` (snapshot) / `sync()` |
| Guardado | `app/Services/RegistroDeGuia.php` | Lo usan el formulario en línea y `sync()`: por eso quedan iguales |
| Latido | `app/Http/Controllers/PingController.php` (`/__ping`, fuera del grupo `web`) | Mide red, no la aplicación |

La detección **no confía en `navigator.onLine`**: late contra `/__ping` cada 6
s (dos fallos seguidos = sin red), engancha el hook `request` de Livewire y
vigila los `wire:navigate` que se cuelgan. Tiene gracia de 30 s al volver y
cortacircuito a los 4 rebotes por minuto.

### localStorage

| Clave | Contenido |
|---|---|
| `enc_offline_snapshot` | Sedes, rutas, tarifas, impuestos, tipos de bulto, clientes de crédito con su saldo, verificador de la clave de descuentos, papel de la caja |
| `enc_offline_cola` | Guías por subir |
| `enc_offline_fallidas` | Rechazadas para siempre; se ven en la pantalla y se reintentan o descartan a mano |
| `enc_offline_listas` | Sincronizadas cuya etiqueta no se marcó como pegada |
| `enc_offline_seq` | Consecutivo **local** del comprobante provisional |

## Contrato de `sync()`

Un resultado por guía. **La diferencia entre `failed` y `error` es de diseño:**

| Estado | Significado | Efecto en la cola |
|---|---|---|
| `synced` | Se creó | Sale; pasa a «falta imprimir la etiqueta» |
| `duplicate` | Ya existía con ese `client_uuid` | Igual que `synced` |
| `failed` | **Nunca** va a entrar: no cuadra, sede o tipo de bulto inexistente | Sale a «Rechazadas» |
| `error` | Corregible: caja cerrada, límite de crédito, cliente o impuesto borrado | **Se queda** y se reintenta |

Marcar `failed` algo corregible saca la guía de la cola para siempre. Al
agregar un rechazo nuevo: si un administrador lo puede arreglar, es `error`.

## Invariantes

1. **Idempotencia por `client_uuid`** (índice único en `invoices`). Es lo que
   impide duplicar por reintentos, dos pestañas o dos equipos. No quitarlo ni
   generar el uuid en el servidor.
2. **Se guarda lo que se cobró**, no lo que diga el tarifario hoy. `sync()`
   solo comprueba que la guía cuadre consigo misma (`noCuadra()`); no recotiza.
3. **La hora es la de recepción.** El navegador manda UTC y se pasa a la zona
   de la app; `created_at` y la primera fila de la bitácora llevan esa hora.
4. **La guía es de quien la recibió** (`sold_by`), no de quien sincroniza, si
   es un usuario activo de la misma empresa.
5. **El tarifario del navegador copia el comportamiento, no el comentario.**
   Entre dos tarifas igual de específicas gana la de `min_weight` más bajo,
   igual que `Tarifario::buscar()`. Si se corrige allá, corregir `tarifa()` en
   la pantalla.
6. **Al cambiar `public/js/guias-offline.js` o el HTML de la pantalla, subir
   la versión del cache en `public/sw-guias.js`.** Se sirven desde cache y un
   equipo que solo los abre sin red se queda con la versión vieja.

## Caja, crédito y descuentos

- **El cobro de contado entra al arqueo al sincronizar**, en la caja abierta de
  quien lo cobró. Sin caja abierta la guía espera en la cola (`error`) hasta
  que la abra. Cerrar la caja con guías en cola deja esos cobros fuera del
  turno: la tarjeta de cierre lo avisa.
- **Quien no cobra** deja la guía pendiente de pago en caja, igual que en línea.
- **Crédito:** el navegador controla el límite con el saldo del snapshot más
  lo que ya cargó sin conexión. Al sincronizar se vuelve a comprobar con el
  saldo real; si ya no alcanza, la guía espera (`error`) hasta que se suba el
  límite o se pague.
- **Clave de descuentos:** se guarda cifrada y el navegador no puede
  descifrarla. Viaja un verificador PBKDF2-SHA256 (210.000 iteraciones,
  `CompanySetting::verificadorDeDescuento()`) que Web Crypto recalcula. Se
  deriva solo, también para las empresas que ya tenían clave, y se renueva al
  cambiarla. Una clave corta es atacable desde la consola por quien tenga el
  equipo: es el precio de verificar sin servidor.

## Apagar el modo

El watchdog borra el snapshot, desregistra el service worker y purga su cache.
**La cola no se toca**: son paquetes que ya se recibieron. Mientras el modo
esté apagado `sync()` responde 403 y esas guías se quedan en el navegador;
encenderlo de nuevo las sube.
