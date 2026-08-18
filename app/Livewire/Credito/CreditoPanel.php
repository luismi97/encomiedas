<?php

namespace App\Livewire\Credito;

use App\Models\CreditStatement;
use App\Models\Customer;
use App\Services\CreditoService;
use Livewire\Component;
use RuntimeException;

class CreditoPanel extends Component
{
    public ?int $customerId = null;

    /** Corte */
    public int $creditTermDays = 30;

    /** Abono */
    public float $paymentAmount = 0;
    public string $paymentMethod = 'cash';
    public string $paymentReference = '';
    public ?int $paymentStatementId = null;

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    private function notify(string $type, string $message): void
    {
        $this->feedbackType = $type;
        $this->feedback = $message;
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
    }

    private function cliente(): ?Customer
    {
        return $this->customerId ? Customer::find($this->customerId) : null;
    }

    public function cortar(CreditoService $credito): void
    {
        $this->feedback = null;

        if (! $cliente = $this->cliente()) {
            $this->notify('error', 'Elegí un cliente antes de cortar.');

            return;
        }

        try {
            $estado = $credito->cortar($cliente, auth()->user(), null, $this->creditTermDays);
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());

            return;
        }

        if (! $estado) {
            $this->notify('error', "«{$cliente->name}» no tiene guías pendientes de cortar en este momento.");

            return;
        }

        $this->notify('success', "Estado de cuenta {$estado->code} emitido por ₡"
            . number_format((float) $estado->total, 2) . ', con vencimiento el '
            . $estado->due_date->format('d/m/Y') . '.');
    }

    public function abonar(CreditoService $credito): void
    {
        $this->feedback = null;

        if (! $cliente = $this->cliente()) {
            $this->notify('error', 'Elegí un cliente antes de registrar el abono.');

            return;
        }

        $estado = $this->paymentStatementId ? CreditStatement::find($this->paymentStatementId) : null;

        try {
            $credito->abonar(
                $cliente,
                (float) $this->paymentAmount,
                auth()->user(),
                $estado,
                $this->paymentMethod,
                $this->paymentReference ?: null
            );
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());

            return;
        }

        $monto = number_format((float) $this->paymentAmount, 2);
        $this->reset(['paymentAmount', 'paymentReference', 'paymentStatementId']);

        $this->notify('success', "Abono de ₡{$monto} registrado. Saldo ahora: ₡"
            . number_format($credito->saldoTotal($cliente->fresh()), 2) . '.');
    }

    public function render(CreditoService $credito)
    {
        $cliente = $this->cliente();

        return view('livewire.credito.credito-panel', [
            'cliente'     => $cliente,
            'clientes'    => Customer::credit()->active()->orderBy('name')->get(['id', 'name', 'identification', 'credit_limit', 'credit_cutoff_day']),
            'saldoTotal'  => $cliente ? $credito->saldoTotal($cliente) : 0.0,
            'sinCortar'   => $cliente ? $credito->saldoSinCortar($cliente) : 0.0,
            'facturado'   => $cliente ? $credito->saldoFacturado($cliente) : 0.0,
            'disponible'  => $cliente ? $credito->disponible($cliente) : 0.0,
            'pendientes'  => $cliente ? $credito->guiasPendientesDeCorte($cliente) : collect(),
            'estados'     => $cliente
                ? CreditStatement::where('customer_id', $cliente->id)->latest('period_end')->limit(12)->get()
                : collect(),
            'antiguedad'  => $credito->antiguedadDeSaldos(),
        ])->layout('layouts.app', ['title' => 'Crédito y cuentas por cobrar']);
    }
}
