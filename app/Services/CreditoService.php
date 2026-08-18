<?php

namespace App\Services;

use App\Models\CreditPayment;
use App\Models\CreditStatement;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crédito de clientes: acumulación, cortes, abonos y antigüedad de saldos.
 *
 * La deuda de un cliente son dos cosas que se suman: lo ya cortado y sin pagar,
 * más lo que lleva acumulado desde el último corte. Contar solo una de las dos
 * es cómo un cliente se pasa del límite sin que nadie lo note.
 */
class CreditoService
{
    /** Guías a crédito todavía sin cortar. */
    public function guiasPendientesDeCorte(Customer $cliente, ?CarbonInterface $hasta = null)
    {
        return Invoice::query()
            ->where('sender_customer_id', $cliente->id)
            ->where('sale_condition', Invoice::SALE_CREDIT)
            ->whereNull('credit_statement_id')
            ->whereNotIn('status', [Invoice::STATUS_CANCELLED])
            ->when($hasta, fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->orderBy('created_at')
            ->get();
    }

    /** Lo acumulado desde el último corte. */
    public function saldoSinCortar(Customer $cliente): float
    {
        return round((float) $this->guiasPendientesDeCorte($cliente)->sum('total'), 2);
    }

    /** Lo ya cortado y todavía sin pagar. */
    public function saldoFacturado(Customer $cliente): float
    {
        return round((float) CreditStatement::where('customer_id', $cliente->id)
            ->pending()
            ->sum('balance'), 2);
    }

    /** Deuda total: lo cortado sin pagar más lo acumulado. */
    public function saldoTotal(Customer $cliente): float
    {
        return round($this->saldoFacturado($cliente) + $this->saldoSinCortar($cliente), 2);
    }

    /** Cuánto le queda de línea. Negativo = se pasó. */
    public function disponible(Customer $cliente): float
    {
        return round((float) $cliente->credit_limit - $this->saldoTotal($cliente), 2);
    }

    /**
     * Motivo por el que no se le puede dar más crédito, o null si sí se puede.
     *
     * Devuelve el motivo en vez de un booleano para que la pantalla pueda decir
     * cuánto le queda, que es lo que el cajero necesita saber.
     */
    public function bloqueoPorLimite(Customer $cliente, float $montoNuevo = 0): ?string
    {
        if (! $cliente->isCredit()) {
            return 'Este cliente es de contado: no acumula crédito.';
        }

        if ((float) $cliente->credit_limit <= 0) {
            return null; // sin límite configurado, no se bloquea
        }

        $disponible = $this->disponible($cliente);

        if ($montoNuevo <= $disponible) {
            return null;
        }

        return "«{$cliente->name}» tiene ₡" . number_format($this->saldoTotal($cliente), 2)
            . ' de saldo contra un límite de ₡' . number_format((float) $cliente->credit_limit, 2)
            . '. Le quedan ₡' . number_format(max(0, $disponible), 2)
            . ' y esta guía suma ₡' . number_format($montoNuevo, 2) . '.';
    }

    /**
     * Corta el período: agrupa las guías acumuladas en un estado de cuenta.
     *
     * Devuelve null cuando no hay nada que cortar, en vez de crear un estado en
     * cero que después habría que depurar a mano.
     */
    public function cortar(Customer $cliente, User $usuario, ?CarbonInterface $hasta = null, int $plazoDias = 30): ?CreditStatement
    {
        if (! $cliente->isCredit()) {
            throw new RuntimeException("«{$cliente->name}» no es cliente de crédito.");
        }

        $hasta = $hasta ? Carbon::parse($hasta) : now();
        $guias = $this->guiasPendientesDeCorte($cliente, $hasta);

        if ($guias->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($cliente, $usuario, $guias, $hasta, $plazoDias) {
            $total = round((float) $guias->sum('total'), 2);

            $estado = CreditStatement::create([
                'code'         => $this->siguienteCodigo(),
                'customer_id'  => $cliente->id,
                'period_start' => $guias->first()->created_at->toDateString(),
                'period_end'   => $hasta->toDateString(),
                'due_date'     => $hasta->copy()->addDays($plazoDias)->toDateString(),
                'total'        => $total,
                'paid'         => 0,
                'balance'      => $total,
                'status'       => CreditStatement::STATUS_ISSUED,
                'issued_by'    => $usuario->id,
                'issued_at'    => now(),
            ]);

            Invoice::whereIn('id', $guias->pluck('id'))->update(['credit_statement_id' => $estado->id]);

            return $estado->fresh();
        });
    }

    private function siguienteCodigo(): string
    {
        $ultimo = CreditStatement::lockForUpdate()->max('id') ?? 0;

        return 'EC-' . str_pad((string) ($ultimo + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Registra un abono. Sin estado de cuenta indicado se aplica al más viejo
     * primero, que es como se cobra una cuenta.
     */
    public function abonar(
        Customer $cliente,
        float $monto,
        User $usuario,
        ?CreditStatement $estado = null,
        string $medio = 'cash',
        ?string $referencia = null
    ): CreditPayment {
        if ($monto <= 0) {
            throw new RuntimeException('El abono tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($cliente, $monto, $usuario, $estado, $medio, $referencia) {
            $abono = CreditPayment::create([
                'customer_id'         => $cliente->id,
                'credit_statement_id' => $estado?->id,
                'amount'              => $monto,
                'payment_method'      => $medio,
                'reference'           => $referencia,
                'paid_at'             => now(),
                'received_by'         => $usuario->id,
            ]);

            $estado
                ? $this->aplicarA($estado, $monto)
                : $this->aplicarDelMasViejo($cliente, $monto);

            return $abono;
        });
    }

    /** Aplica el monto a un estado concreto. */
    private function aplicarA(CreditStatement $estado, float $monto): void
    {
        $pagado = round((float) $estado->paid + $monto, 2);
        $saldo  = round((float) $estado->total - $pagado, 2);

        $estado->update([
            'paid'    => $pagado,
            // El saldo no baja de cero aunque el abono se pase: el excedente
            // queda como saldo a favor del cliente, no como número negativo.
            'balance' => max(0, $saldo),
            'status'  => $saldo <= 0.009 ? CreditStatement::STATUS_PAID : CreditStatement::STATUS_ISSUED,
        ]);
    }

    /** Reparte el abono entre los estados pendientes, del más viejo al más nuevo. */
    private function aplicarDelMasViejo(Customer $cliente, float $monto): void
    {
        $restante = $monto;

        $pendientes = CreditStatement::where('customer_id', $cliente->id)
            ->pending()
            ->orderBy('period_end')
            ->get();

        foreach ($pendientes as $estado) {
            if ($restante <= 0) {
                break;
            }

            $aplica = min($restante, (float) $estado->balance);
            $this->aplicarA($estado, $aplica);
            $restante = round($restante - $aplica, 2);
        }
    }

    /**
     * Cuentas por cobrar agrupadas por antigüedad, que es como se lee un
     * reporte de cobranza: no importa solo cuánto deben, sino desde cuándo.
     */
    public function antiguedadDeSaldos(): Collection
    {
        return CreditStatement::with('customer')
            ->pending()
            ->get()
            ->groupBy(fn (CreditStatement $e) => $e->tramoAntiguedad())
            ->map(fn (Collection $grupo) => [
                'cantidad' => $grupo->count(),
                'total'    => round((float) $grupo->sum('balance'), 2),
                'estados'  => $grupo,
            ]);
    }

    /** ¿Le toca corte hoy a este cliente? */
    public function leTocaCorte(Customer $cliente, ?CarbonInterface $fecha = null): bool
    {
        $fecha = $fecha ? Carbon::parse($fecha) : now();
        $dia = $cliente->credit_cutoff_day;

        if (! $cliente->isCredit() || ! $dia) {
            return false;
        }

        // Un corte el 31 en un mes de 30 cae el último día: si no, ese cliente
        // no se cortaría nunca en febrero.
        $diaEfectivo = min($dia, $fecha->daysInMonth);

        return $fecha->day === $diaEfectivo;
    }
}
