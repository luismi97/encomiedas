<?php

namespace App\Console\Commands;

use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Console\Command;

class HaciendaPoll extends Command
{
    protected $signature = 'hacienda:poll';
    protected $description = 'Consulta en Hacienda el estado de los comprobantes enviados (sent) y actualiza aceptado/rechazado.';

    public function handle(ElectronicBillingService $service): int
    {
        $pending = ElectronicInvoice::where('status', ElectronicInvoice::STATUS_SENT)->get();

        $this->info("Consultando {$pending->count()} comprobante(s) en proceso...");

        foreach ($pending as $electronicInvoice) {
            $result = $service->pollStatus($electronicInvoice);
            $this->line(" - {$result->clave}: {$result->statusLabel()}");
        }

        return self::SUCCESS;
    }
}
