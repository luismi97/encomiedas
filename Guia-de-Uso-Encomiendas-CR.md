# Guía de uso · Encomiendas CR

Manual de operación del sistema, módulo por módulo. Escrito para quien lo usa
todos los días: cajero, repartidor y administrador.

---

## Índice

**Antes de empezar**
1. [Roles y qué ve cada uno](#1-roles-y-qué-ve-cada-uno)
2. [Entrar al sistema](#2-entrar-al-sistema)

**Operación diaria**
3. [Inicio](#3-inicio)
4. [Caja](#4-caja)
5. [Guías (encomiendas)](#5-guías-encomiendas)
6. [Impresión: recibo y etiqueta](#6-impresión-recibo-y-etiqueta)
7. [Cierres de envío](#7-cierres-de-envío)
8. [Mi ruta (chofer)](#8-mi-ruta-chofer)
9. [Clientes](#9-clientes)
10. [Crédito](#10-crédito)
11. [Reportes](#11-reportes)

**Administración**
12. [Facturación electrónica](#12-facturación-electrónica)
13. [Sucursales](#13-sucursales)
14. [Cajas](#14-cajas)
15. [Tarifario](#15-tarifario)
16. [Tipos de bulto](#16-tipos-de-bulto)
17. [Impuestos](#17-impuestos)
18. [Usuarios](#18-usuarios)
19. [Bitácora](#19-bitácora)
20. [Datos de la empresa](#20-datos-de-la-empresa)

**Referencia**
21. [Rastreo público](#21-rastreo-público)
22. [Correos que envía el sistema](#22-correos-que-envía-el-sistema)
23. [Tareas automáticas](#23-tareas-automáticas)
24. [Preguntas frecuentes](#24-preguntas-frecuentes)

---

## 1. Roles y qué ve cada uno

Hay tres roles. El menú lateral cambia según cuál tenga el usuario.

| Módulo | Administrador | Cajero | Repartidor |
|---|:---:|:---:|:---:|
| Inicio | ✅ | ✅ | ✅ |
| Guías (ver, imprimir) | ✅ | ✅ | ✅ |
| Crear y editar guías (recibir paquetería) | ✅ | ✅ | — |
| Mi ruta | ✅ | — | ✅ |
| Caja | ✅ | ✅ | — |
| Cierres de envío | ✅ | ✅ | — |
| Clientes, Crédito, Reportes | ✅ | ✅ | — |
| Toda la administración | ✅ | — | — |

**El cajero solo ve lo de su sede.** No es un filtro de pantalla: la restricción
está en la consulta, así que aplica en todo listado, reporte y búsqueda. Un
administrador ve todas las sedes.

**El repartidor solo ve las guías que trae asignadas.** Si intenta abrir o
imprimir una que no es suya, el sistema se lo niega.

---

## 2. Entrar al sistema

Se entra con **usuario o correo** y contraseña.

**Olvidé mi contraseña** → se pide el correo y llega un enlace para
restablecerla. El formulario responde lo mismo exista o no la cuenta: es a
propósito, para que nadie pueda averiguar qué correos están registrados.

---

## 3. Inicio

El tablero de entrada. Muestra, de un vistazo:

- Guías recibidas hoy
- Pendientes
- En camino
- Entregadas
- Comprobantes pendientes de enviar a Hacienda
- Las últimas guías registradas

Es solo lectura: sirve para saber cómo va el día, no para operar.

---

## 4. Caja

> **Regla que sostiene todo el módulo: no se cobra de contado sin una caja
> abierta.** Sin eso el arqueo no significa nada, porque siempre habría cobros
> que el sistema no vio.

### Abrir el turno

1. Entrá a **Caja**.
2. Elegí la caja en el selector de arriba (si la sede tiene varias).
3. Digitá el **fondo inicial**: el efectivo con el que arranca la gaveta.
4. **Abrir caja**.

El sistema arranca preseleccionando una caja **libre** de tu sede. Si ya tenés
un turno abierto, te devuelve al tuyo.

Una misma caja no puede tener dos turnos abiertos a la vez. Dos cajas distintas
de la misma sede sí pueden estar abiertas al mismo tiempo — es justamente para
eso que existen varias.

### Durante el turno

Todo cobro de contado de una guía entra solo al turno abierto. Además se pueden
registrar a mano:

- **Entradas** de efectivo (por ejemplo, un vuelto que se repone)
- **Salidas** de efectivo (una compra, un adelanto)

Toda salida **exige un motivo**. Sin motivo no se registra.

El panel muestra en vivo el **efectivo esperado** (fondo + cobros en efectivo
+ entradas − salidas) y el desglose por medio de pago.

### Cerrar el turno (arqueo)

1. **Hacer arqueo**.
2. Contá el efectivo y digitá **cuántas piezas hay de cada denominación**
   (₡20.000, ₡10.000, … ₡5). El sistema va sumando mientras digitás.
3. **Cerrar turno**.

El resultado se compara contra lo esperado:

- **Cuadra** → confirmación y listo.
- **Faltante** o **sobrante** → se muestra la diferencia y queda registrada.
  El turno se cierra igual: el descuadre se documenta, no se esconde.

Se puede descargar el **reporte del turno** en PDF, con el arqueo y espacio para
firmas.

---

## 5. Guías (encomiendas)

Una **guía** es una encomienda: el traslado de uno o varios bultos de una sede a
otra.

### Crear una guía

**Guías → Nueva guía.**

**Ruta.** Sede de origen y de destino. Tienen que ser **distintas**: una
encomienda es un traslado entre sedes, y el código se arma con los dos prefijos.

**Remitente y destinatario.** Nombre, teléfono e identificación. Se pueden
buscar entre los clientes ya registrados o digitar directo.

Si ponés el **correo del destinatario**, el sistema le avisa automáticamente
cuando el paquete llegue al destino y cuando se entregue.

**Bultos.** Uno por cada paquete físico. De cada uno:

| Campo | Para qué sirve |
|---|---|
| **Tipo de bulto** | Qué es: paquete, caja, sobre, herramienta… Viene preseleccionado |
| **Tamaño** | S, M, L, XL |
| **Peso** | En kilogramos |
| **Largo / ancho / alto** | En cm. Sirven para el peso volumétrico |
| **Descripción** | Contenido, opcional |
| **Precio** | Lo que se cobra por ese bulto |

Con **Agregar bulto** se suman más renglones.

> Si el tipo de bulto está marcado como **frágil**, la etiqueta que se pega al
> paquete sale con el aviso `FRÁGIL · MANEJAR CON CUIDADO`.

**¿Cómo se paga esta guía?** Una sola decisión, y de ella depende a qué caja
entra la plata:

| Opción | Quién paga | Qué pasa con el dinero |
|---|---|---|
| **Pagado** | El remitente, ahora | Entra al arqueo de **esta** caja |
| **Por cobrar** | Quien la retira, en destino | No entra acá. Se cobra al entregar, en la caja de **destino** |
| **A crédito** | Nadie, por ahora | Suma al **saldo del cliente**. Se factura en el próximo corte |

«A crédito» solo se habilita si elegiste al remitente entre los clientes con
convenio. Al elegirlo, la pantalla muestra cuánto debe y cuánto le queda de
cupo, y **no deja pasar** una guía que se salga del límite.

Un flete **por cobrar** sale marcado en la etiqueta con un recuadro negro que
dice `POR COBRAR` y el monto: es lo primero que ve quien entrega.

**Factura electrónica.** Si marcás la casilla, se pide la cédula del receptor y
se emite **Factura Electrónica**. Si no la marcás, se emite **Tiquete
Electrónico**, que no requiere identificación.

Al guardar, el sistema asigna el código: **`SJ-LIM-00005`** — prefijo de origen,
prefijo de destino y consecutivo de esa ruta.

### Los 10 estados

```
Recibido → Listo para envío → Enviado → En camino → Llegó al destino → Entregado
                                                          ↓
                                                  Próximo a desecho → Desechado
```

| Estado | Significa |
|---|---|
| **Recibido** | Está en la sede de origen |
| **Listo para envío** | Preparado para salir |
| **Enviado** | Salió en un cierre de envío |
| **En camino** | En tránsito |
| **Llegó al destino** | Está en la sede destino, esperando que lo retiren |
| **Entregado** | Se lo llevó el destinatario |
| **Próximo a desecho** | Lleva mucho tiempo sin retirar |
| **Desechado** | Se dispuso del paquete |
| **Devuelto** | Regresó al remitente |
| **Anulado** | Se canceló |

**No se puede saltar de un estado a cualquier otro.** El sistema solo permite
los pasos válidos: eso evita que un escaneo mal hecho mande una guía entregada
de vuelta a «recibido». Entregado, Desechado, Devuelto y Anulado son finales.

**Todo cambio pide confirmación**, tanto desde el listado como desde el detalle.
Ninguno se deshace y todos quedan firmados en la bitácora.

Cada cambio queda en la bitácora, con quién, cuándo y en qué sede.

### Entregar

Desde el detalle de la guía. Se registra **quién retiró**: nombre e
identificación. Por eso no se puede entregar desde el listado — hace falta ese
dato.

Al entregar, el comprobante electrónico queda **en cola para enviarse a
Hacienda**.

### Anular

Exige **motivo**. Queda registrado quién anuló y por qué.

### Incidencias

Desde el detalle se puede **reportar una incidencia** (paquete dañado, faltante,
dirección errónea) y después marcarla como resuelta.

### El listado

Una tabla con una guía por fila: código, ruta, remitente y destinatario, estado,
**cómo se cobra** y total. Las acciones van agrupadas a la derecha —los tres
impresos primero, que es lo que más se repite en mostrador, y después los
cambios de estado disponibles.

Se filtra por **período** (hoy, esta semana, este mes, rango de fechas), por
**estado** y por **sucursal**, con búsqueda libre por código, remitente o
destinatario. En el celular las mismas guías se ven como tarjetas.

---

## 6. Impresión: recibo y etiqueta

Son **dos impresos distintos**, con dos botones distintos.

### Recibo

Se lo lleva **el cliente**. Lleva los montos, el detalle de bultos y un **QR**
que abre el rastreo público.

Cada reimpresión queda registrada y se marca como **COPIA**: dos recibos iguales
sin marca es exactamente el fraude que eso evita.

### Etiqueta del paquete

Se **pega al bulto**. Lleva:

- La **sede destino en letra grande** — es lo que se lee al cargar el camión
- El **código de barras** del código guía, escaneable
- **BULTO 1 DE 3** y el tipo
- Destinatario, remitente y origen
- `POR COBRAR` con el monto, en recuadro negro, si el flete no está pagado
- El aviso de **frágil**, si aplica

**No lleva montos**, a propósito: la etiqueta queda a la vista de cualquiera que
manipule el paquete.

Sale **una etiqueta por bulto**. Si la guía trae tres paquetes, se imprimen tres:
se separan en bodega y cada uno necesita la suya.

### Impresoras

Ambos impresos salen desde el navegador contra la impresora del sistema — no
hace falta instalar nada. El ancho de rollo (**58 o 80 mm**) se toma del
configurado en la sede que imprime, y se puede forzar agregando `?ancho=58` o
`?ancho=80` a la dirección.

---

## 7. Cierres de envío

Un **cierre** es el manifiesto de lo que sale de una sede hacia otra en un
viaje.

### Preparar y despachar

1. **Cierres de envío → Nuevo cierre.** Sede origen y destino (distintas),
   chofer y placa.
2. **Agregar guías** al cierre. Solo aparecen las que están listas.
3. **Despachar.** Las guías pasan a *Enviado* y el cierre queda *En ruta*.

Se puede imprimir el **manifiesto en PDF** para que viaje con el chofer.

### Recibir en destino

En la sede destino se abre el cierre y se van marcando las guías que llegaron
— **escaneando el código de barras de la etiqueta** o digitando el código.

Al **cerrar la recepción**, el sistema informa si hubo **faltantes**: guías que
salieron en el manifiesto y no llegaron. Eso es lo que convierte al cierre en un
control y no en un papel.

### Estados del cierre

| Estado | Significa |
|---|---|
| **En preparación** | Se le están agregando guías |
| **En ruta** | Ya salió |
| **Recibido en destino** | Se completó la recepción |

---

## 8. Mi ruta (chofer)

Pantalla pensada para el celular, en la calle. El repartidor **solo ve el cierre
que trae asignado**.

Desde ahí puede:

- **Escanear** guías con el código de barras de la etiqueta
- **Registrar la entrega**: quién retiró, nombre e identificación
- **Reportar una incidencia** si algo salió mal

---

## 9. Clientes

Registro de remitentes y destinatarios frecuentes, para no volver a digitar sus
datos en cada guía.

De cada cliente: nombre, identificación, teléfono, correo y dirección.

Los clientes con **convenio de crédito** llevan además:

- **Límite de crédito**: hasta cuánto pueden deber
- **Día de corte**: qué día del mes se les corta el estado de cuenta

---

## 10. Crédito

Para los clientes que no pagan en el momento sino contra estado de cuenta.

### Cómo entra una guía al saldo

Al crear la guía se elige al **remitente** entre los clientes registrados. Si
ese cliente tiene convenio, la pantalla propone **«A crédito»** y muestra su
saldo. Al guardar, el total de la guía **suma a su saldo** y queda esperando el
corte. Esa guía no entra a ninguna caja: no se cobró.

Si el cliente ya llegó a su límite, la guía **no se guarda** y la pantalla dice
cuánto le queda y cuánto suma la guía. Para pasarla igual hay que subirle el
límite o recibirle un abono.

**Saldo total** = lo ya facturado en estados de cuenta + lo que aún no se ha
cortado.

### Cortar

Genera el **estado de cuenta** del período: agrupa todas las guías a crédito
pendientes y las deja en un solo documento, descargable en PDF.

### Abonar

Registra un pago contra un estado de cuenta. Se puede abonar parcial.

### Antigüedad de saldos

Muestra cuánto se debe y desde hace cuánto, en tramos. Sirve para saber a quién
hay que cobrarle primero.

---

## 11. Reportes

Ocho reportes, todos filtrables por **período** y por **sede**:

| Reporte | Responde a |
|---|---|
| **Guías por estado** | ¿Cuántas hay en cada estado y por cuánto monto? |
| **Próximas a desecho y desechadas** | ¿Qué lleva mucho tiempo sin retirar? |
| **Ventas de contado** | ¿Cuánto se cobró y por qué medio de pago? |
| **Cuentas por cobrar** | ¿Cuánto nos deben y desde cuándo? |
| **Cierres de caja** | ¿Qué turnos descuadraron y por cuánto? |
| **Facturación electrónica** | ¿Cuántos comprobantes aceptados, rechazados, pendientes? |
| **Volumen por ruta** | ¿Qué rutas mueven más? |
| **Tiempo promedio de entrega** | ¿Cuánto tardamos de origen a entrega? |

---

## 12. Facturación electrónica

> Solo administración.

El sistema emite comprobantes electrónicos ante Hacienda de Costa Rica
(versión 4.4).

### Cómo funciona

Cuando una guía se **entrega**, su comprobante se reserva y queda **pendiente de
envío**. Nunca se transmite solo: pasa por la cola.

Un proceso automático revisa cada minuto los comprobantes enviados y actualiza
su estado con la respuesta de Hacienda.

### Pendientes de envío

En esta pantalla se ven los comprobantes por estado. Desde ahí se puede:

- **Enviar uno** o **enviar varios seleccionados**
- **Ver el motivo del rechazo**, con el código y el mensaje que devolvió Hacienda
- **Reintentar** los que fallaron

### Tipos de comprobante

| Tipo | Cuándo |
|---|---|
| **Tiquete Electrónico** | Por defecto. No requiere identificación del cliente |
| **Factura Electrónica** | Cuando se marca «factura electrónica» y se da la cédula |
| **Nota de crédito** | Para corregir o anular un comprobante ya aceptado |
| **Nota de débito** | Para aumentar el monto de uno ya aceptado |

Las notas se emiten desde el detalle de la guía.

---

## 13. Sucursales

> Solo administración.

De cada sede se configura:

| Campo | Para qué |
|---|---|
| **Nombre** | Cómo se llama |
| **Prefijo** | 2 a 4 letras. **Va en el código guía**: `SJ-LIM-00005` |
| **Código de sucursal y terminal** | Los que exige Hacienda (001 / 00001) |
| **Dirección, provincia, cantón, distrito** | Ubicación fiscal |
| **Teléfono** | Sale en el recibo |
| **Ancho de rollo** | 58 u 80 mm, el de la impresora de ese mostrador |
| **Horario** | Por día de la semana |

**El prefijo es único** entre sedes y es obligatorio: son de 2 a 4 letras, sin
números ni espacios. Es lo que arma el código de cada guía, así que conviene que
sea reconocible (`SJ`, `LIM`, `HER`).

Cada sede nueva **nace con su «Caja principal»** automáticamente.

Una sede con encomiendas en curso **no se puede desactivar** hasta cerrarlas o
reasignarlas.

---

## 14. Cajas

> Solo administración.

Una sede puede tener **varias cajas**: «Mostrador 1», «Mostrador 2». Cada una
lleva su propio turno y su propio arqueo — dos cajeros cobrando sobre la misma
gaveta hacen que el faltante de uno aparezca en el conteo del otro.

El nombre es **único dentro de la sede**. Entre sedes distintas sí puede
repetirse.

**Lo que el sistema no deja hacer, y por qué:**

- **Desactivar una caja con turno abierto** → los cobros del día se quedarían
  sin forma de llegar al arqueo. Cerrá el arqueo primero.
- **Eliminar una caja con turnos registrados** → un arqueo cerrado es un
  documento contable. Desactivala: deja de ofrecerse sin borrar el historial.
- **Eliminar la única caja de una sede** → esa sede no podría cobrar de contado.
- **Cambiar de sede una caja con historial** → sus arqueos quedarían
  contabilizados donde no ocurrieron.

---

## 15. Tarifario

> Solo administración.

Define el precio por **ruta** y **rango de peso**.

De cada tarifa:

- **Sede origen y destino** — dejar «cualquiera» crea una tarifa base sin tener
  que declarar todas las combinaciones
- **Tipo de envío**
- **Peso desde / hasta** — vacío en «hasta» es un tramo abierto
- **Precio** y **₡ por kilo adicional**

### Peso volumétrico

Se cobra por el **mayor** entre el peso real y el volumétrico. El volumétrico
sale de `(largo × ancho × alto) ÷ divisor`, con el divisor configurado en el
sistema (por defecto 5000).

Es lo que evita cobrar como liviano un paquete enorme lleno de aire.

### Se aplica sola

En el formulario de guías **no hay que presionar nada**: apenas se elige la ruta
y se digita el peso o las medidas, el precio de cada bulto se llena con la
tarifa que corresponde.

Si el cajero **pisa el precio a mano**, ese queda: corregir después el peso ya
no se lo borra. Es lo que permite un acuerdo puntual sin pelear con la tabla.

### Probar una cotización

Hay un cotizador en la pantalla del tarifario: se digita ruta, peso y medidas, y
muestra **qué tarifa gana** y por qué. Sirve para verificarlo antes de que lo
use un cajero.

> Un tramo sin peso máximo **exige** un cobro por kilo adicional: de lo
> contrario un paquete de 500 kg costaría lo mismo que uno de 5.

---

## 16. Tipos de bulto

> Solo administración.

La lista que el cajero elige al recibir cada bulto: Paquete, Caja, Sobre, Bolsa,
Documento, Herramienta, Electrodoméstico, Frágil.

Es configurable porque cada operación recibe cosas distintas — agregar «Llanta»
no debería requerir tocar el sistema.

De cada tipo:

- **Nombre**
- **Orden** — el del desplegable. Poné arriba lo que más recibís
- **Frágil** — hace que la etiqueta salga con el aviso
- **Activo** — si se ofrece al crear guías

Un tipo **ya usado en guías emitidas no se puede borrar**: se desactiva, y así
deja de ofrecerse sin borrar lo que ya se emitió. Tampoco se puede desactivar el
último activo.

---

## 17. Impuestos

> Solo administración.

Los impuestos que se aplican a las guías. Por defecto viene el **IVA general al
13 %** con su código de Hacienda (08).

Uno puede marcarse como **predeterminado**: es el que se aplica solo.

---

## 18. Usuarios

> Solo administración.

Alta, edición y desactivación de usuarios. De cada uno: nombre, usuario, correo,
contraseña, **rol** y **sede**.

Cajeros y repartidores se asignan a una sede — es lo que delimita qué pueden ver.

En vez de borrar un usuario, **desactivalo**: así se conserva la trazabilidad de
todo lo que hizo.

---

## 19. Bitácora

> Solo administración.

Registro de lo que pasó en el sistema: quién, qué, cuándo. Sirve para auditar un
descuadre, una anulación o un cambio de estado que nadie recuerda haber hecho.

---

## 20. Datos de la empresa

> Solo administración.

**Datos fiscales.** Nombre, nombre comercial, cédula, **código de actividad
económica**, ubicación, teléfono y correo. Todo esto va en cada comprobante
electrónico.

**Credenciales de Hacienda.** Usuario y contraseña de ATV, certificado digital
(.p12) y su PIN. Hay un botón para **probar la conexión** antes de emitir.

**Ambiente.** *Sandbox* para pruebas, *producción* para emitir de verdad.

**CABYS.** El código de producto por defecto. Hay un buscador integrado.

---

## 21. Rastreo público

**Sin necesidad de entrar al sistema**, en `/rastreo`.

El cliente digita el código de la guía —o escanea el QR del recibo— y ve el
estado y su recorrido.

La página **no muestra montos ni datos personales**: aunque alguien se pusiera a
probar códigos, no obtiene nada aprovechable.

---

## 22. Correos que envía el sistema

| Cuándo | A quién | Qué lleva |
|---|---|---|
| El comprobante es **aceptado por Hacienda** | Al correo del receptor | El **PDF**, el **XML firmado** y el **XML de respuesta de Hacienda** |
| La guía **llega al destino** | Al destinatario | Aviso de que puede retirarla |
| La guía queda **próxima a desecho** | Al destinatario | Aviso de que la retire |
| La guía se **entrega** | Al destinatario | Confirmación |
| Se pide **restablecer contraseña** | Al usuario | Enlace para cambiarla |

Los avisos al destinatario solo salen si la guía trae **correo del
destinatario**.

---

## 23. Tareas automáticas

Corren solas si el cron del servidor está configurado:

| Tarea | Cuándo | Qué hace |
|---|---|---|
| Consulta a Hacienda | Cada minuto | Revisa los comprobantes enviados y actualiza su estado |
| Control de desecho | 2:30 a. m. | Marca como *próximas a desecho* las que llevan mucho en destino |
| Corte de crédito | 3:00 a. m. | Genera los estados de cuenta de los clientes cuyo día de corte llegó |

**Plazos de desecho** (configurables): se avisa a los **30 días** de llegar al
destino, y hay **15 días** de gracia antes de desechar. El desecho automático
viene **desactivado** por defecto: se marca, pero alguien tiene que confirmarlo.

---

## 24. Preguntas frecuentes

**No puedo abrir la caja: el selector está vacío.**
La sede no tiene ninguna caja. Un administrador la crea en **Cajas**.

**El código de la guía salió como `ENC-000045` en vez de `SJ-LIM-00005`.**
La sede no tiene prefijo. Se configura en **Sucursales** — afecta solo a las
guías nuevas.

**No me deja cambiar el estado de una guía.**
Solo se permiten los pasos válidos del ciclo. La pantalla muestra a cuáles puede
ir desde donde está.

**No me deja entregar desde el listado.**
Entregar exige registrar quién retiró. Entrá al detalle de la guía.

**Hacienda rechazó un comprobante.**
En **Pendientes de envío a Hacienda** está el motivo con el código de error. Se
corrige y se reintenta desde ahí.

**El cajero no ve las guías de otra sede.**
Es correcto: el cajero opera solo en la suya. Para ver todas hace falta rol de
administrador.

**Quiero borrar una sede / caja / tipo de bulto y no me deja.**
Si ya tiene movimiento registrado, no se borra — se **desactiva**. Es para no
perder el historial de lo que ya se emitió.

**¿Se puede imprimir en 58 mm si la sede está en 80?**
Sí: agregá `?ancho=58` al final de la dirección del recibo o la etiqueta.

**Creé una tarifa y el precio sigue saliendo en blanco.**
Revisá que la tarifa cubra el peso del bulto y que sea de esa ruta (o con las
sedes en «cualquiera»). El cotizador del tarifario dice qué tarifa gana para una
ruta y un peso concretos.

**Un cliente con convenio envió y su saldo no se movió.**
La guía tiene que quedar **a crédito**: hay que elegirlo entre los clientes
registrados en el campo de remitente, no digitar su nombre a mano.

**El dinero de un «por cobrar» no aparece en mi arqueo.**
Es correcto: ese flete se cobra en destino. Entra al arqueo de la caja de la
sede que entrega, en el momento de la entrega, y solo si esa caja tiene un turno
abierto.
