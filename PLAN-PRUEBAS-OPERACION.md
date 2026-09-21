# Plan de pruebas de operación

Qué se va a probar en el navegador, con qué escenarios y qué tiene que cuadrar
al final. Escrito para quien mantiene el sistema: sirve para entender por qué
cada prueba existe antes de leer su código, y para saber qué **no** cubre.

---

## Por qué en el navegador y no en PHP

Las 774 pruebas de PHP ya cubren la lógica pieza por pieza: el tarifario
calcula, el servicio de caja suma, el servicio de estados no deja saltarse un
paso. Lo que no cubren es el **recorrido completo con las manos de una
persona**: abrir la caja, recibir cinco paquetes distintos, armar el camión,
despacharlo, recibirlo del otro lado, entregar cobrando y cerrar el turno
cuadrado.

Ahí es donde aparecen los errores que ninguna prueba unitaria ve. Ya pasó una
vez en este mismo proyecto: el aislamiento por empresa quedaba apagado en
**todas** las peticiones del navegador y las 772 pruebas de PHP pasaban en
verde, porque en PHPUnit el usuario está puesto desde el principio y en una
petición real no.

El criterio de cada escenario de acá es el mismo: **la plata tiene que cuadrar
al final**. No alcanza con que la pantalla no reviente.

---

## Sobre qué corren

Cada archivo de prueba se da de alta **su propia empresa** desde el panel de
superadministrador, con nombre único, y trabaja adentro. Eso da tres cosas:

- **Aislamiento real.** Lo que hace una prueba no interfiere con otra ni con los
  datos de desarrollo.
- **Números limpios.** El arqueo arranca en cero y el consecutivo de guías en
  uno, así que se puede afirmar «el efectivo esperado son ₡43.500» y no «subió
  ₡43.500».
- **Repetible.** `php artisan e2e:preparar` barre las empresas de corridas
  anteriores antes de empezar.

Cada empresa nueva trae una sola sede, así que el primer paso de casi todos los
escenarios es crear la segunda: **una encomienda es un traslado entre sedes**, y
el sistema no deja que origen y destino sean el mismo.

---

## Escenario 1 · Facturas, todas sus variantes

**Archivo:** `tests/e2e/facturas.spec.js`

Tres decisiones independientes se cruzan en el mostrador y hay que probarlas por
separado, porque cada una manda la plata a un lugar distinto.

### 1.1 Qué pasa con el dinero (`cobro`)

| Variante | Quién paga | A dónde va | Qué hay que verificar |
|---|---|---|---|
| **Contado** (`prepaid`) | el remitente, ahora | caja de **origen** | entra al arqueo del turno abierto |
| **Por cobrar** (`collect`) | quien retira | caja de **destino**, al entregar | **NO** entra al arqueo de origen |
| **Crédito** (`credit`) | a la cuenta del cliente | ninguna caja | suma al saldo, no toca el arqueo |

Tres reglas que el sistema hace cumplir y que la prueba comprueba:

- Contado **sin caja abierta** se rechaza con un mensaje explícito. Sin eso, el
  cobro no entraría a ningún arqueo y la plata desaparecería del registro.
- Crédito **exige remitente registrado** como cliente de crédito: no se le puede
  fiar a un nombre suelto escrito a mano.
- Crédito **respeta el límite**: pasarse bloquea la guía.

### 1.2 Qué comprobante sale

| Variante | Cuándo | Qué exige |
|---|---|---|
| **Tiquete electrónico** | por defecto | nada más |
| **Factura electrónica** | se marca a mano | tipo de identificación + cédula de 9 a 12 dígitos del receptor |

Es una elección explícita y no deducida de si venía la cédula. La prueba
verifica que marcar «factura» sin cédula **no deja guardar**, con el mensaje que
explica por qué.

### 1.3 Cómo se paga

Los cinco medios: efectivo, tarjeta, SINPE Móvil, transferencia y otro. Importan
al cerrar la caja, y de eso se ocupa el escenario 4: **solo el efectivo está en
la gaveta**.

### 1.4 Cargos y descuentos

| Concepto | Qué se prueba |
|---|---|
| **Impuestos** | el IVA general del 13 % se aplica y se suma al total |
| **Seguro** | con valor declarado, aparece el porcentaje configurado |
| **Entrega a domicilio** | suma el cargo y **exige dirección exacta** |
| **Descuento** | resta del total |
| **Clave de descuento** | si la empresa la configuró, sin clave no se aplica |

El total tiene que dar: `bultos + seguro + domicilio − descuento + impuestos`.
Se comprueba contra el número que muestra la pantalla, no contra el que calcula
la prueba.

---

## Escenario 2 · Envíos, todas sus variantes

**Archivo:** `tests/e2e/envios.spec.js`

### 2.1 Tipo de envío

Paquete, sobre y documento. Cambia la tarifa que propone el tarifario.

### 2.2 El bulto

- **Tamaño:** pequeño, mediano, grande, extra grande.
- **Peso real** y **dimensiones** (largo × ancho × alto). El sistema calcula el
  **peso volumétrico** y factura por el mayor de los dos: una caja de plumas
  ocupa camión aunque no pese.
- **Varios bultos en una guía:** cada uno con su tipo, su tamaño y su precio; el
  subtotal es la suma.

### 2.3 El precio

- **Automático:** el tarifario propone según ruta, tipo y peso.
- **Manual:** el cajero lo pisa. Y al corregir el peso, el precio digitado a mano
  **no se pierde** — eso es un acuerdo puntual con el cliente, no un descuido.

### 2.4 El código guía

`PREFIJO_ORIGEN-PREFIJO_DESTINO-CONSECUTIVO`. Se verifica que:

- la primera guía de la empresa es `SJO-LIM-00001`;
- el consecutivo es **por ruta**: `SJO-LIM-00002` no altera a `LIM-SJO-00001`;
- la etiqueta y el recibo térmico se generan y traen el código.

---

## Escenario 3 · Manifiestos y entregas

**Archivo:** `tests/e2e/manifiestos.spec.js`

El recorrido completo de un paquete, con los diez estados del sistema.

### 3.1 Armar y despachar

1. La guía nace **Recibido**; se pasa a **Listo para envío**.
2. Se crea el cierre: origen, destino, chofer (un usuario del sistema o un
   nombre libre, para el chofer contratado) y placa.
3. Se agregan guías. **Solo aparecen las de esa ruta**: es lo que impide cargar
   en el camión de Limón un paquete que va a Pérez Zeledón. Una guía recién
   recibida se puede cargar sin marcarla «Listo» antes —en el mostrador se
   recibe y se manda en el mismo movimiento—.
4. Se quita una y vuelve a estar disponible.
5. Una guía ya cargada **deja de ofrecerse**: no puede ir en dos camiones.
6. Se despacha → todas sus guías pasan a **Enviado**.

### 3.2 Recibir en destino

1. Recepción **por código**, que es lo que hace el escáner: se digita el código
   guía y la línea se marca recibida.
2. Recepción **marcando a mano**, para cuando el código no se lee.
3. Se cierra la recepción → las recibidas pasan a **Llegó al destino**.

Y un código que no va en ese camión **se rechaza**: no se puede marcar como
recibida una guía que el manifiesto no traía.

### 3.3 Entregar

| Variante | Qué se prueba |
|---|---|
| **Entrega normal** | nombre de quien retira + identificación + firma |
| **Sin nombre** | se rechaza: la entrega necesita constancia de a quién |
| **Por cobrar sin caja** | se rechaza **antes** de mover nada, con el monto en el mensaje |
| **Por cobrar con caja** | entrega y cobro en el mismo acto, al arqueo de destino |
| **Incidencia** | destinatario ausente: queda registrada y se puede resolver |
| **Anulación** | con motivo obligatorio, queda en la bitácora con quién la anuló |

Y el **rastreo público** de esa guía muestra el recorrido completo sin exponer
datos personales: nombre parcial (`Jose F.`), sin montos.

---

## Escenario 4 · Caja: abrir, operar, cerrar y cuadrar

**Archivo:** `tests/e2e/caja.spec.js`

Es el escenario que más importa, porque es donde el error cuesta plata.

### 4.1 El turno

1. **Abrir** con fondo inicial.
2. No se puede abrir **dos turnos** en la misma caja.
3. No se puede vender apoyándose en el **turno de un compañero**: el cobro tiene
   que caer en el arqueo de quien responde por él.

### 4.2 Lo que entra y sale

| Movimiento | Efecto en el efectivo esperado |
|---|---|
| Fondo inicial | suma |
| Cobro en **efectivo** | suma |
| Cobro con **tarjeta / SINPE / transferencia** | **no suma** — no está en la gaveta |
| **Entrada** de efectivo (reposición de sencillo) | suma |
| **Salida** de efectivo (pago de mensajería) | resta |
| Guía **por cobrar** creada en esta sede | **no suma** — se cobra en destino |
| Guía a **crédito** | **no suma** — va a la cuenta del cliente |

Toda entrada o salida **exige motivo**: sin eso, un faltante no se puede
explicar al día siguiente.

### 4.3 El arqueo

Se cuenta por denominación (₡20.000 … ₡5). La prueba arma tres cierres
distintos:

| Cierre | Qué se cuenta | Resultado esperado |
|---|---|---|
| **Cuadrado** | exactamente lo esperado | sin diferencia |
| **Faltante** | de menos | la diferencia sale marcada, y la nota de explicación es obligatoria |
| **Sobrante** | de más | la diferencia sale marcada |

### 4.4 La cuenta completa

El escenario final es un turno de verdad, con todo mezclado:

```
Fondo inicial                    ₡ 20.000
+ Guía 1, contado, efectivo      ₡  3.500
+ Guía 2, contado, efectivo      ₡  5.000
+ Guía 3, contado, TARJETA       ₡ 12.000   → no entra al efectivo
+ Guía 4, POR COBRAR             ₡  4.000   → no entra: se cobra en destino
+ Guía 5, CRÉDITO                ₡  8.000   → no entra: va a la cuenta
+ Entrada (reposición)           ₡  2.000
− Salida (mensajería)            ₡  1.500
─────────────────────────────────────────
Efectivo esperado en gaveta      ₡ 29.000
Total vendido en el turno        ₡ 32.500
```

La prueba cuenta ₡29.000 en billetes y verifica que **cuadra**, y que el reporte
de cierre separa los medios de pago. Es la afirmación central de todo el plan:
**lo que el sistema dice que hay en la gaveta es lo que hay en la gaveta.**

### 4.5 El cobro del otro lado

La guía 4 (por cobrar) sigue viva. Se despacha, se recibe en destino, se abre la
caja de allá y se entrega: **ahí** entran los ₡4.000, al arqueo de destino y no
al de origen.

---

## Escenario 5 · Tarifario

**Archivo:** `tests/e2e/tarifario.spec.js`

Cuánto se cobra por llevar un paquete, y por qué. Cada prueba es un ejemplo de
una regla, y leerlas en orden es la forma más rápida de entender el tarifario.

### Qué es una tarifa

Una tarifa dice: **«de ESTA sede a ESTA otra, para ESTE tipo de envío, entre
ESTE y ESTE peso, se cobra ESTO»**. Las tres primeras condiciones se pueden
dejar en blanco, y eso significa «para todas»: así se tiene una tarifa base sin
declarar las treinta combinaciones de sedes.

### Las reglas, una por una

| # | Regla | Ejemplo |
|---|---|---|
| 0 | Sin tarifa no se inventa un precio | avisa y el cajero lo digita |
| 1 | Sin ruta declarada, aplica a todas | una «Base nacional» cubre ida y vuelta |
| 2 | El tope de la banda es **exclusivo** | con 0–1 y 1–5, un kilo exacto cae en la segunda |
| 3 | La banda sin tope cobra por kilo de más | 5 kg = ₡5.000; 8,2 kg = ₡5.000 + 4 × ₡800 |
| 4 | Se cobra por el **mayor** entre peso real y volumétrico | 40×40×40 cm = 12,8 kg aunque pese 2 |
| 5 | La tarifa más específica gana | ruta (4 pts) > destino (2) > tipo (1) |

El peso volumétrico es `largo × ancho × alto ÷ 5.000`, en centímetros. El
divisor es una convención del sector y se cambia por configuración.

La tarifa de ruta es **de ida**: `SJO → LIM` no cubre `LIM → SJO`. Si se quiere
en los dos sentidos, hay que declararla dos veces.

### En el mostrador

Al poner el peso, el precio aparece solo — y es una **propuesta**: el cajero
puede pisarla. Un precio digitado a mano **no se pierde** al corregir el peso,
porque es un acuerdo puntual con el cliente y no un descuido.

La pantalla de Tarifario trae un **probador** («Probar una cotización») que
responde «¿qué tarifa gana y por qué?» sin tener que crear una guía de mentira.
Es la herramienta para entenderlo, y es la que usan estas pruebas.

---

## Qué NO cubren estas pruebas, y por qué

- **Transmisión real a Hacienda.** Firmar y transmitir necesita el certificado
  `.p12` del contribuyente y el ambiente de pruebas del Ministerio. Se prueba
  que el comprobante se **arma** y queda en la cola; el envío ya está cubierto
  por las pruebas de PHP con respuestas simuladas.
- **Impresión física.** Se verifica que el recibo, la etiqueta y los PDF se
  **generan**; que la impresora térmica los saque es del hardware.
- **Escaneo con cámara.** Se prueba la recepción por código digitado, que es el
  mismo camino que usa el escáner; la cámara necesita un dispositivo real.
- **Correos.** Se verifica que la guía los dispara; la entrega es del servidor
  de correo.
- **Corte de crédito por fecha.** Lo cubren las pruebas de PHP, que pueden
  adelantar el reloj; un navegador no.

---

## Lo que encontraron al escribirlas

Tres cosas que las pruebas de PHP no veían:

1. **Una tarifa con «Todos los tipos» no se aplicaba nunca.** La pantalla la
   guardaba con el tipo de envío en cadena vacía en vez de nulo, y el tarifario
   busca las comodines con `whereNull`. Quedaba en la lista, con su precio, sin
   aplicarse a una sola guía. *Corregido* en `RateIndex::save()`, más una
   migración que repara las que se cargaron antes.
2. **Entre dos tarifas igual de específicas gana la de banda más ancha**, no la
   más estrecha como promete el comentario del código. Una promoción de 3–5 kg
   encima de una base de 0–20 nunca se aplica. Queda documentado en la prueba;
   **no se cambió**, porque tocarlo cambia precios y esa es una decisión
   comercial.
3. **La configuración de la empresa no se guarda sin la identidad fiscal
   completa** —cédula, actividad económica y ubicación—. Es correcto, porque
   Hacienda las exige, pero significa que una empresa recién dada de alta no
   puede tocar ni el porcentaje de seguro hasta llenarlas.

Y dos detalles menores: el tipo de envío se captura y se usa para cotizar pero
no se muestra en el detalle de la guía, y el aviso de «Configuración guardada»
no llega a verse porque Livewire no vuelve a dibujar el layout.

---

## Cómo correrlas

```bash
docker compose up -d
nvm use 20                      # Playwright no arranca en Node 18
npm run test:e2e                # todas, con reporte HTML
npm run test:e2e:report         # abrir el reporte
```

El reporte HTML queda en `playwright-report/` con el rastro, el video y las
capturas de cada prueba que falle: se puede recorrer paso a paso lo que hizo el
navegador.

Para un escenario suelto:

```bash
npx playwright test tests/e2e/caja.spec.js
npx playwright test --headed     # viendo el navegador
```
