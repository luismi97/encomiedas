<?php

namespace App\Services\Hacienda;

use SimpleXMLElement;

/**
 * Traduce la respuesta de rechazo de Hacienda a mensajes legibles.
 */
class RejectionParser
{
    private const ERROR_CODES = [
        '-1' => 'Error en los parámetros de entrada',
        '-2' => 'Error al procesar la solicitud',
        '-3' => 'El comprobante ya existe',
        '-4' => 'El comprobante no fue encontrado',
        '-5' => 'Actualmente el servicio no está disponible',
        '-100' => 'Error en autenticación',
        '-101' => 'Error de autorización',
        '-102' => 'Error en la firma digital',
        '-103' => 'El certificado digital no es válido',
        '-104' => 'El certificado digital ha expirado',
        '-201' => 'Error en la estructura XML',
        '-202' => 'Nodo requerido faltante',
        '-203' => 'Valor de nodo inválido',
        '-204' => 'Nodo duplicado',
        '-301' => 'Error en validación de datos',
        '-302' => 'Cédula del emisor inválida',
        '-303' => 'Cédula del receptor inválida',
        '-304' => 'Código de actividad inválido',
        '-305' => 'Monto total inconsistente',
        '-306' => 'IVA inválido',
        '-307' => 'Otros cargos inválido',
        '-308' => 'Descuentos inválidos',
        '-309' => 'Servicio de entrega no válido',
        '-310' => 'Moneda no válida',
        '-311' => 'Tipo de comprobante inválido',
        '-312' => 'Número consecutivo duplicado',
        '-313' => 'Fecha de emisión inválida',
        '-314' => 'Código de comprobante duplicado',
        '-315' => 'Línea detalle sin ítems',
        '-37'  => 'Datos del emisor incorrectos (provincia, cantón, distrito)',
        '-488' => 'Desglose de impuestos incompleto respecto a las líneas',
    ];

    public function parse(string $responseXml): array
    {
        try {
            $xml = simplexml_load_string($responseXml);
            if (!$xml) {
                return ['errors' => ['No se pudo procesar la respuesta']];
            }

            return $this->extractMessages($xml);
        } catch (\Exception $e) {
            return ['errors' => ['Error al procesar respuesta: ' . $e->getMessage()]];
        }
    }

    private function extractMessages(SimpleXMLElement $xml): array
    {
        $result = [
            'estado' => (string) ($xml->EstadoMensaje ?? 'desconocido'),
            'errors' => [],
            'warnings' => [],
        ];

        $detalle = (string) ($xml->DetalleMensaje ?? '');
        if ($detalle) {
            $this->parseDetailMessage($detalle, $result);
        }

        return $result;
    }

    private function parseDetailMessage(string $detalle, array &$result): void
    {
        if (preg_match_all('/-?\d+,\s*""([^""]+)""/', $detalle, $matches)) {
            foreach ($matches[1] as $index => $message) {
                if (preg_match('/^(-?\d+),/', $matches[0][$index], $codeMatch)) {
                    $code = $codeMatch[1];
                    $result['errors'][] = [
                        'code' => $code,
                        'description' => $this->getErrorDescription($code),
                        'message' => $message,
                    ];
                }
            }
        }

        if (empty($result['errors']) && $detalle !== '') {
            $result['errors'][] = ['code' => null, 'description' => null, 'message' => $detalle];
        }
    }

    private function getErrorDescription(string $code): ?string
    {
        return self::ERROR_CODES[$code] ?? null;
    }
}
