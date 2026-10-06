<?php

namespace App\Livewire\Credito;

use App\Models\CreditStatement;
use App\Models\Customer;
use App\Services\CreditoService;
use Livewire\Component;
use RuntimeException;

class CreditoPanel extends Component
{
    public $customerId = null;

    /** Corte */
    public $creditTermDays = 30;

    /** Abono */
    public $paymentAmount = 0;
    public string $paymentMethod = 'cash';
    public string $paymentReference = '';
    public $paymentStatementId = null;

    /** Estado de cuenta por fechas: arranca en el mes en curso. */
    public string $rangoDesde = '';
    public string $rangoHasta = '';

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

    public function mount(): void
    {
        $this->rangoDesde = now()->startOfMonth()->toDateString();
        $this->rangoHasta = now()->toDateString();
    }

    /**
     * El enlace al PDF del rango, o null con el motivo si las fechas no sirven.
     *
     * Se arma acá y no se valida en el PDF: un GET que falla validación
     * redirige de vuelta sin que esta pantalla muestre por qué.
     *
     * @return array{0:?string, 1:?string}
     */
    private function enlaceDelRango(?Customer $cliente): array
    {
        if (! $cliente) {
            return [null, null];
        }

        if (blank($this->rangoDesde) || blank($this->rangoHasta)) {
            return [null, 'Elegí las dos fechas.'];
        }

        try {
            $desde = \Illuminate\Support\Carbon::parse($this->rangoDesde);
            $hasta = \Illuminate\Support\Carbon::parse($this->rangoHasta);
        } catch (\Throwable) {
            return [null, 'Alguna de las fechas no es válida.'];
        }

        if ($hasta->lt($desde)) {
            return [null, 'La fecha final no puede ser anterior a la inicial.'];
        }

        return [route('credito.rango', [
            'customer' => $cliente->id,
            'from'     => $desde->toDateString(),
            'to'       => $hasta->toDateString(),
        ]), null];
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
        [$enlaceRango, $avisoRango] = $this->enlaceDelRango($cliente);

        return view('livewire.credito.credito-panel', [
            'enlaceRango' => $enlaceRango,
            'avisoRango'  => $avisoRango,
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
