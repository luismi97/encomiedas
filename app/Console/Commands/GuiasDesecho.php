<?php

namespace App\Console\Commands;

use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Services\GuideStatusService;
use Illuminate\Console\Command;

/**
 * Mueve las guías que nadie retiró: primero a "próximo a desecho" y, pasado el
 * plazo de gracia, a "desechado".
 *
 * El desecho definitivo NO se automatiza a ciegas: el requisito pide que quede
 * registrado quién lo autorizó. Por eso el comando avisa, y solo desecha por su
 * cuenta si la configuración lo habilita explícitamente.
 */
class GuiasDesecho extends Command
{
    protected $signature = 'guias:desecho {--dry-run : Muestra qué haría sin tocar nada}';
    protected $description = 'Marca próximas a desecho las guías sin retirar y desecha las que agotaron el plazo';

    public function handle(GuideStatusService $estados): int
    {
        $diasAviso  = (int) config('encomiendas.disposal.warn_after_days', 30);
        $diasGracia = (int) config('encomiendas.disposal.dispose_after_days', 15);
        $automatico = (bool) config('encomiendas.disposal.auto_dispose', false);
        $simulacion = (bool) $this->option('dry-run');

        // 1) Llegaron al destino y nadie las retiró en el plazo.
        $porAvisar = Invoice::where('status', Invoice::STATUS_AT_DESTINATION)
            ->whereNotNull('arrived_at')
            ->where('arrived_at', '<=', now()->subDays($diasAviso))
            ->get();

        $this->info("Próximas a desecho: {$porAvisar->count()} (más de {$diasAviso} días en destino)");

        foreach ($porAvisar as $guia) {
            $this->line("  - {$guia->code} · llegó {$guia->arrived_at->format('d/m/Y')}");

            if (! $simulacion) {
                $estados->cambiar(
                    $guia,
                    Invoice::STATUS_NEAR_DISPOSAL,
                    source: GuideStatusHistory::SOURCE_SYSTEM,
                    nota: "Sin retirar {$diasAviso} días después de llegar al destino."
                );
            }
        }

        // 2) Ya avisadas y con el plazo de gracia vencido.
        $porDesechar = Invoice::where('status', Invoice::STATUS_NEAR_DISPOSAL)
            ->whereNotNull('disposal_warned_at')
            ->where('disposal_warned_at', '<=', now()->subDays($diasGracia))
            ->get();

        if (! $automatico) {
            $this->warn("Listas para desechar: {$porDesechar->count()} — requieren autorización manual.");
            foreach ($porDesechar as $guia) {
                $this->line("  - {$guia->code} · avisada {$guia->disposal_warned_at->format('d/m/Y')}");
            }

            return self::SUCCESS;
        }

        $this->info("Desechando: {$porDesechar->count()} (plazo de gracia de {$diasGracia} días vencido)");

        foreach ($porDesechar as $guia) {
            $this->line("  - {$guia->code}");

            if (! $simulacion) {
                $estados->cambiar(
                    $guia,
                    Invoice::STATUS_DISPOSED,
                    source: GuideStatusHistory::SOURCE_SYSTEM,
                    nota: "Plazo de gracia de {$diasGracia} días vencido sin reclamo."
                );
            }
        }

        return self::SUCCESS;
    }
}
