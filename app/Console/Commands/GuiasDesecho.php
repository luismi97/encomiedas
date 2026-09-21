<?php

namespace App\Console\Commands;

use App\Console\Concerns\RecorreEmpresas;
use App\Models\Company;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Models\ShippingRoute;
use App\Services\GuideStatusService;
use Illuminate\Console\Command;

/**
 * Mueve las guías que nadie retiró: primero a "próximo a desecho" y, pasado el
 * plazo de gracia, a "desechado". Y de paso lista las que salieron y nadie
 * recibió, que son el otro extremo del mismo problema.
 *
 * El desecho definitivo NO se automatiza a ciegas: el requisito pide que quede
 * registrado quién lo autorizó. Por eso el comando avisa, y solo desecha por su
 * cuenta si la configuración lo habilita explícitamente.
 */
class GuiasDesecho extends Command
{
    use RecorreEmpresas;

    protected $signature = 'guias:desecho
        {--dry-run : Muestra qué haría sin tocar nada}
        {--empresa= : Procesa solo esta empresa (id o identificador)}';
    protected $description = 'Marca próximas a desecho las guías sin retirar, desecha las que agotaron el plazo y lista las estancadas en tránsito';

    public function handle(GuideStatusService $estados): int
    {
        return $this->porCadaEmpresa(fn (Company $empresa) => $this->procesar($estados));
    }

    private function procesar(GuideStatusService $estados): int
    {
        $diasAviso  = (int) config('encomiendas.disposal.warn_after_days', 30);
        $diasGracia = (int) config('encomiendas.disposal.dispose_after_days', 15);
        $automatico = (bool) config('encomiendas.disposal.auto_dispose', false);
        $simulacion = (bool) $this->option('dry-run');

        $this->avisarProximasADesecho($estados, $diasAviso, $simulacion);
        $this->desecharVencidas($estados, $diasGracia, $automatico, $simulacion);
        $this->listarEstancadas();

        return self::SUCCESS;
    }

    /** 1) Llegaron al destino y nadie las retiró en el plazo. */
    private function avisarProximasADesecho(GuideStatusService $estados, int $diasAviso, bool $simulacion): void
    {
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
    }

    /** 2) Ya avisadas y con el plazo de gracia vencido. */
    private function desecharVencidas(GuideStatusService $estados, int $diasGracia, bool $automatico, bool $simulacion): void
    {
        $porDesechar = Invoice::where('status', Invoice::STATUS_NEAR_DISPOSAL)
            ->whereNotNull('disposal_warned_at')
            ->where('disposal_warned_at', '<=', now()->subDays($diasGracia))
            ->get();

        if (! $automatico) {
            $this->warn("Listas para desechar: {$porDesechar->count()} — requieren autorización manual.");
            foreach ($porDesechar as $guia) {
                $this->line("  - {$guia->code} · avisada {$guia->disposal_warned_at->format('d/m/Y')}");
            }

            return;
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
    }

    /**
     * 3) Salieron y nadie las recibió.
     *
     * El ciclo de desecho persigue lo que llegó y nadie retiró; esto es el otro
     * extremo del viaje, que no tenía quién lo mirara. Una guía que se queda en
     * «Enviado» no la reclama ninguna otra tarea, y tampoco puede entrar en otro
     * cierre —solo se ofrecen las que están en la sede de origen—, así que se
     * perdía de vista sin que nadie lo notara.
     *
     * Solo informa, nunca mueve: el paquete está físicamente en algún lado y
     * decidir dónde desde una tarea de madrugada sería inventarlo.
     */
    private function listarEstancadas(): void
    {
        $dias   = (int) config('encomiendas.stuck_after_days', 7);
        $margen = (int) config('encomiendas.stuck_margin_days', 3);

        if ($dias <= 0) {
            return;
        }

        // La consulta acota con el umbral MÁS CORTO que pueda aplicarle a
        // alguien; el umbral exacto de cada guía se decide después, fila por
        // fila, porque depende de la ruta por la que salió. Al revés —traer
        // todo y filtrar— serían miles de filas cada madrugada.
        $limite = now()->subDays($this->umbralMasCorto($dias, $margen));

        $candidatas = Invoice::whereIn('status', [Invoice::STATUS_DISPATCHED, Invoice::STATUS_IN_TRANSIT])
            // Sin ningún movimiento posterior al límite: la última fila de la
            // bitácora es la que la puso en tránsito, y de eso hace rato.
            ->whereDoesntHave('statusHistories', fn ($q) => $q->where('happened_at', '>', $limite))
            ->with(['statusHistories', 'shippingRoute'])
            ->get();

        $estancadas = $candidatas->filter(function (Invoice $guia) use ($dias, $margen) {
            $ultimo = $guia->statusHistories->last()?->happened_at;

            return $ultimo && $ultimo->lte(now()->subDays($this->umbralDe($guia, $dias, $margen)));
        });

        if ($estancadas->isEmpty()) {
            $this->info("Estancadas en tránsito: 0 (ninguna lleva más de {$dias} días sin moverse)");

            return;
        }

        $this->warn("Estancadas en tránsito: {$estancadas->count()} — salieron y nadie las recibió.");

        foreach ($estancadas as $guia) {
            $desde  = $guia->statusHistories->last()?->happened_at?->format('d/m/Y') ?? '?';
            $umbral = $this->umbralDe($guia, $dias, $margen);
            $ruta   = $guia->shippingRoute?->transit_days
                ? " · ruta {$guia->shippingRoute->name}"
                : '';

            // En dos líneas cortas y no en una larga: la consola envuelve a los
            // 80 caracteres y partía el nombre de la ruta por la mitad.
            $this->line("  - {$guia->code} · {$guia->statusLabel()} desde {$desde}");
            $this->line("    más de {$umbral} días sin moverse{$ruta}");
        }
    }

    /** Lo que esta guía puede tardar antes de ser rara. */
    private function umbralDe(Invoice $guia, int $dias, int $margen): int
    {
        $transito = $guia->shippingRoute?->transit_days;

        return $transito ? $transito + $margen : $dias;
    }

    /** El umbral más corto que existe hoy, para no traer filas de más. */
    private function umbralMasCorto(int $dias, int $margen): int
    {
        $masRapida = ShippingRoute::active()->whereNotNull('transit_days')->min('transit_days');

        return $masRapida !== null ? min($dias, (int) $masRapida + $margen) : $dias;
    }
}
