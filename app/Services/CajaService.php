<?php

namespace App\Services;

use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Denomination;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apertura, movimientos y arqueo de caja.
 *
 * La regla que sostiene todo: no se cobra de contado sin una caja abierta. Sin
 * eso el arqueo no significa nada, porque siempre habría cobros que el sistema
 * no vio.
 */
class CajaService
{
    public function abrir(CashRegister $caja, User $usuario, float $fondoInicial, ?string $nota = null): CashSession
    {
        return DB::transaction(function () use ($caja, $usuario, $fondoInicial) {
            // Se relee bajo candado: dos pestañas dando clic a la vez abrirían
            // dos turnos, y el segundo arrastraría los cobros del primero.
            $abierta = CashSession::where('cash_register_id', $caja->id)
                ->where('status', CashSession::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($abierta) {
                throw new RuntimeException(
                    "La caja «{$caja->name}» ya tiene un turno abierto desde el "
                    . $abierta->opened_at->format('d/m/Y H:i') . ", a nombre de " . $abierta->opener?->name . '.'
                );
            }

            if ($fondoInicial < 0) {
                throw new RuntimeException('El fondo inicial no puede ser negativo.');
            }

            return CashSession::create([
                'cash_register_id' => $caja->id,
                'branch_id'        => $caja->branch_id,
                'opened_by'        => $usuario->id,
                'opened_at'        => now(),
                'opening_float'    => $fondoInicial,
                'status'           => CashSession::STATUS_OPEN,
            ]);
        });
    }

    /**
     * Turno abierto de la sede del usuario, o null.
     *
     * Se busca por sede y no por caja concreta porque el cajero opera donde
     * está asignado; si la sede tiene varias cajas abiertas, manda la suya.
     */
    public function sesionAbiertaPara(User $usuario, ?int $branchId = null): ?CashSession
    {
        $sede = $branchId ?? $usuario->branch_id;

        if (! $sede) {
            return null;
        }

        return CashSession::where('branch_id', $sede)
            ->where('status', CashSession::STATUS_OPEN)
            ->where(fn ($q) => $q->where('opened_by', $usuario->id)->orWhereNotNull('id'))
            ->orderByRaw('CASE WHEN opened_by = ? THEN 0 ELSE 1 END', [$usuario->id])
            ->latest('id')
            ->first();
    }

    /**
     * Turno que abrió este usuario, si tiene uno.
     *
     * Distinto de sesionAbiertaPara(), que devuelve cualquier turno de la sede:
     * con varias cajas por sucursal, arrancar en la caja de un compañero
     * mostraría su arqueo y mandaría el primer cobro a la gaveta equivocada.
     */
    public function sesionPropiaAbierta(User $usuario): ?CashSession
    {
        return CashSession::where('opened_by', $usuario->id)
            ->where('status', CashSession::STATUS_OPEN)
            ->latest('id')
            ->first();
    }

    /**
     * Registra el cobro de una guía en el turno abierto.
     *
     * Devuelve null si no hay caja abierta: quien llama decide si eso es un
     * error que bloquea (cobro de contado) o algo aceptable (guía a crédito).
     */
    public function registrarCobro(Invoice $guia, User $usuario, ?CashSession $sesion = null): ?CashMovement
    {
        $sesion ??= $this->sesionAbiertaPara($usuario, $guia->pickup_branch_id);

        if (! $sesion || ! $sesion->estaAbierta()) {
            return null;
        }

        // Idempotente: reabrir y guardar una guía no debe duplicar su cobro.
        $existente = CashMovement::where('cash_session_id', $sesion->id)
            ->where('invoice_id', $guia->id)
            ->where('type', CashMovement::TYPE_SALE)
            ->first();

        if ($existente) {
            $existente->update([
                'amount'         => $guia->total,
                'payment_method' => $guia->payment_method ?: 'cash',
            ]);

            return $existente;
        }

        return CashMovement::create([
            'cash_session_id' => $sesion->id,
            'type'            => CashMovement::TYPE_SALE,
            'payment_method'  => $guia->payment_method ?: 'cash',
            'amount'          => $guia->total,
            'invoice_id'      => $guia->id,
            'reference'       => $guia->code,
            'created_by'      => $usuario->id,
            'happened_at'     => now(),
        ]);
    }

    /** Entrada o salida de efectivo con su motivo. */
    public function registrarMovimiento(
        CashSession $sesion,
        string $tipo,
        float $monto,
        string $motivo,
        User $usuario
    ): CashMovement {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('El turno ya está cerrado: no admite más movimientos.');
        }

        if (! in_array($tipo, [CashMovement::TYPE_IN, CashMovement::TYPE_OUT], true)) {
            throw new RuntimeException('Tipo de movimiento inválido.');
        }

        if ($monto <= 0) {
            throw new RuntimeException('El monto tiene que ser mayor que cero.');
        }

        if (trim($motivo) === '') {
            throw new RuntimeException('Toda entrada o salida de efectivo necesita un motivo.');
        }

        return CashMovement::create([
            'cash_session_id' => $sesion->id,
            'type'            => $tipo,
            'payment_method'  => 'cash',
            'amount'          => $monto,
            'reason'          => $motivo,
            'created_by'      => $usuario->id,
            'happened_at'     => now(),
        ]);
    }

    /**
     * Lo que debería haber en la gaveta: fondo inicial más todo el efectivo que
     * entró, menos el que salió. Las tarjetas y SINPE no cuentan: no están ahí.
     */
    public function efectivoEsperado(CashSession $sesion): float
    {
        $movimientos = $sesion->movements()->get()
            ->sum(fn (CashMovement $m) => $m->efectoEnEfectivo());

        return round((float) $sesion->opening_float + $movimientos, 2);
    }

    /** Totales por medio de pago, para el reporte de cierre. */
    public function totalesPorMedio(CashSession $sesion): Collection
    {
        return $sesion->movements()
            ->where('type', CashMovement::TYPE_SALE)
            ->get()
            ->groupBy('payment_method')
            ->map(fn ($grupo) => [
                'etiqueta' => Invoice::PAYMENT_METHODS[$grupo->first()->payment_method] ?? $grupo->first()->payment_method,
                'cantidad' => $grupo->count(),
                'total'    => round((float) $grupo->sum('amount'), 2),
            ]);
    }

    /**
     * Cierra el turno con el arqueo.
     *
     * @param  array<int,int>  $conteo  denomination_id => cantidad
     */
    public function cerrar(CashSession $sesion, User $usuario, array $conteo, ?string $nota = null): CashSession
    {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Este turno ya fue cerrado.');
        }

        $denominaciones = Denomination::active()->get()->keyBy('id');
        $contado = 0.0;

        return DB::transaction(function () use ($sesion, $usuario, $conteo, $nota, $denominaciones, &$contado) {
            foreach ($conteo as $denominacionId => $cantidad) {
                if (! $denominacion = $denominaciones->get($denominacionId)) {
                    continue;
                }

                $cantidad = max(0, (int) $cantidad);
                $subtotal = $cantidad * $denominacion->value;
                $contado += $subtotal;

                CashCount::updateOrCreate(
                    ['cash_session_id' => $sesion->id, 'denomination_id' => $denominacion->id],
                    ['quantity' => $cantidad, 'subtotal' => $subtotal]
                );
            }

            $esperado = $this->efectivoEsperado($sesion);

            $sesion->update([
                'closed_by'     => $usuario->id,
                'closed_at'     => now(),
                'expected_cash' => $esperado,
                'counted_cash'  => round($contado, 2),
                // Negativo = faltante; positivo = sobrante.
                'discrepancy'   => round($contado - $esperado, 2),
                'status'        => CashSession::STATUS_CLOSED,
                'closing_note'  => $nota,
            ]);

            return $sesion->fresh();
        });
    }
}
