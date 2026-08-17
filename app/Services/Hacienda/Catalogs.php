<?php

namespace App\Services\Hacienda;

/**
 * Pequeños mapeos entre conceptos del sistema y los códigos de catálogo de
 * Hacienda v4.4.
 */
class Catalogs
{
    public static function paymentMethod(?string $method): string
    {
        return config('hacienda.payment_methods.' . $method, '01');
    }

    /** Catalogo de condicion de venta de Hacienda (v4.4). */
    public const SALE_CONDITIONS = [
        '01' => 'Contado',
        '02' => 'Crédito',
        '03' => 'Consignación',
        '04' => 'Apartado',
        '05' => 'Arrendamiento con opción de compra',
        '06' => 'Arrendamiento en función financiera',
        '07' => 'Cobro a favor de un tercero',
        '08' => 'Servicios prestados al Estado',
        '09' => 'Pago del servicio prestado al Estado',
        '99' => 'Otros',
    ];

    /**
     * Etiqueta legible de la condicion de venta configurada.
     *
     * Las pantallas y el PDF la sacan de aqui en vez de escribir "Contado" a
     * mano: si cambia la configuracion, el XML y lo que ve el cliente dejarian
     * de coincidir.
     */
    public static function saleConditionLabel(?string $code = null): string
    {
        $code = $code ?: (string) config('hacienda.sale_condition');

        return self::SALE_CONDITIONS[$code] ?? 'Otros';
    }

    public static function documentCode(string $type): string
    {
        return config('hacienda.document_codes.' . $type, '04');
    }

    public static function namespace(string $type): string
    {
        return config('hacienda.namespaces.' . $type);
    }

    /** El XSD v4.4 exige 6 posiciones: CIIU "NNNN.N" o el legado de 6 dígitos. */
    public static function validActivityCode(?string $code): bool
    {
        return (bool) preg_match('/^(?:\d{6}|\d{4}\.\d)$/', (string) $code);
    }

    public static function normalizeActivityCode(?string $code): string
    {
        $code = trim((string) $code);

        if (preg_match('/^\d{5}$/', $code)) {
            $code = substr($code, 0, 4) . '.' . substr($code, 4);
        }

        return $code;
    }

    /**
     * CodigoTarifaIVA por porcentaje: 08 = 13% general, 04 = 4%, 03 = 2%,
     * 02 = 1%, 09 = 0.5%, 01 = 0% (tarifa cero).
     */
    public static function ivaRateCode(float $percent): string
    {
        return match (true) {
            $percent >= 13.0 => '08',
            $percent == 4.0  => '04',
            $percent == 2.0  => '03',
            $percent == 1.0  => '02',
            $percent == 0.5  => '09',
            default          => '01',
        };
    }
}
