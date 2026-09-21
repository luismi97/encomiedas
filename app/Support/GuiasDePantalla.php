<?php

namespace App\Support;

/**
 * El recorrido guiado de cada pantalla.
 *
 * Vive en un solo archivo y no repartido por las vistas para que se pueda leer
 * entero: un texto de ayuda envejece en silencio —el botón cambia de nombre y
 * la explicación se queda—, y lo único que lo evita es poder revisarlo todo de
 * una sentada.
 *
 * Cada paso puede llevar un ancla: el valor de un `data-ayuda` puesto en la
 * vista. Si el ancla no está en pantalla, el paso se muestra igual, centrado y
 * sin resaltar nada, en vez de desaparecer: un paso que se salta solo porque el
 * botón estaba escondido deja al usuario con la explicación a medias.
 */
class GuiasDePantalla
{
    /**
     * @return array<string, array{titulo: string, pasos: array<int, array{ancla?: string, titulo: string, texto: string}>}>
     */
    public static function todas(): array
    {
        return [
            'dashboard' => [
                'titulo' => 'El panel',
                'pasos' => [
                    ['titulo' => 'Dónde estás', 'texto' => 'Esta es la pantalla de inicio: el resumen del día y los accesos a lo que más se usa. Todo lo demás está en el menú de la izquierda.'],
                    ['titulo' => 'El menú', 'texto' => 'Arriba va la operación del día —guías, cierres, caja— y más abajo la configuración, que se toca una vez y no se vuelve a mirar.'],
                    ['titulo' => 'Esta ayuda', 'texto' => 'El signo de interrogación junto a un botón explica qué hace. Podés volver a abrir este recorrido desde el botón «Guía» de la barra de arriba.'],
                ],
            ],

            'invoices.index' => [
                'titulo' => 'El listado de guías',
                'pasos' => [
                    ['titulo' => 'Se filtra por período', 'texto' => 'Arranca en «hoy» a propósito: es lo que se busca el 95% de las veces y es lo que hace que el listado abra rápido con la tabla grande.'],
                    ['titulo' => 'El buscador', 'texto' => 'Buscá por código de guía, por remitente o por destinatario. El código se puede escribir desde el principio (SJ-LIM) o solo la cola («la cero cero cinco»).'],
                    ['titulo' => 'La lista crece hacia abajo', 'texto' => 'No hay números de página: al llegar al final se cargan más solas. Si son demasiadas, acotá el filtro en vez de seguir bajando.'],
                    ['titulo' => 'Las acciones', 'texto' => 'A la derecha de cada fila están primero los tres impresos —etiqueta, recibo y PDF— y después los cambios de estado que esa guía admite desde donde está.'],
                ],
            ],

            'invoices.create' => [
                'titulo' => 'Crear una guía',
                'pasos' => [
                    ['ancla' => 'guia-ruta', 'titulo' => 'Empezá por la ruta', 'texto' => 'Si el par de sucursales ya está definido como ruta, elegirlo llena origen y destino de un solo toque. Si no, elegí las dos sucursales a mano.'],
                    ['titulo' => 'Remitente y destinatario', 'texto' => 'Si el cliente está registrado, buscalo y se llenan sus datos. Si no, escribilos: no hace falta registrarlo para poder enviar.'],
                    ['titulo' => 'Los bultos', 'texto' => 'Un renglón por bulto. El tarifario propone el precio según el peso y la ruta, pero lo podés pisar: hay acuerdos que ninguna tabla cubre.'],
                    ['titulo' => 'Cómo se cobra', 'texto' => 'Pagado entra a tu caja ahora. Por cobrar entra a la caja de destino cuando lo retiren. A crédito no entra a ninguna caja: suma al saldo del cliente.'],
                    ['titulo' => 'Al guardar', 'texto' => 'Se asigna el código de guía con los prefijos de la ruta y se imprime la etiqueta: una por bulto, que es la que se pega a la caja.'],
                ],
            ],

            'invoices.show' => [
                'titulo' => 'El detalle de una guía',
                'pasos' => [
                    ['titulo' => 'El recorrido', 'texto' => 'La bitácora de abajo dice quién movió la guía, cuándo y desde qué sede. No se edita ni se borra: es lo que sostiene el rastreo.'],
                    ['titulo' => 'Cambiar el estado', 'texto' => 'Solo se ofrecen los pasos válidos desde el estado actual. Todos piden confirmación y ninguno se deshace.'],
                    ['titulo' => 'Entregar', 'texto' => 'Pide el nombre de quien retira y la firma. Por eso no se puede entregar desde el listado: hace falta ese dato.'],
                    ['titulo' => 'Anular', 'texto' => 'Exige un motivo y solo se puede antes de que la guía salga. Una encomienda que ya viaja se devuelve, que es otra cosa.'],
                ],
            ],

            'dispatches.index' => [
                'titulo' => 'Cierres de envío',
                'pasos' => [
                    ['titulo' => 'Qué es un cierre', 'texto' => 'El manifiesto de lo que sale de una sede hacia otra en un viaje. Es el documento que viaja con el chofer y el que después dice si faltó algo.'],
                    ['titulo' => 'Armarlo', 'texto' => 'Elegí la ruta —o las dos sedes—, el chofer y la placa. Al agregar guías solo aparecen las de esa ruta: es lo que impide cargar en el camión equivocado.'],
                    ['titulo' => 'Despachar', 'texto' => 'Sale el camión y todas sus guías pasan a «Enviado» de una vez. Después ya no se les puede agregar ni quitar nada.'],
                    ['titulo' => 'Recibir en destino', 'texto' => 'Se marca una por una, con el lector, con la cámara o a mano. Al cerrar la recepción, lo que no se marcó queda como faltante y abre una incidencia de extravío.'],
                    ['titulo' => 'Si el faltante aparece', 'texto' => 'Se abre el cierre y se marca «Apareció»: la guía pasa a destino y se cierra el extravío. El cierre conserva la marca, porque eso pasó en ese viaje.'],
                ],
            ],

            'chofer.index' => [
                'titulo' => 'Mi ruta',
                'pasos' => [
                    ['titulo' => 'Solo lo tuyo', 'texto' => 'Acá ves únicamente el cierre que traés asignado y las guías que llevás encima. Nada de montos ni configuración.'],
                    ['titulo' => 'Escanear', 'texto' => 'La cámara se queda abierta mientras marcás. Suena distinto cuando la guía queda marcada y cuando no: si no pita bien, no quedó.'],
                    ['titulo' => 'Entregar', 'texto' => 'Nombre de quien retira y firma en la pantalla. Si la guía es por cobrar, primero hay que tener la caja abierta.'],
                    ['titulo' => 'Incidencias', 'texto' => 'Destinatario ausente, dirección errónea, paquete dañado. Queda registrada y la guía no se mueve de donde está, que es lo correcto.'],
                ],
            ],

            'caja.index' => [
                'titulo' => 'Caja',
                'pasos' => [
                    ['titulo' => 'Abrir el turno', 'texto' => 'Con el fondo inicial. Sin turno abierto no se puede cobrar de contado: el dinero no entraría a ningún arqueo.'],
                    ['titulo' => 'Tu turno es tuyo', 'texto' => 'No se puede vender apoyándose en el turno de un compañero. El cobro tiene que caer en el arqueo de quien responde por él.'],
                    ['titulo' => 'Entradas y salidas', 'texto' => 'Toda plata que entra o sale fuera de una venta exige motivo. Sin eso, un faltante después no se puede explicar.'],
                    ['titulo' => 'Cerrar y cuadrar', 'texto' => 'Se cuenta por denominación y el sistema compara contra lo esperado. La diferencia queda registrada: es el punto de todo el módulo.'],
                ],
            ],

            'quotes.index' => [
                'titulo' => 'Cotizaciones',
                'pasos' => [
                    ['titulo' => 'No son guías', 'texto' => 'Una proforma no consume consecutivo, no entra en los reportes de venta y no llega a Hacienda. No es nada hasta que el cliente acepta.'],
                    ['titulo' => 'Vencimiento', 'texto' => 'Ponele fecha: una cotización sin vencer es una promesa eterna, y el combustible y las tarifas cambian.'],
                    ['titulo' => 'Convertir', 'texto' => 'Cuando el cliente acepta, se convierte en guía real con sus datos ya cargados.'],
                ],
            ],

            'customers.index' => [
                'titulo' => 'Clientes',
                'pasos' => [
                    ['titulo' => 'Para qué sirven', 'texto' => 'Registrar un cliente permite acumular sus envíos, cobrarle a crédito y no volver a digitar sus datos en cada guía.'],
                    ['titulo' => 'El buscador', 'texto' => 'Busca por nombre, identificación, correo o teléfono. La cédula y el teléfono se buscan desde el principio; el nombre, por cualquier palabra.'],
                    ['ancla' => 'clientes-importar', 'titulo' => 'Si ya tenés la cartera', 'texto' => 'Importá el archivo que ya mantenés en Excel en vez de digitarlo. Primero te muestra qué va a pasar con cada fila.'],
                    ['titulo' => 'Contado y crédito', 'texto' => 'Un cliente de crédito necesita identificación, límite y día de corte: sin identificación no se le puede facturar al cierre del período.'],
                ],
            ],

            'customers.import' => [
                'titulo' => 'Importar clientes',
                'pasos' => [
                    ['ancla' => 'importar-plantilla', 'titulo' => 'La plantilla primero', 'texto' => 'Descargala y pegá tus clientes debajo del encabezado. Trae dos ejemplos, uno de contado y uno de crédito.'],
                    ['ancla' => 'importar-archivo', 'titulo' => 'Subí el archivo', 'texto' => 'Sirve con coma o con punto y coma, con acentos, y la cédula puede venir con guiones. No hace falta prepararlo.'],
                    ['ancla' => 'importar-revisar', 'titulo' => 'Revisá antes', 'texto' => 'Este paso no escribe nada: muestra fila por fila qué se crea, qué ya existe y qué no entra, con el número de línea del archivo.'],
                    ['ancla' => 'importar-confirmar', 'titulo' => 'Recién ahora se guarda', 'texto' => 'Lo que quedó marcado «No entra» se queda afuera. Corregilo en el archivo y volvé a subirlo: lo ya importado no se duplica.'],
                ],
            ],

            'credito.index' => [
                'titulo' => 'Crédito',
                'pasos' => [
                    ['titulo' => 'El saldo', 'texto' => 'Lo ya facturado en estados de cuenta más lo que todavía no se ha cortado. Las dos mitades son deuda.'],
                    ['titulo' => 'El corte', 'texto' => 'Agrupa las guías del período en un estado de cuenta. Corre solo el día que cada cliente tenga configurado.'],
                    ['titulo' => 'Los pagos', 'texto' => 'Se registran contra un estado de cuenta y admiten abonos parciales.'],
                ],
            ],

            'reportes.index' => [
                'titulo' => 'Reportes',
                'pasos' => [
                    ['titulo' => 'Guías por estado', 'texto' => 'Cuántas hay en cada estado, por cuánto monto y cuánto de eso sigue por cobrar.'],
                    ['titulo' => 'Próximas a desecho', 'texto' => 'Lo que lleva mucho tiempo en destino sin que nadie lo retire. Es la lista que hay que llamar antes de que venza el plazo.'],
                    ['titulo' => 'El período manda', 'texto' => 'Todo reporte se lee contra un rango de fechas. Cambiarlo cambia todos los números de la pantalla.'],
                ],
            ],

            'hacienda.pending' => [
                'titulo' => 'Pendientes de Hacienda',
                'pasos' => [
                    ['titulo' => 'Nunca se envía solo', 'texto' => 'Los comprobantes se encolan al entregar la guía, pero la transmisión la autoriza una persona. Es un requisito del negocio, no una limitación.'],
                    ['titulo' => 'Los rechazos', 'texto' => 'Cuando Hacienda rechaza, se guarda el motivo con su código. Desde acá se corrige y se reintenta.'],
                    ['titulo' => 'La consulta automática', 'texto' => 'Cada minuto se revisa el estado de lo ya enviado. No hace falta refrescar ni reenviar.'],
                ],
            ],

            'shipping-routes.index' => [
                'titulo' => 'Rutas',
                'pasos' => [
                    ['ancla' => 'rutas-nueva', 'titulo' => 'Qué es una ruta', 'texto' => 'El par origen–destino que se repite todos los días, con nombre propio. Definilo una vez y el mostrador deja de elegir dos sucursales por encomienda.'],
                    ['titulo' => 'Días de tránsito', 'texto' => 'Cuánto tarda normalmente. Con eso se le promete una fecha al cliente y el control nocturno sabe cuándo una guía de esa ruta va tarde de verdad.'],
                    ['titulo' => 'Desactivar, no borrar', 'texto' => 'Una ruta que ya se usó no se borra: se desactiva. Borrarla dejaría sin fecha prometida a guías que ya salieron con ella.'],
                ],
            ],

            'rates.index' => [
                'titulo' => 'Tarifario',
                'pasos' => [
                    ['titulo' => 'Por ruta y por peso', 'texto' => 'Cada tarifa cubre un rango de peso entre dos sedes. Dejar origen o destino en blanco significa «cualquiera», y sirve para una tarifa base.'],
                    ['titulo' => 'El peso volumétrico', 'texto' => 'Un paquete grande y liviano ocupa el mismo espacio en el camión que uno pesado. Se cobra por el mayor de los dos pesos.'],
                    ['titulo' => 'El precio se propone', 'texto' => 'El tarifario sugiere y el cajero puede pisarlo: hay acuerdos puntuales que ninguna tabla cubre.'],
                ],
            ],

            'branches.index' => [
                'titulo' => 'Sucursales',
                'pasos' => [
                    ['titulo' => 'El prefijo', 'texto' => 'Es lo que arma el código de guía: SJ-LIM-00005. Cambiarlo no reescribe las guías ya emitidas.'],
                    ['titulo' => 'El ancho del rollo', 'texto' => 'Cada sucursal declara si imprime en 58 u 80 mm. De ahí sale el formato del recibo y de la etiqueta.'],
                ],
            ],

            'cash-registers.index' => [
                'titulo' => 'Cajas',
                'pasos' => [
                    ['titulo' => 'Una caja es una gaveta', 'texto' => 'Dos cajeros en la misma sede no pueden compartirla: el faltante de uno aparecería en el conteo del otro.'],
                    ['titulo' => 'Varias por sede', 'texto' => 'Si hay dos ventanillas, cada una es su propia caja con su propio arqueo.'],
                ],
            ],

            'package-types.index' => [
                'titulo' => 'Tipos de bulto',
                'pasos' => [
                    ['titulo' => 'Es lo que elige el cajero', 'texto' => 'Va configurable porque cada operación recibe cosas distintas: agregar «llanta» no puede exigir un despliegue.'],
                    ['titulo' => 'El orden importa', 'texto' => 'El orden de acá es el del desplegable. Poné arriba lo que más se recibe.'],
                    ['titulo' => 'Frágil', 'texto' => 'Marcarlo hace que la etiqueta salga con el aviso para quien carga el camión.'],
                ],
            ],

            'taxes.index' => [
                'titulo' => 'Impuestos',
                'pasos' => [
                    ['titulo' => 'Lo que se le aplica a una guía', 'texto' => 'Cada impuesto lleva su porcentaje y su código de Hacienda, que es el que viaja en el comprobante electrónico.'],
                    ['titulo' => 'Desactivar en vez de borrar', 'texto' => 'Un impuesto usado en guías ya emitidas no se borra: se desactiva y deja de ofrecerse.'],
                ],
            ],

            'users.index' => [
                'titulo' => 'Usuarios',
                'pasos' => [
                    ['titulo' => 'El rol decide qué se ve', 'texto' => 'El cajero factura y cobra en su sede; el despachador solo arma y recibe camiones; el repartidor solo ve su ruta.'],
                    ['titulo' => 'La sede', 'texto' => 'Cajeros y despachadores trabajan en una sede concreta. El administrador las ve todas.'],
                    ['titulo' => 'Desactivar', 'texto' => 'Un usuario que ya operó no se borra: se desactiva. Lo que hizo tiene que seguir teniendo nombre en la bitácora.'],
                ],
            ],

            'activity-logs.index' => [
                'titulo' => 'Actividad',
                'pasos' => [
                    ['titulo' => 'Para qué está', 'texto' => 'Para cuando aparece un descuadre, una anulación o un cambio de estado que nadie recuerda haber hecho.'],
                    ['titulo' => 'Se filtra por persona y fecha', 'texto' => 'Es lo que convierte una lista larga en una respuesta.'],
                ],
            ],

            'settings.company' => [
                'titulo' => 'Configuración de la empresa',
                'pasos' => [
                    ['titulo' => 'Los datos del emisor', 'texto' => 'Nombre, cédula y ubicación son los que viajan en cada comprobante a Hacienda. Un dato mal puesto acá rechaza todos los envíos.'],
                    ['titulo' => 'El certificado', 'texto' => 'Sin el certificado y su clave no se puede firmar ningún comprobante. Tiene vencimiento: renovarlo a tiempo es lo que evita el apagón.'],
                    ['titulo' => 'Antes de facturar', 'texto' => 'La pantalla avisa qué falta para poder emitir. Mientras haya algo en rojo, los comprobantes se encolan pero no salen.'],
                ],
            ],
        ];
    }

    /** @return array{titulo: string, pasos: array<int, array<string,string>>}|null */
    public static function para(?string $ruta): ?array
    {
        return $ruta ? (self::todas()[$ruta] ?? null) : null;
    }
}
