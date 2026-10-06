{{-- Prueba con ?ajustar=1: el documento toma el ancho del papel que tenga
     configurado el driver, en vez del que dice la caja.

     Sin @page el navegador usa el papel del driver y sus márgenes
     «predeterminados», que son el área que la impresora de verdad alcanza: en
     la de matriz eso reemplaza a ANCHO_IMPRIMIBLE_MATRIZ, que es una tabla
     fija. Con margin: 0, en cambio, se armaría sobre el rollo entero y el
     driver recortaría los bordes, que es justo lo que se quiere evitar.

     El estilo (matriz o térmica) sigue saliendo de la configuración: el ancho
     se puede adaptar solo, el tipo de impresora no. --}}
@if ($ajustar ?? false)
    @media print {
        body {
            width: auto !important;
            margin: 0 !important;
            padding: 1mm 0 !important;
        }
    }
@endif
