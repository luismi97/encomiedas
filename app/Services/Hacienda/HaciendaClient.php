<?php

namespace App\Services\Hacienda;

use App\Models\CompanySetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Envoltorio delgado sobre la API de recepción (v1) de Hacienda.
 */
class HaciendaClient
{
    public function __construct(private HaciendaAuth $auth)
    {
    }

    public function send(CompanySetting $settings, array $payload): Response
    {
        $env = $settings->environmentConfig();

        return Http::withToken($this->auth->token($settings))
            ->acceptJson()
            ->timeout(45)
            ->post($env['reception_url'], $payload);
    }

    public function status(CompanySetting $settings, string $clave): Response
    {
        $env = $settings->environmentConfig();

        return Http::withToken($this->auth->token($settings))
            ->acceptJson()
            ->timeout(45)
            ->get(rtrim($env['reception_url'], '/') . '/' . $clave);
    }
}
