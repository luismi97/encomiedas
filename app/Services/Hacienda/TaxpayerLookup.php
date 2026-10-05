<?php

namespace App\Services\Hacienda;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Datos de un contribuyente segun Hacienda (`GET /fe/ae?identificacion=`):
 * nombre, tipo de identificacion y actividades economicas.
 *
 * Con eso la guia autocompleta el receptor de la Factura Electronica en vez de
 * que el cajero transcriba un nombre, y le ofrece sus propias
 * actividades para `CodigoActividadReceptor` en vez de un codigo de memoria.
 *
 * Tres resultados, y el tercero nunca se trata como los otros dos (el mismo
 * criterio que ElectronicBillingService::reconcile()):
 *
 *  - FOUND: Hacienda lo tiene.
 *  - NOT_FOUND: Hacienda contesto que no lo conoce (404) o que la identificacion
 *    no es valida (400). Vale la pena que el cajero revise lo que digito.
 *  - UNAVAILABLE: no se pudo saber (red caida, timeout, 5xx, la pagina HTML del
 *    WAF). No dice nada de la cedula: el cajero completa a mano y sigue.
 *    Una consulta que falla NUNCA puede trabar una guia.
 */
class TaxpayerLookup
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @return array{
     *     status: string,
     *     name?: string,
     *     id_type?: string|null,
     *     activities?: array<int, array{code: string, description: string, principal: bool}>
     * }
     */
    public function find(?string $identification): array
    {
        $id = preg_replace('/\D/', '', (string) $identification);

        // Fisica 9 digitos, juridica y NITE 10, DIMEX 11 o 12. Fuera de eso
        // Hacienda contesta una pagina HTML de error: ni se pregunta.
        if (strlen($id) < 9 || strlen($id) > 12) {
            return ['status' => self::NOT_FOUND];
        }

        $cacheKey = 'hacienda.ae.' . $id;

        if (is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('hacienda.taxpayer.user_agent', 'EncomiendasCR/1.0 (facturacion electronica)'),
            ])
                ->timeout((int) config('hacienda.taxpayer.timeout', 6))
                ->acceptJson()
                ->get(config('hacienda.taxpayer.url', 'https://api.hacienda.go.cr/fe/ae'), ['identificacion' => $id]);
        } catch (\Throwable $e) {
            // Timeout, DNS, TLS: lo que sea, la guia sigue sin autocompletar.
            Log::warning('Hacienda AE: consulta fallida', ['id' => $id, 'error' => $e->getMessage()]);

            return ['status' => self::UNAVAILABLE];
        }

        if (in_array($response->status(), [400, 404], true)) {
            return ['status' => self::NOT_FOUND];
        }

        // El WAF puede contestar su pagina de bloqueo con HTTP 200: json() da
        // null y eso es "no se pudo saber", no "no existe".
        $body = $response->successful() ? $response->json() : null;

        if (!is_array($body) || blank($body['nombre'] ?? null)) {
            return ['status' => self::UNAVAILABLE];
        }

        $result = [
            'status' => self::FOUND,
            'name' => trim((string) $body['nombre']),
            'id_type' => in_array($body['tipoIdentificacion'] ?? null, ['01', '02', '03', '04'], true)
                ? $body['tipoIdentificacion']
                : null,
            'activities' => $this->activities($body['actividades'] ?? []),
        ];

        Cache::put($cacheKey, $result, (int) config('hacienda.taxpayer.cache_ttl', 60 * 60 * 24));

        return $result;
    }

    /**
     * Las actividades ACTIVAS, la principal primero.
     *
     * Solo las que tienen un codigo que el XML acepta (Catalogs::validActivityCode):
     * ofrecer una que despues rechace la recepcion seria peor que no ofrecerla.
     *
     * @return array<int, array{code: string, description: string, principal: bool}>
     */
    private function activities($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($activity) => is_array($activity) && ($activity['estado'] ?? 'A') === 'A')
            ->map(fn ($activity) => [
                'code' => Catalogs::normalizeActivityCode((string) ($activity['codigo'] ?? '')),
                'description' => trim((string) ($activity['descripcion'] ?? '')),
                'principal' => ($activity['tipo'] ?? '') === 'P',
            ])
            ->filter(fn ($activity) => Catalogs::validActivityCode($activity['code']))
            ->unique('code')
            ->sortByDesc('principal')
            ->values()
            ->all();
    }
}
