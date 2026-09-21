<?php

namespace App\Console\Commands;

use App\Console\Concerns\RecorreEmpresas;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Console\Command;

class HaciendaPoll extends Command
{
    use RecorreEmpresas;

    protected $signature = 'hacienda:poll
        {--limit= : Máximo de comprobantes por corrida}
        {--max-seconds= : Presupuesto de tiempo para la corrida}
        {--empresa= : Consulta solo esta empresa (id o identificador)}';

    protected $description = 'Consulta en Hacienda el estado de los comprobantes enviados (sent) y actualiza aceptado/rechazado.';

    /**
     * Una pasada por empresa: cada una consulta con SUS credenciales de ATV y
     * ve solo sus comprobantes, que es lo que el ámbito global garantiza una
     * vez fijado el contexto.
     */
    public function handle(ElectronicBillingService $service): int
    {
        return $this->porCadaEmpresa(fn (Company $empresa) => $this->consultar($service));
    }

    private function consultar(ElectronicBillingService $service): int
    {
        // Sin facturación electrónica configurada no hay nada que consultar, y
        // esto corre cada minuto: salir aquí evita levantar el framework para
        // nada en instalaciones que todavía no la usan.
        if (!CompanySetting::instance()->isReady()) {
            $this->info('Facturación electrónica no configurada: nada que consultar.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: config('hacienda.poll.batch_size', 25));
        $budget = (int) ($this->option('max-seconds') ?: config('hacienda.poll.max_seconds', 45));

        // Los menos consultados primero: así ninguno se queda sin turno cuando
        // hay más de los que caben en una corrida.
        $pending = ElectronicInvoice::with('invoice')
            ->where('status', ElectronicInvoice::STATUS_SENT)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $total = ElectronicInvoice::where('status', ElectronicInvoice::STATUS_SENT)->count();

        if ($pending->isEmpty()) {
            $this->info('No hay comprobantes en proceso.');

            return self::SUCCESS;
        }

        $this->info("Consultando {$pending->count()} de {$total} comprobante(s) en proceso...");

        $inicio = microtime(true);
        $consultados = 0;

        foreach ($pending as $electronicInvoice) {
            // Cada consulta es una llamada de red: sin techo, una acumulación de
            // comprobantes atascados deja un proceso PHP ocupado minuto tras
            // minuto. Lo que no entre en esta corrida entra en la siguiente.
            if (microtime(true) - $inicio > $budget) {
                $this->warn("Presupuesto de {$budget}s agotado: quedan " . ($pending->count() - $consultados) . ' para la próxima corrida.');
                break;
            }

            $result = $service->pollStatus($electronicInvoice);
            $consultados++;
            $this->line(" - {$result->clave}: {$result->statusLabel()}");
        }

        return self::SUCCESS;
    }
}
