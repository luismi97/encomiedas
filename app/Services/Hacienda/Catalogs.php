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
