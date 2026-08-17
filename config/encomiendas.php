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

];
