<?php

namespace App\Services\Hacienda;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Busquedas en el catalogo CABYS contra el API publico de Hacienda.
 *
 * Version delgada: en un sistema de encomiendas practicamente toda linea es el
 * mismo servicio de transporte, asi que no se importa el catalogo completo
 * (>100 MB) como en un POS con miles de articulos. Solo hace falta encontrar y
 * validar el codigo una vez, al configurar la empresa.
 *
 * El WAF de Hacienda rechaza clientes automatizados de forma intermitente y
 * llega a devolver su pagina de bloqueo con HTTP 200, por eso se valida la
 * forma del JSON antes de confiar en la respuesta. Si no se pudo consultar
 * devuelve null (distinto de []: "no hay resultados"), y la UI deja digitar el
 * codigo a mano.
 */
class CabysService
{
    /**
     * Busca por descripcion o por codigo exacto de 13 digitos.
     *
     * @return array<int,array{codigo:string,descripcion:string,impuesto:float}>|null
     */
    public function search(string $term): ?array
    {
        $term = trim(preg_replace('/\s+/', ' ', $term));

        if ($term === '') {
            return [];
        }

        $cacheKey = 'hacienda.cabys.' . sha1(mb_strtolower($term));

        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $query = preg_match('/^\d{13}$/', $term)
            ? ['codigo' => $term]
            : ['q' => $term, 'top' => config('hacienda.cabys.top', 15)];

        try {
            $response = Http::withHeaders(['User-Agent' => config('hacienda.cabys.user_agent')])
                ->timeout(config('hacienda.cabys.timeout', 10))
                ->acceptJson()
                ->get(config('hacienda.cabys.url'), $query);
        } catch (ConnectionException) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $body = $response->json();

        // Pagina de bloqueo del WAF servida con HTTP 200: json() da null y no
        // se puede interpretar (ni cachear) como "sin resultados".
        if (!is_array($body)) {
            return null;
        }

        // ?q= responde {total, cantidad, cabys: [...]}; ?codigo= responde el array directo.
        $hits = isset($query['codigo']) ? $body : ($body['cabys'] ?? null);

        if (!is_array($hits)) {
            return null;
        }

        $results = collect($hits)
            ->filter(fn ($hit) => is_array($hit) && isset($hit['codigo'], $hit['descripcion']))
            ->map(fn ($hit) => [
                'codigo'      => (string) $hit['codigo'],
                'descripcion' => (string) $hit['descripcion'],
                'impuesto'    => (float) ($hit['impuesto'] ?? 13),
            ])
            ->values()
            ->all();

        Cache::put($cacheKey, $results, config('hacienda.cabys.cache_ttl', 86400));

        return $results;
    }

    /** Datos de un codigo exacto, o null si no existe o no se pudo consultar. */
    public function find(string $code): ?array
    {
        $results = $this->search($code);

        if ($results === null) {
            return null;
        }

        return collect($results)->firstWhere('codigo', $code);
    }
}
