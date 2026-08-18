<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use App\Services\CreditoService;
use Illuminate\Console\Command;

/**
 * Corta el crédito de los clientes a los que hoy les toca.
 *
 * El día de corte es por cliente: unos cierran el 15, otros el 30. Correrlo a
 * diario y preguntar quién califica es más simple —y más fiable— que programar
 * una tarea por cliente.
 */
class CreditoCorte extends Command
{
    protected $signature = 'credito:corte
        {--dry-run : Muestra a quién cortaría sin emitir nada}
        {--cliente= : Corta solo a este cliente, sin importar el día}
        {--plazo=30 : Días de plazo del estado de cuenta}';

    protected $description = 'Emite los estados de cuenta de los clientes de crédito con corte hoy';

    public function handle(CreditoService $credito): int
    {
        $simulacion = (bool) $this->option('dry-run');
        $plazo = (int) $this->option('plazo');

        // El corte lo hace el sistema; se atribuye al primer administrador para
        // que el estado de cuenta no quede sin responsable.
        $usuario = User::where('role', User::ROLE_ADMIN)->orderBy('id')->first();

        if (! $usuario) {
            $this->error('No hay ningún administrador al que atribuir el corte.');

            return self::FAILURE;
        }

        $clientes = Customer::credit()->active()
            ->when($this->option('cliente'), fn ($q) => $q->whereKey($this->option('cliente')))
            ->get()
            ->filter(fn (Customer $c) => $this->option('cliente') || $credito->leTocaCorte($c));

        if ($clientes->isEmpty()) {
            $this->info('Hoy no le toca corte a ningún cliente.');

            return self::SUCCESS;
        }

        $this->info("Clientes con corte hoy: {$clientes->count()}");
        $emitidos = 0;

        foreach ($clientes as $cliente) {
            $pendiente = $credito->saldoSinCortar($cliente);

            if ($pendiente <= 0) {
                $this->line("  - {$cliente->name}: sin movimientos, no se emite estado.");
                continue;
            }

            if ($simulacion) {
                $this->line("  - {$cliente->name}: emitiría ₡" . number_format($pendiente, 2));
                continue;
            }

            $estado = $credito->cortar($cliente, $usuario, null, $plazo);
            $emitidos++;

            $this->line("  - {$cliente->name}: {$estado->code} por ₡"
                . number_format((float) $estado->total, 2)
                . ', vence ' . $estado->due_date->format('d/m/Y'));
        }

        if (! $simulacion) {
            $this->info("Estados de cuenta emitidos: {$emitidos}");
        }

        return self::SUCCESS;
    }
}
