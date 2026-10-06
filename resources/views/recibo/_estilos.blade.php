{{-- Estilos de los documentos en rollo que se le entregan al cliente: el
     recibo y la factura. Mismo papel, misma impresora, mismas reglas. --}}
<style>
    /*
       Documento en rollo, para térmica o de matriz de puntos. Se imprime desde
       el navegador contra el driver del sistema: no hace falta WebUSB ni un
       puente local, y funciona igual en Windows, Mac y una tablet Android.

       El ancho y el tipo de impresora salen de la sede, no de una constante:
       cada mostrador compra la impresora que consigue.
    */
    @unless ($ajustar ?? false)
    @page {
        size: {{ $ancho }}mm auto;
        margin: 0;
    }
    @endunless

    * { box-sizing: border-box; }

    body {
        width: {{ $ancho }}mm;
        margin: 0;
        padding: 3mm;
        /* Monoespaciada: en térmica es lo que sale parejo y legible. */
        font-family: "Courier New", ui-monospace, monospace;
        font-size: {{ $ancho >= 76 ? '11px' : '10px' }};
        line-height: 1.35;
        color: #000;
        background: #fff;
    }

    .centro { text-align: center; }
    .grande { font-size: {{ $ancho >= 76 ? '17px' : '14px' }}; font-weight: bold; letter-spacing: .5px; }
    .medio  { font-size: {{ $ancho >= 76 ? '13px' : '12px' }}; font-weight: bold; }
    .regla  { border-top: 1px dashed #000; margin: 2mm 0; }
    /* Tabla y no flex: la etiqueta también se renderiza a PDF en algunos
       flujos, y DomPDF ignora flexbox — los montos saldrían pegados. */
    .fila   { width: 100%; border-collapse: collapse; }
    .fila td:last-child { text-align: right; }
    .etiqueta { text-transform: uppercase; font-size: 9px; letter-spacing: .4px; }
    .qr img { width: {{ $ancho >= 76 ? '36mm' : '30mm' }}; height: auto; }
    .firma { margin-top: 8mm; border-top: 1px solid #000; padding-top: 1mm; text-align: center; font-size: 9px; }
    .nota  { font-size: 9px; }
    .chico { font-size: 8px; }
    .fino  { font-weight: normal; }
    /* La clave de Hacienda son 50 dígitos seguidos: sin esto no parte y se
       sale del papel. */
    .clave { word-break: break-all; }

    @if ($matriz)
        /*
           Matriz de puntos (TM-U220 y similares). El cabezal de 9 agujas tiene
           tan poca resolución vertical que la letra de 8 a 11 px en peso
           normal sale deshecha, y los trazos finos de Courier New se cortan:
           en la práctica solo se leía lo que ya iba grande y en negrita. Acá
           todo va en negrita, sin serifas y nada por debajo de 12 px.

           Y nada gris: el driver lo convierte en una trama de puntos que
           ensucia el texto de al lado.
        */
        /*
           Margen de seguridad a los lados, más a la izquierda: el driver no
           siempre pone el origen exactamente al inicio del área imprimible, y
           con el contenido pegado al borde el primer milímetro de cada renglón
           salía cortado. El borde izquierdo es el que se corre.
        */
        body {
            width: {{ $anchoUtil }}mm;
            padding: 2mm 1mm 2mm {{ \App\Models\CashRegister::MARGEN_IZQUIERDO_MATRIZ_MM }}mm;
            font-family: Tahoma, Verdana, Arial, sans-serif;
            font-size: {{ $ancho >= 76 ? '14px' : '13px' }};
            font-weight: bold;
            line-height: 1.35;
        }
        .grande { font-size: {{ $ancho >= 76 ? '20px' : '17px' }}; letter-spacing: 0; }
        .medio  { font-size: {{ $ancho >= 76 ? '16px' : '15px' }}; }
        .etiqueta, .nota, .chico, .firma { font-size: 12px; letter-spacing: 0; }
        .fino   { font-weight: bold; }
        .regla  { border-top: 2px solid #000; }
        /* Sin suavizado al escalar: los bordes grises de cada módulo salen
           como puntos sueltos y el lector no encuentra las esquinas. */
        .qr img { width: {{ $ancho >= 76 ? '40mm' : '34mm' }}; image-rendering: pixelated; }
    @endif

    /* En pantalla se ve el papel; al imprimir, solo el contenido. */
    @media screen {
        body { margin: 20px auto; box-shadow: 0 0 0 1px #ddd; }
        .no-imprimir { display: block; }
    }
    @media print {
        .no-imprimir { display: none !important; }
        @if ($matriz)
            /* El área imprimible va centrada en el rollo: pegado a la
               izquierda, lo primero que se corta es el comienzo del renglón. */
            body { margin: 0 auto; }
        @endif
    }

    @include('recibo._ajustar')
</style>
