<?php

namespace App\Services\Hacienda;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OAuth2 (password grant) contra el IDP (Keycloak) de Hacienda. Los tokens
 * duran ~5 minutos y se cachean por configuración/ambiente.
 */
class HaciendaAuth
{
    public function token(CompanySetting $settings): string
    {
        $cacheKey = 'hacienda_token_' . $settings->id . '_' . $settings->environment;

        return Cache::remember($cacheKey, config('hacienda.token_ttl'), function () use ($settings) {
            return $this->requestToken($settings);
        });
    }

    public function fresh(CompanySetting $settings): string
    {
        Cache::forget('hacienda_token_' . $settings->id . '_' . $settings->environment);

        return $this->token($settings);
    }

    private function requestToken(CompanySetting $settings): string
    {
        $env = $settings->environmentConfig();

        $response = Http::asForm()
            ->timeout(30)
            ->post($env['auth_url'], [
                'client_id'  => $env['client_id'],
                'grant_type' => 'password',
                'username'   => $settings->atv_username,
                'password'   => $settings->atv_password,
            ]);

        if (!$response->successful() || !$response->json('access_token')) {
            throw new RuntimeException(
                'No se pudo autenticar con Hacienda (' . $response->status() . '): '
                . ($response->json('error_description') ?? $response->body())
            );
        }

        return $response->json('access_token');
    }
}
