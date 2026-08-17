<?php

namespace App\Console\Commands;

use App\Models\ElectronicBillingSequence;
use App\Models\ElectronicInvoice;
use Illuminate\Console\Command;

/**
 * Resincroniza los contadores de consecutivos con lo que realmente se emitió.
 *
 * Hace falta cuando se restaura un respaldo viejo: la tabla de secuencias
 * vuelve atrás pero los comprobantes ya emitidos ante Hacienda no, y el
 * siguiente envío repetiría un consecutivo (rechazo -312).
 */
class RepairElectronicBillingSequences extends Command
{
    protected $signature = 'hacienda:repair-sequences {--force : Aplica las correcciones}';
    protected $description = 'Repara los contadores de consecutivos para evitar claves duplicadas';

    public function handle(): int
    {
        $this->info('Revisando los contadores de consecutivos...');

        $updates = [];

        foreach (ElectronicBillingSequence::all() as $sequence) {
            $maxIssued = ElectronicInvoice::where('branch_id', $sequence->branch_id)
                ->where('document_type', $sequence->document_type)
                ->pluck('consecutivo')
                ->map(fn ($consecutivo) => (int) substr((string) $consecutivo, -10))
                ->max() ?? 0;

            $this->line("Sucursal {$sequence->branch_id} / tipo {$sequence->document_type}: contador={$sequence->last_number}, emitido más alto={$maxIssued}");

            if ($maxIssued > $sequence->last_number) {
                $updates[$sequence->id] = $maxIssued;
                $this->warn('  Desincronizado: necesita reparación.');
            }
        }

        if (empty($updates)) {
            $this->info('Todas las secuencias están sincronizadas.');
            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            $this->warn('Ejecute con --force para aplicar: php artisan hacienda:repair-sequences --force');
            return self::FAILURE;
        }

        foreach ($updates as $sequenceId => $newValue) {
            ElectronicBillingSequence::whereKey($sequenceId)->update(['last_number' => $newValue]);
            $this->info("Secuencia {$sequenceId} actualizada a last_number = {$newValue}");
        }

        $this->info('Secuencias reparadas.');

        return self::SUCCESS;
    }
}
