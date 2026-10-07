"""
Genera Guia-rapida-encomiendas.pdf (en la raíz del repo).

La guía es para quien atiende el mostrador: cajeros y dependientes. Cada tarea
empieza en una página nueva; las capturas están en img/ y ya traen el recuadro
rojo dibujado.

Uso (necesita reportlab y la fuente Verdana de macOS):

    python3 -m venv /tmp/guia && /tmp/guia/bin/pip install reportlab
    /tmp/guia/bin/python docs/guia-rapida/generar.py

Marcado del texto:
    [[texto]]  lo que se ve escrito en la pantalla: azul y negrita
    **texto**  énfasis: negrita

Ojo: Verdana no trae la flecha (→); sale como un cuadrito. Escribirlo con palabras.
"""

import os
import re

from reportlab.lib.colors import HexColor, white
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, CondPageBreak, Flowable, Frame, Image, KeepTogether,
    PageBreak, PageTemplate, Paragraph, Spacer, Table, TableStyle,
)

AQUI = os.path.dirname(os.path.abspath(__file__))
IMG = os.path.join(AQUI, "img")
SALIDA = os.path.join(AQUI, "..", "..", "Guia-rapida-encomiendas.pdf")

FUENTES = "/System/Library/Fonts/Supplemental"
pdfmetrics.registerFont(TTFont("Verdana", os.path.join(FUENTES, "Verdana.ttf")))
pdfmetrics.registerFont(TTFont("Verdana-Bold", os.path.join(FUENTES, "Verdana Bold.ttf")))
pdfmetrics.registerFontFamily("Verdana", normal="Verdana", bold="Verdana-Bold")

AZUL = HexColor("#1e3a8a")
TEXTO = HexColor("#111827")
GRIS = HexColor("#4b5563")
BORDE = HexColor("#94a3b8")
ROJO = HexColor("#dc2626")

# (fondo, barra) de cada recuadro
RECUADROS = {
    "importante": (HexColor("#fef3c7"), HexColor("#d97706")),
    "consejo":    (HexColor("#dcfce7"), HexColor("#16a34a")),
    "info":       (HexColor("#dbeafe"), AZUL),
    "nuevo":      (HexColor("#dbeafe"), AZUL),
}

MARGEN = 68
ANCHO = letter[0] - 2 * MARGEN  # 476 pt


def marcar(texto, color_pantalla=AZUL):
    texto = re.sub(r"\[\[(.+?)\]\]", lambda m: f'<font name="Verdana-Bold" color="{color_pantalla.hexval()}">{m.group(1)}</font>', texto)
    texto = re.sub(r"\*\*(.+?)\*\*", r'<font name="Verdana-Bold">\1</font>', texto)
    return texto


def estilo(nombre, **kw):
    base = dict(fontName="Verdana", textColor=TEXTO, fontSize=16, leading=24)
    base.update(kw)
    return ParagraphStyle(nombre, **base)


E_PORTADA = estilo("portada", fontName="Verdana-Bold", fontSize=36, leading=44, textColor=AZUL)
E_ETIQUETA = estilo("etiqueta", fontName="Verdana-Bold", fontSize=13, leading=16, textColor=GRIS)
E_TITULO = estilo("titulo", fontName="Verdana-Bold", fontSize=28, leading=33, textColor=AZUL)
E_BAJADA = estilo("bajada", fontSize=16, leading=23, textColor=GRIS)
E_PASO = estilo("paso", fontSize=16, leading=24)
E_CAJA = estilo("caja", fontSize=15, leading=22)
E_CAJA_TIT = estilo("cajatit", fontName="Verdana-Bold", fontSize=15, leading=22)
E_TARJETA_TIT = estilo("tarjetatit", fontName="Verdana-Bold", fontSize=16, leading=21, textColor=AZUL)
E_TARJETA = estilo("tarjeta", fontSize=14.5, leading=21)
E_LISTA = estilo("lista", fontSize=15, leading=19.5)


def P(texto, e=E_PASO):
    return Paragraph(marcar(texto), e)


class Numero(Flowable):
    """El círculo azul con el número del paso."""

    def __init__(self, n):
        super().__init__()
        self.n = str(n)
        self.width = self.height = 35

    def draw(self):
        c = self.canv
        c.setFillColor(AZUL)
        c.circle(17.5, 17.5, 17.5, stroke=0, fill=1)
        c.setFillColor(white)
        c.setFont("Verdana-Bold", 20)
        c.drawCentredString(17.5, 10.5, self.n)


def captura(nombre, ancho):
    """La captura con su borde gris fino, al ancho que tenía en la guía."""
    ruta = os.path.join(IMG, nombre)
    img = Image(ruta)
    img.drawHeight = img.drawHeight * ancho / img.drawWidth
    img.drawWidth = ancho
    t = Table([[img]], colWidths=[ancho + 1.6])
    t.setStyle(TableStyle([
        ("BOX", (0, 0), (-1, -1), 0.8, BORDE),
        ("LEFTPADDING", (0, 0), (-1, -1), 0.8), ("RIGHTPADDING", (0, 0), (-1, -1), 0.8),
        ("TOPPADDING", (0, 0), (-1, -1), 0.8), ("BOTTOMPADDING", (0, 0), (-1, -1), 0.8),
    ]))
    t.hAlign = "LEFT"
    return t


def paso(n, texto, img=None, ancho=None, vinetas=None):
    celda = [P(texto)]
    for v in vinetas or []:
        celda.append(Paragraph(marcar("•&nbsp;" + v), estilo("vin", leftIndent=16, firstLineIndent=-12)))
    if img:
        celda += [Spacer(1, 8), captura(img, ancho)]
    t = Table([[Numero(n), celda]], colWidths=[51, ANCHO - 51])
    t.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0), ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0), ("BOTTOMPADDING", (0, 0), (-1, -1), 18),
        ("TOPPADDING", (0, 0), (0, 0), 3),
    ]))
    return t


def recuadro(tipo, titulo, *parrafos):
    fondo, barra = RECUADROS[tipo]
    celda = [Paragraph(marcar(titulo), E_CAJA_TIT)] + [P(p, E_CAJA) for p in parrafos]
    t = Table([["", celda]], colWidths=[6, ANCHO - 6])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (0, 0), barra),
        ("BACKGROUND", (1, 0), (1, 0), fondo),
        ("LEFTPADDING", (1, 0), (1, 0), 10), ("RIGHTPADDING", (1, 0), (1, 0), 12),
        ("TOPPADDING", (1, 0), (1, 0), 12), ("BOTTOMPADDING", (1, 0), (1, 0), 14),
        ("LEFTPADDING", (0, 0), (0, 0), 0), ("RIGHTPADDING", (0, 0), (0, 0), 0),
    ]))
    return KeepTogether([t, Spacer(1, 10)])


def tarjeta(titulo, texto, img=None, ancho=None):
    """Las opciones de la Tarea 4: un título azul y su explicación."""
    celda = [Paragraph(marcar(titulo), E_TARJETA_TIT), Spacer(1, 4)]
    celda += [P(t, E_TARJETA) for t in (texto if isinstance(texto, list) else [texto])]
    if img:
        celda += [Spacer(1, 8), captura(img, ancho)]
    t = Table([["", celda]], colWidths=[6, ANCHO - 6])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (0, 0), AZUL),
        ("BOX", (1, 0), (1, 0), 0.8, BORDE),
        ("LEFTPADDING", (1, 0), (1, 0), 10), ("RIGHTPADDING", (1, 0), (1, 0), 12),
        ("TOPPADDING", (1, 0), (1, 0), 12), ("BOTTOMPADDING", (1, 0), (1, 0), 14),
        ("LEFTPADDING", (0, 0), (0, 0), 0), ("RIGHTPADDING", (0, 0), (0, 0), 0),
    ]))
    return KeepTogether([t, Spacer(1, 12)])


def encabezado(etiqueta, titulo, bajada):
    return [Paragraph(etiqueta, E_ETIQUETA), Spacer(1, 2), Paragraph(titulo, E_TITULO),
            P(bajada, E_BAJADA), Spacer(1, 18)]


def subtitulo(texto):
    return [CondPageBreak(160), Spacer(1, 6), Paragraph(texto, E_TARJETA_TIT), Spacer(1, 12)]


# ---------------------------------------------------------------------------
# Contenido
# ---------------------------------------------------------------------------

TAREAS = [
    "Entrar al sistema",
    "Abrir la caja",
    "Recibir una encomienda",
    "Opciones especiales de la guía",
    "Imprimir la etiqueta y el recibo",
    "Buscar una encomienda",
    "Entregar una encomienda",
    "Cobrar lo pendiente",
    "Meter o sacar dinero de la caja",
    "Cerrar la caja",
    "Despachar y recibir el camión",
]


def portada():
    contenido = [Paragraph(f"{i}.&nbsp; {t}", E_LISTA) for i, t in enumerate(TAREAS + ["Si algo sale mal"], 1)]
    return [
        Spacer(1, 4),
        Paragraph("Guía rápida del sistema de encomiendas", E_PORTADA),
        Spacer(1, 6),
        P("Lo que se hace todos los días, paso a paso. Actualizada en octubre de 2026.", E_BAJADA),
        Spacer(1, 16),
        recuadro("info", "Cómo leer esta guía",
                 "Cada tarea empieza en una página nueva. Siga los pasos en orden, del 1 en adelante.",
                 "Las palabras en [[azul y negrita]] son las que usted va a ver escritas en la pantalla.",
                 'En las fotos, el <font name="Verdana-Bold" color="#dc2626">recuadro rojo</font> marca dónde escribir o qué botón presionar.'),
        Spacer(1, 4),
        Paragraph("Contenido", E_CAJA_TIT),
        *contenido,
        PageBreak(),
        *encabezado("NOVEDADES", "Qué cambió", "Lo nuevo de esta versión de la guía, por si ya conocía la anterior."),
        tarjeta("Una caja en cada sede",
                "Si usted atiende en dos sedes, puede tener una caja abierta en cada una (nunca dos en la misma sede). Vea la Tarea 2."),
        tarjeta("Clientes exonerados de IVA",
                "Al elegir un cliente exonerado, la guía se marca sola y no se le cobra el IVA. Vea la Tarea 4."),
        tarjeta("Guías dentro de la misma sede",
                "Un paquete que se deja y se retira en la misma sede ya puede tener su ruta. Vea la Tarea 4."),
        tarjeta("Cobrar desde la guía",
                "Una guía que espera pago se puede cobrar desde su propia pantalla, con [[Cobrar en mi caja]]. Vea la Tarea 8."),
        tarjeta("Los dependientes entregan y despachan",
                "Quien tiene usuario de **Dependiente** ahora también entrega paquetes y arma los cierres del camión. "
                "Lo que falta cobrar lo sigue cobrando un cajero. Vea las Tareas 7 y 11."),
        PageBreak(),
    ]


def tarea_1():
    return [
        *encabezado("TAREA 1", "Entrar al sistema", "Lo primero de cada día."),
        paso(1, "Abra el navegador de internet (Chrome) en la computadora."),
        paso(2, "Arriba, en la barra de direcciones, escriba:<br/>[[encomiendas.flkdevelopment.com]]<br/>y presione la tecla **Enter**."),
        paso(3, "En la casilla [[Correo o usuario]] escriba su usuario, y en [[Contraseña]] su clave. Luego presione [[Entrar]]."),
        paso(4, "Así se ve la pantalla para entrar:", "entrar-login.png", 255),
        paso(5, "Ya adentro, verá esta pantalla. **A la izquierda está el menú**: desde ahí se llega a todo lo demás.",
             "entrar-inicio.png", 420),
        recuadro("importante", "¡Importante!",
                 "Su usuario y su clave son solo **suyos**. No se los preste a nadie: todo lo que se haga con ellos queda a su nombre."),
        recuadro("consejo", "Un consejo",
                 "Guarde la página en favoritos (la estrellita de arriba a la derecha) para no tener que escribirla cada día. "
                 "Si olvidó la clave, pídale ayuda al administrador."),
        PageBreak(),
    ]


def tarea_2():
    return [
        *encabezado("TAREA 2", "Abrir la caja",
                    "Al empezar el turno, antes de cobrar. Sin la caja abierta el sistema no deja cobrar en efectivo."),
        paso(1, "En el menú de la izquierda, presione [[Caja]]."),
        paso(2, "Arriba a la derecha, elija la caja en la que va a trabajar. En [[Fondo inicial (₡)]] escriba cuánto "
                "dinero hay en la gaveta para empezar. Luego presione [[Abrir caja]]."),
        paso(3, "Así se ve:", "caja-abrir.png", 420),
        recuadro("importante", "¡Importante!",
                 "La caja que usted abre es **suya**. No cobre en la caja de un compañero: el dinero se sumaría a la cuenta de él."),
        recuadro("consejo", "Si atiende en dos sedes",
                 "Puede tener **una caja abierta en cada sede**, pero no dos en la misma. Cuando tiene más de una abierta, "
                 "arriba aparece [[Tus turnos abiertos]] con un botón por caja para pasar de una a otra. "
                 "Cada cobro entra solo a la caja de la sede de la guía."),
        recuadro("info", "¿Su usuario es de Dependiente?",
                 "Usted no abre caja ni cobra: salte a la Tarea 3. Lo que usted reciba de contado queda esperando que el cliente pague en caja."),
        PageBreak(),
    ]


def tarea_3():
    return [
        *encabezado("TAREA 3", "Recibir una encomienda",
                    "Cuando un cliente viene a enviar un paquete. El sistema le llama «guía». Si pide algo especial "
                    "(domicilio, seguro, factura, exoneración…), vea también la Tarea 4."),
        paso(1, "En el menú, presione [[Facturas / Encomiendas]] y luego el botón azul [[Nueva guía]].",
             "guia-nueva.png", 340),
        paso(2, "En [[Ruta predefinida]] elija hacia dónde va el paquete. Las sucursales se llenan solas.",
             "guia-ruta.png", 340),
        paso(3, "Escriba el nombre y teléfono de quien envía ([[Remitente]]) y de quien recibe ([[Receptor]]). "
                "Si el cliente ya está registrado, búsquelo en [[Cliente registrado]] y los datos se llenan solos.",
             "guia-personas.png", 420),
        paso(4, "En [[Paquetes]], elija el [[Tipo de bulto]], escriba el [[Peso (kg)]] y presione "
                "[[Calcular con el tarifario]]: el precio aparece solo. Si son varios paquetes, presione "
                "[[Agregar paquete]] por cada uno.",
             "guia-paquetes.png", 420),
        paso(5, "En [[¿Cómo se paga esta guía?]] elija:", "guia-cobro.png", 383, vinetas=[
            "[[Pagado]]: paga ahora. Elija también el [[Medio de pago]].",
            "[[Por cobrar]]: paga quien lo recoge, al llegar.",
            "[[A crédito]]: solo clientes con crédito.",
        ]),
        paso(6, "Abajo está el total. Revise todo con calma y presione [[Guardar factura]].", "guia-total.png", 283),
        recuadro("info", "Si su usuario no cobra (Dependiente)",
                 "Una guía [[Pagado]] queda **pendiente de pago**: mande al cliente a pagar a la caja. "
                 "El paquete no sale en el camión hasta que un cajero la cobre."),
        PageBreak(),
    ]


def tarea_4():
    return [
        *encabezado("TAREA 4", "Opciones especiales de la guía",
                    "Todo esto está en la misma pantalla de **Nueva guía**. Úselo solo si el cliente lo pide; si no, déjelo como está."),
        tarjeta("Si es un sobre o un documento, o si quiere asegurarlo", [
            "En [[Tipo de envío]] elija [[Sobre]] o [[Documento]] en vez de [[Paquete]]: el precio cambia según el tipo.",
            "Para asegurarlo, escriba en [[Valor declarado (₡)]] cuánto vale lo que envía. El sistema cobra un porcentaje "
            "de ese valor como seguro y lo suma solo. Si no quiere seguro, déjelo en cero.",
        ], "opciones-envio.png", 397),
        tarjeta("Si hay que llevarlo hasta la casa",
                "Marque la casilla [[Entrega a domicilio]]. Aparecen dos casillas más: la [[Dirección exacta]] "
                "(provincia, cantón, distrito y señas) y el [[Cargo por domicilio (₡)]], lo que se cobra por llevarlo.",
                "opciones-domicilio.png", 397),
        tarjeta("Si el cliente pide factura electrónica",
                "Marque [[Emitir Factura Electrónica]] y en [[Facturar a]] elija [[Destinatario]], [[Remitente]] u "
                "[[Otra persona]]. Esa persona necesita su identificación (cédula escrita **sin guiones ni espacios**) "
                "y su correo. Al escribir la cédula, el sistema busca el nombre en Hacienda. Si no la pide, no marque nada: sale un tiquete.",
                "opciones-factura.png", 397),
        tarjeta("Si el cliente es exonerado de IVA", [
            "Algunos clientes no pagan el 13 % porque Hacienda les dio una exoneración (zona franca, embajadas, algunas instituciones). "
            "Si el cliente está registrado como exonerado, al elegirlo en [[Cliente registrado]] la casilla "
            "[[Cliente exonerado de IVA]] se marca sola, la guía sale con factura electrónica a su nombre y "
            "abajo aparece el [[IVA exonerado]] restando.",
            "Si el cliente dice que es exonerado pero el sistema no lo marca, **no lo marque a mano**: cóbrele normal y "
            "pídale al administrador que registre la exoneración en [[Clientes]]. Necesita el número de autorización de Hacienda.",
        ]),
        tarjeta("Si el paquete es grande pero liviano",
                "Además del peso, escriba las medidas en [[L × A × H (cm)]]: largo, ancho y alto. Elija también el "
                "[[Tamaño]]. El sistema cobra por lo que pesa o por el espacio que ocupa, lo que sea mayor.",
                "opciones-medidas.png", 397),
        tarjeta("Si hay que hacer un descuento",
                "Escríbalo en [[Descuento (₡)]]. Si el sistema le pide una [[Clave de autorización]], la tiene que poner el administrador.",
                "opciones-descuento.png", 312),
        tarjeta("Si no aparece la ruta que necesita",
                "En [[Ruta predefinida]] elija [[Sin ruta: elegir las sucursales a mano]] y escoja la "
                "[[Sucursal de recogida]] y la [[Sucursal de entrega]]."),
        tarjeta("Si el paquete se deja y se retira en la misma sede",
                "Elija la ruta de esa sede (por ejemplo, la de **San José a San José**), o ponga la misma sucursal en recogida y en entrega. "
                "Ese paquete no viaja en ningún camión: se entrega ahí mismo."),
        tarjeta("Si hay algo que avisar",
                "Escríbalo en [[Notas]]: «frágil, no voltear», «llamar antes de entregar»…"),
        PageBreak(),
    ]


def tarea_5():
    return [
        *encabezado("TAREA 5", "Imprimir la etiqueta y el recibo",
                    "Después de guardar, el sistema le muestra la guía con sus botones."),
        paso(1, "Presione [[Etiqueta del paquete]]. Sale una etiqueta por cada paquete: péguela en la caja, donde se "
                "vea bien. Luego presione [[Recibo del cliente]] y entréguele el recibo al cliente.",
             "imprimir.png", 312),
        paso(2, "Si la impresora no imprime, revise que esté encendida y que tenga papel, y vuelva a presionar el botón. "
                "El recibo trae un código QR para que el cliente vea desde su celular por dónde va su paquete."),
        recuadro("importante", "¡Importante!",
                 "Si se equivocó en la guía, no haga otra encima: avísele al administrador. Solo él puede corregirla o anularla."),
        PageBreak(),
    ]


def tarea_6():
    return [
        *encabezado("TAREA 6", "Buscar una encomienda", "Cuando un cliente pregunta por su paquete."),
        paso(1, "En el menú, presione [[Facturas / Encomiendas]]."),
        paso(2, "Arriba, la lista empieza en [[Hoy]]. Si el paquete es de otro día, presione [[Esta semana]], "
                "[[Este mes]] o [[Todas]]. En la casilla [[Buscar]] escriba el número de la guía, o el nombre de "
                "quien envía o de quien recibe.",
             "buscar.png", 420),
        paso(3, "La columna [[Estado]] dice dónde está el paquete. Para ver todo sobre esa guía, presione su número, en azul."),
        PageBreak(),
    ]


def tarea_7():
    return [
        *encabezado("TAREA 7", "Entregar una encomienda",
                    "Cuando alguien viene a retirar un paquete que ya llegó. Lo hacen cajeros y dependientes."),
        paso(1, "Busque la guía (vea la Tarea 6) y presione su número en azul."),
        paso(2, "Presione el botón verde [[Entregado]]. El sistema le pregunta si está seguro: acepte.",
             "entregar-boton.png", 369),
        paso(3, "Escriba el [[Nombre de quien retira]] y su [[Identificación]] (pídale la cédula y revísela). Pídale "
                "que firme en el cuadro [[Firma]], con el dedo o con el ratón. Presione [[Confirmar entrega]] y "
                "entréguele el paquete.",
             "entregar-firma.png", 369),
        recuadro("importante", "¡Importante! Si la guía es Por cobrar",
                 "Reciba el dinero **antes** de entregar. Si usted tiene su caja abierta, el cobro entra a su caja al confirmar la entrega.",
                 "Si su usuario **no cobra** (Dependiente), el sistema no le deja entregarla: mande al cliente a pagar "
                 "a la caja y entréguela cuando el cajero la haya cobrado."),
        PageBreak(),
    ]


def tarea_8():
    return [
        *encabezado("TAREA 8", "Cobrar lo pendiente",
                    "Las guías que esperan pago: las de contado que recibió alguien que no cobra, y las «por cobrar» que ya llegaron."),
        paso(1, "En el menú, presione [[Caja]], y busque la guía en la lista [[Por cobrar en caja]].",
             "cobrar-pendiente.png", 420),
        paso(2, "Al lado, elija cómo paga el cliente (Efectivo, Tarjeta, SINPE Móvil…) y presione [[Cobrar]]."),
        paso(3, "Si el cliente quiere comprobante, presione [[Recibo]] o [[Factura]]."),
        recuadro("consejo", "También desde la guía",
                 "Si ya tiene la guía abierta y dice [[Sin cobrar en caja]], puede cobrarla ahí mismo: elija el medio "
                 "de pago y presione [[Cobrar en mi caja]]. El dinero entra a su caja de esa sede."),
        PageBreak(),
    ]


def tarea_9():
    return [
        *encabezado("TAREA 9", "Meter o sacar dinero de la caja",
                    "Cualquier dinero que entra o sale de la gaveta y que no es un cobro: un vuelto que trajeron, "
                    "una compra de la oficina, un pago a un proveedor."),
        paso(1, "En [[Caja]], busque el cuadro [[Entrada o salida de efectivo]]."),
        paso(2, "En [[Tipo]] elija [[Entrada]] si mete dinero, o [[Salida]] si lo saca. Escriba el [[Monto (₡)]] y, "
                "en [[Motivo]], para qué fue. Presione [[Registrar]].",
             "caja-movimiento.png", 420),
        recuadro("importante", "¡Importante!",
                 "Siempre escriba el motivo. Si al cerrar la caja falta o sobra dinero, ese motivo es lo que lo explica."),
        PageBreak(),
    ]


def tarea_10():
    return [
        *encabezado("TAREA 10", "Cerrar la caja", "Al terminar el turno, antes de irse."),
        paso(1, "En [[Caja]], presione [[Cerrar turno y hacer arqueo]].", "caja-cerrar-boton.png", 213),
        paso(2, "Cuente el dinero de la gaveta. Escriba cuántos billetes y monedas hay de cada valor (por ejemplo: "
                "3 billetes de ₡10 000). El sistema hace la suma solo y le muestra si cuadra en [[Diferencia]].",
             "caja-arqueo.png", 383),
        paso(3, "Si pasó algo fuera de lo normal, escríbalo en [[Nota de cierre]]. Presione [[Confirmar cierre]] y acepte la pregunta."),
        paso(4, "Si le piden el comprobante, presione [[Reporte del turno]] para imprimirlo."),
        recuadro("importante", "¡Importante!",
                 "Una caja cerrada no se puede volver a abrir. Revise bien el conteo antes de confirmar. "
                 "Si tiene cajas abiertas en dos sedes, cierre cada una."),
        recuadro("consejo", "Un consejo",
                 "Si un día se le olvidó cerrar, avísele al administrador: él puede cerrarla."),
        PageBreak(),
    ]


def tarea_11():
    return [
        *encabezado("TAREA 11", "Despachar y recibir el camión",
                    "El «cierre de envío» es la lista de guías que viajan juntas en un camión. Lo arman cajeros, "
                    "despachadores y dependientes."),
        *subtitulo("Cuando sale el camión"),
        paso(1, "En el menú, presione [[Cierres de envío]] y luego [[Nuevo cierre]]."),
        paso(2, "En [[Ruta predefinida]] elija la ruta del camión, o escoja [[Sede origen]] y [[Sede destino]]. "
                "Si sabe quién maneja, elíjalo en [[Chofer]] y escriba la [[Placa del vehículo]]. Presione [[Crear cierre]]."),
        paso(3, "Abajo aparecen las [[Guías disponibles de esta ruta]]. Presione [[Agregar]] en cada guía cuyo paquete "
                "se sube al camión. Si se equivocó, presione [[Quitar]]."),
        paso(4, "Cuando todo está cargado, presione [[Despachar cierre]] y acepte. Todas las guías pasan a [[Enviado]]."),
        paso(5, "En la lista de cierres, presione [[PDF]] e imprima el manifiesto: viaja con el chofer."),
        recuadro("importante", "¡Importante!",
                 "Una guía de contado que todavía no se cobró **no puede subir al camión**: primero se cobra en caja. "
                 "Después de despachar ya no se puede agregar ni quitar guías."),
        *subtitulo("Cuando llega un camión"),
        paso(1, "En [[Cierres de envío]], busque el cierre que llegó y presione [[Abrir]]."),
        paso(2, "Por cada paquete que baja, escanee la etiqueta o escriba el código en [[Recibir por código de guía]] "
                "y presione [[Recibir]]. También puede presionar [[Marcar recibida]] en la lista."),
        paso(3, "Al terminar, presione [[Cerrar recepción]] y acepte. Lo que no se marcó queda como [[Faltante]]."),
        recuadro("consejo", "Un consejo",
                 "Si un paquete que quedó como faltante aparece después, abra el cierre y presione [[Apareció]] en esa guía."),
        PageBreak(),
    ]


def si_algo_sale_mal():
    return [
        *encabezado("SI ALGO SALE MAL", "Tranquilo: casi todo tiene arreglo", ""),
        paso(1, "**Presione cada botón una sola vez y espere.** Después lea el mensaje de colores que aparece arriba: "
                "casi siempre dice qué falta. Si el sistema le hace una pregunta, léala con calma antes de aceptar: "
                "muchos cambios no se pueden deshacer."),
        paso(2, "**Pida ayuda a la pantalla.** El signo [[?]] junto a un botón explica qué hace, y el botón [[Guía]] "
                "de arriba le explica la pantalla completa.",
             "ayuda.png", 420),
        paso(3, "**Si se equivocó en una guía** (precio, nombre, destino), no haga otra encima: avísele al administrador. "
                "Solo él puede corregirla o anularla."),
        paso(4, "**Al irse**, cierre su caja (si tiene una) y presione [[Salir]] (arriba a la derecha)."),
        recuadro("info", "¿Necesita ayuda con el sistema?",
                 "Llame al [[7262-7896]]<br/>o escriba un correo a [[info@flkdevelopment.com]]"),
    ]


def pie(canvas, doc):
    canvas.saveState()
    canvas.setFont("Verdana", 12)
    canvas.setFillColor(GRIS)
    canvas.drawString(50, 36, "Guía rápida · Encomiendas")
    canvas.drawRightString(letter[0] - 50, 36, f"Página {doc.page}")
    canvas.restoreState()


def main():
    doc = BaseDocTemplate(
        SALIDA, pagesize=letter,
        title="Guía rápida del sistema de encomiendas", author="Encomiendas",
        leftMargin=MARGEN, rightMargin=MARGEN, topMargin=60, bottomMargin=70,
    )
    marco = Frame(MARGEN, 70, ANCHO, letter[1] - 130, id="cuerpo", leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    doc.addPageTemplates([PageTemplate(id="pagina", frames=[marco], onPage=pie)])

    historia = []
    for parte in (portada, tarea_1, tarea_2, tarea_3, tarea_4, tarea_5, tarea_6, tarea_7,
                  tarea_8, tarea_9, tarea_10, tarea_11, si_algo_sale_mal):
        historia += parte()

    doc.build(historia)
    print("Listo:", os.path.normpath(SALIDA))


if __name__ == "__main__":
    main()
