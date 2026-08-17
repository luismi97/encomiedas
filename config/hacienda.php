<?php

/*
|--------------------------------------------------------------------------
| Facturación Electrónica - Ministerio de Hacienda Costa Rica (v4.4)
|--------------------------------------------------------------------------
|
| Endpoints, OAuth client ids, firma XAdES y catálogos usados por las clases
| App\Services\Hacienda\*. Adaptado de retailpos para el sistema de
| encomiendas: cada comprobante representa el servicio de transporte de uno
| o varios paquetes de una factura (Invoice), no una venta de productos.
|
| IMPORTANTE: reconfirmar URLs y el hash de la política de firma contra la
| documentación oficial de ATV antes de producción. Validar contra sandbox.
|
*/

return [

    'country_code' => '506',

    // Solo debe ser true en el .env del servidor de produccion real.
    'live' => (bool) env('HACIENDA_LIVE', false),

    'environments' => [

        'sandbox' => [
            'auth_url'      => 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token',
            'client_id'     => 'api-stag',
            'reception_url' => 'https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1/recepcion',
        ],

        'prod' => [
            'auth_url'      => 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token',
            'client_id'     => 'api-prod',
            'reception_url' => 'https://api.comprobanteselectronicos.go.cr/recepcion/v1/recepcion',
        ],

    ],

    'signature_policy' => [
        'url'          => 'https://www.hacienda.go.cr/contenido/14350-comprobantes-electronicos',
        'digest_algo'  => 'sha256',
        'digest_value' => '6QY6fkw2Dy4hscWQBdKC6XDCfL/iFN8/aUm5wl2z/Yk=',
    ],

    'namespaces' => [
        'FE' => 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica',
        'TE' => 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/tiqueteElectronico',
        'NC' => 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaCreditoElectronica',
        'ND' => 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaDebitoElectronica',
    ],

    'document_codes' => [
        'FE' => '01', // Factura Electrónica (con receptor identificado)
        'ND' => '02', // Nota de Débito
        'NC' => '03', // Nota de Crédito
        'TE' => '04', // Tiquete Electrónico (sin receptor)
    ],

    'tax' => [
        'iva_code'      => '01',
        'iva_rate_code' => '08',
        'iva_rate'      => 13.00,
    ],

    'sale_condition' => '01', // Contado

    /*
     | Unidad de medida por tipo de linea. Hacienda clasifica bien vs servicio
     | por el CABYS, asi que la unidad tiene que seguir esa misma clasificacion:
     | 'Sp' en una linea cuyo CABYS es un bien es una contradiccion.
     */
    'measurement_unit' => 'Sp',          // servicios prestados
    'measurement_unit_goods' => 'Unid',  // mercancias

    'payment_methods' => [
        'cash'     => '01',
        'card'     => '02',
        'sinpe'    => '06',
        'transfer' => '04',
        'other'    => '99',
    ],

    // Codigo CABYS por defecto para "servicios de transporte de encomiendas /
    // mensajeria" — configurable desde Ajustes de la empresa si Hacienda
    // exige uno mas especifico segun la actividad economica registrada.
    'default_cabys_code' => '8511200000000',

    /*
     | Busqueda en el catalogo CABYS contra el API publico de Hacienda.
     |
     | A diferencia de un POS, aqui casi todas las lineas son el mismo servicio
     | de transporte, asi que no se importa el catalogo completo: basta con
     | poder buscar y validar un codigo desde la configuracion.
     */
    'cabys' => [
        'url'        => 'https://api.hacienda.go.cr/fe/cabys',
        'timeout'    => 10,           // segundos por request
        'top'        => 15,           // max resultados por busqueda
        'cache_ttl'  => 60 * 60 * 24, // 24h por termino buscado
        'user_agent' => 'EncomiendasCR/1.0 (facturacion electronica)',
    ],

    'disk' => 'hacienda',

    'token_ttl' => 240,
];
