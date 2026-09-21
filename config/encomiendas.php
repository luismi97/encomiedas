<?php

/*
|--------------------------------------------------------------------------
| Operación de encomiendas
|--------------------------------------------------------------------------
|
| Parámetros del negocio que no son de Hacienda. Lo que cambia por empresa vive
| aquí; lo que cambia por sede vive en la tabla de sedes.
|
*/

return [

    /*
     | Divisor del peso volumétrico: (largo × ancho × alto en cm) / divisor.
     | Convención del sector: 5000 para aéreo, 6000 para terrestre. Un paquete
     | grande y liviano ocupa espacio en el camión igual que uno pesado, por eso
     | se cobra por el mayor entre el peso real y este.
     */
    'volumetric_divisor' => (int) env('ENCOMIENDAS_VOLUMETRIC_DIVISOR', 5000),

    /*
     | Relleno de ceros del consecutivo en el código guía: SJ-LIM-00005.
     */
    'guide_sequence_padding' => (int) env('ENCOMIENDAS_GUIDE_PADDING', 5),

    /*
     | Ciclo de desecho de guías sin retirar, en días desde que llegaron a la
     | sede destino.
     |
     | auto_dispose viene apagado a propósito: el requisito pide que el desecho
     | quede autorizado por alguien con permiso. Encendido, el cron desecha solo
     | y la bitácora registra "Automático" en vez de una persona.
     */
    'disposal' => [
        'warn_after_days'    => (int) env('ENCOMIENDAS_DISPOSAL_WARN_DAYS', 30),
        'dispose_after_days' => (int) env('ENCOMIENDAS_DISPOSAL_GRACE_DAYS', 15),
        'auto_dispose'       => (bool) env('ENCOMIENDAS_AUTO_DISPOSE', false),
    ],

    /*
     | Días que una guía puede pasar en «Enviado» o «En camino» antes de que el
     | control nocturno la liste como estancada.
     |
     | Es el otro extremo del viaje: el ciclo de desecho persigue lo que llegó y
     | nadie retiró, y esto persigue lo que salió y nadie recibió. Sin esto una
     | guía se queda en tránsito para siempre sin que nadie se entere, porque no
     | la mira ninguna otra tarea. En 0 se apaga el aviso.
     */
    'stuck_after_days' => (int) env('ENCOMIENDAS_STUCK_DAYS', 7),

    /*
     | Margen sobre los días de tránsito de la ruta antes de dar una guía por
     | estancada.
     |
     | Una ruta que tarda dos días no está estancada al tercero: un camión se
     | atrasa. Lo que sí es raro es que tarde el doble. Cuando la guía trae ruta
     | se mide contra lo que esa ruta tarda más este margen; sin ruta, contra
     | `stuck_after_days`, que es el número igual para todas.
     */
    'stuck_margin_days' => (int) env('ENCOMIENDAS_STUCK_MARGIN_DAYS', 3),

];
