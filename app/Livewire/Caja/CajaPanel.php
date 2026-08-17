<?php

namespace App\Livewire\Caja;

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Denomination;
use App\Services\CajaService;
use Livewire\Component;
use RuntimeException;

class CajaPanel extends Component
{
    public ?int $registerId = null;

    // Apertura
    public float $openingFloat = 0;

    // Entrada / salida de efectivo
    public string $movementType = CashMovement::TYPE_OUT;
    public float $movementAmount = 0;
    public string $movementReason = '';

    /** Arqueo: denomination_id => cantidad contada. */
    public array $counts = [];
    public string $closingNote = '';
    public bool $showArqueo = false;

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    public function mount(CajaService $caja): void
    {
        $sesion = $caja->sesionAbiertaPara(auth()->user());

        $this->registerId = $sesion?->cash_register_id
            ?? CashRegister::active()
                ->when(auth()->user()->branch_id, fn ($q) => $q->where('branch_id', auth()->user()->branch_id))
                ->value('id');
    }

    private function notify(string $type, string $message): void
    {
        $this->feedbackType = $type;
        $this->feedback = $message;
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
    }

    private function caja(): ?CashRegister
    {
        return $this->registerId ? CashRegister::find($this->registerId) : null;
    }

    private function sesion(): ?CashSession
    {
        return $this->caja()?->sesionAbierta();
    }

    public function abrir(CajaService $servicio): void
    {
        $this->feedback = null;

        if (! $caja = $this->caja()) {
            $this->notify('error', 'Elegí una caja antes de abrir el turno.');

            return;
        }

        try {
            $sesion = $servicio->abrir($caja, auth()->user(), (float) $this->openingFloat);
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());

            return;
        }

        $this->openingFloat = 0;
        $this->notify('success', 'Turno abierto con un fondo de ₡' . number_format((float) $sesion->opening_float, 2) . '.');
    }

    public function registrarMovimiento(CajaService $servicio): void
    {
        $this->feedback = null;

        if (! $sesion = $this->sesion()) {
            $this->notify('error', 'No hay un turno abierto.');

            return;
        }

        try {
            $servicio->registrarMovimiento(
                $sesion,
                $this->movementType,
                (float) $this->movementAmount,
                $this->movementReason,
                auth()->user()
            );
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());

            return;
        }

        $this->reset(['movementAmount', 'movementReason']);
        $this->notify('success', 'Movimiento registrado.');
    }

    public function abrirArqueo(): void
    {
        $this->feedback = null;
        $this->counts = Denomination::active()->pluck('id')->mapWithKeys(fn ($id) => [$id => 0])->all();
        $this->showArqueo = true;
    }

    /** Lo que suma el conteo mientras el cajero digita. */
    public function getContadoProperty(): float
    {
        $denominaciones = Denomination::active()->get()->keyBy('id');

        $total = 0.0;
        foreach ($this->counts as $id => $cantidad) {
            $total += ((int) $cantidad) * ($denominaciones[$id]->value ?? 0);
        }

        return round($total, 2);
    }

    public function cerrar(CajaService $servicio): void
    {
        $this->feedback = null;

        if (! $sesion = $this->sesion()) {
            $this->notify('error', 'No hay un turno abierto.');

            return;
        }

        try {
            $cerrada = $servicio->cerrar($sesion, auth()->user(), $this->counts, $this->closingNote ?: null);
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());

            return;
        }

        $this->showArqueo = false;
        $this->counts = [];
        $this->closingNote = '';

        if ($cerrada->cuadra()) {
            $this->notify('success', 'Turno cerrado y cuadrado: ₡' . number_format((float) $cerrada->counted_cash, 2) . '.');

            return;
        }

        $this->notify('error', ($cerrada->hayFaltante() ? 'Faltante' : 'Sobrante') . ' de ₡'
            . number_format(abs((float) $cerrada->discrepancy), 2)
            . '. Esperado ₡' . number_format((float) $cerrada->expected_cash, 2)
            . ' contra ₡' . number_format((float) $cerrada->counted_cash, 2) . ' contados.');
    }

    public function render(CajaService $servicio)
    {
        $sesion = $this->sesion();

        return view('livewire.caja.caja-panel', [
            'caja'          => $this->caja(),
            'sesion'        => $sesion?->load(['movements.invoice', 'movements.creator', 'opener']),
            'esperado'      => $sesion ? $servicio->efectivoEsperado($sesion) : 0.0,
            'porMedio'      => $sesion ? $servicio->totalesPorMedio($sesion) : collect(),
            'denominaciones' => Denomination::active()->get(),
            'cajas'         => CashRegister::active()->with('branch')->get(),
            'historial'     => CashSession::with(['opener', 'closer', 'register.branch'])
                ->where('status', CashSession::STATUS_CLOSED)
                ->latest('closed_at')
                ->limit(10)
                ->get(),
        ])->layout('layouts.app', ['title' => 'Caja']);
    }
}
