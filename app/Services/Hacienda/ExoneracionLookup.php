<?php

namespace App\Services\Hacienda;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Una exoneración según EXONET (`GET /fe/ex?autorizacion=`).
 *
 * Es la misma fuente contra la que Hacienda valida el nodo Exoneracion de la
 * factura, así que copiar de aquí evita digitar a mano lo que después puede
 * rechazar: tipo de documento, institución, fecha, tarifa y a qué cédula le
 * pertenece.
 *
 * Los mismos tres resultados que TaxpayerLookup, y por la misma razón: una
 * consulta que falla nunca dice nada del número, solo que no se pudo saber.
 */
class ExoneracionLookup
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @return array{
     *     status: string,
     *     numero?: string,
     *     identificacion?: string,
     *     tipo?: string,
     *     institucion?: string,
     *     fecha_emision?: string,
     *     vence?: ?string,
     *     tarifa?: float,
     *     cabys?: array<int,string>
     * }
     */
    public function find(?string $numero): array
    {
        $numero = strtoupper(trim((string) $numero));

        if (strlen($numero) < 3 || strlen($numero) > 40) {
            return ['status' => self::NOT_FOUND];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('hacienda.taxpayer.user_agent', 'EncomiendasCR/1.0 (facturacion electronica)'),
            ])
                ->timeout((int) config('hacienda.taxpayer.timeout', 6))
                ->acceptJson()
                ->get(config('hacienda.exoneracion_url', 'https://api.hacienda.go.cr/fe/ex'), ['autorizacion' => $numero]);
        } catch (\Throwable $e) {
            Log::warning('Hacienda EX: consulta fallida', ['numero' => $numero, 'error' => $e->getMessage()]);

            return ['status' => self::UNAVAILABLE];
        }

        if (in_array($response->status(), [400, 404], true)) {
            return ['status' => self::NOT_FOUND];
        }

        $body = $response->successful() ? $response->json() : null;

        if (! is_array($body) || blank($body['numeroDocumento'] ?? null)) {
            return ['status' => self::UNAVAILABLE];
        }

        $tipo = (string) ($body['tipoDocumento']['codigo'] ?? '');
        $institucion = (string) ($body['CodigoInstitucion'] ?? '');

        return [
            'status'         => self::FOUND,
            'numero'         => (string) $body['numeroDocumento'],
            'identificacion' => preg_replace('/\D/', '', (string) ($body['identificacion'] ?? '')),
            'tipo'           => array_key_exists($tipo, Catalogs::EXEMPTION_DOCUMENT_TYPES) ? $tipo : null,
            'institucion'    => array_key_exists($institucion, Catalogs::EXEMPTION_INSTITUTIONS) ? $institucion : null,
            'fecha_emision'  => $this->fecha($body['fechaEmision'] ?? null),
            'vence'          => $this->fecha($body['fechaVencimiento'] ?? null),
            'tarifa'         => (float) ($body['porcentajeExoneracion'] ?? 0),
            // Sin la marca, la autorización no se limita a ciertos CABYS.
            'cabys'          => ($body['poseeCabys'] ?? false) ? array_values(array_map('strval', (array) ($body['cabys'] ?? []))) : [],
        ];
    }

    private function fecha($valor): ?string
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return Carbon::parse($valor)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
