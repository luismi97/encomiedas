<?php

namespace App\Livewire\Quotes;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PackageType;
use App\Models\Quote;
use App\Models\Tax;
use App\Notifications\EnviarProforma;
use App\Services\Tarifario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cotizador: precios por escrito que NO se facturan.
 *
 * Usa el mismo tarifario que las guías —si no, el precio cotizado y el cobrado
 * se separarían— pero no toca consecutivos de ruta ni reportes de venta.
 */
class QuoteIndex extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;

    public ?int $origin_branch_id = null;
    public ?int $destination_branch_id = null;
    public ?int $customer_id = null;
    public string $customer_name = '';
    public string $customer_email = '';
    public string $customer_phone = '';
    public string $shipment_type = '';
    public string $notes = '';
    public string $valid_until = '';
    public bool $aplicarImpuesto = true;

    public array $items = [];
    public array $quote = [];
    public array $preciosSugeridos = [];

    /** Correo al que se envía, cuando se pide enviar una proforma. */
    public ?int $enviandoId = null;
    public string $enviarA = '';

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    public function mount(): void
    {
        $this->items = [$this->renglonEnBlanco()];
        // Un mes: pasado ese plazo el combustible y las tarifas ya cambiaron.
        $this->valid_until = now()->addMonth()->toDateString();
    }

    private function renglonEnBlanco(): array
    {
        return [
            'package_type_id' => PackageType::porDefecto()?->id,
            'description' => '', 'weight' => '',
            'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'price' => '',
        ];
    }

    protected function rules(): array
    {
        return [
            'origin_branch_id' => 'required|exists:branches,id',
            'destination_branch_id' => 'required|exists:branches,id|different:origin_branch_id',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'required|string|max:150',
            'customer_email' => 'nullable|email|max:150',
            'customer_phone' => 'nullable|string|max:30',
            'valid_until' => 'nullable|date|after_or_equal:today',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.package_type_id' => 'nullable|exists:package_types,id',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.weight' => 'nullable|numeric|min:0|max:999999.99',
            'items.*.length_cm' => 'nullable|numeric|min:0|max:999999.99',
            'items.*.width_cm' => 'nullable|numeric|min:0|max:999999.99',
            'items.*.height_cm' => 'nullable|numeric|min:0|max:999999.99',
            'items.*.price' => 'required|numeric|min:0',
        ];
    }

    protected function messages(): array
    {
        return [
            'destination_branch_id.different' => 'El destino tiene que ser una sede distinta del origen.',
            'customer_name.required' => 'Poné a nombre de quién va la cotización.',
            'valid_until.after_or_equal' => 'La cotización no puede vencer antes de hoy.',
            'items.*.price.required' => 'Cada bulto necesita un precio.',
        ];
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

    /** Recotiza sola, igual que el formulario de guías. */
    public function updated(string $campo): void
    {
        $afecta = in_array($campo, ['origin_branch_id', 'destination_branch_id', 'shipment_type'], true)
            || preg_match('/^items\.\d+\.(weight|length_cm|width_cm|height_cm)$/', $campo);

        if ($afecta) {
            $this->cotizar(app(Tarifario::class));
        }
    }

    public function updatedCustomerId($value): void
    {
        if (! $cliente = Customer::find($value)) {
            return;
        }

        $this->customer_name = $cliente->name;
        $this->customer_email = (string) $cliente->email;
        $this->customer_phone = (string) $cliente->phone;
    }

    public function cotizar(Tarifario $tarifario): void
    {
        $origen = $this->origin_branch_id ? Branch::find($this->origin_branch_id) : null;
        $destino = $this->destination_branch_id ? Branch::find($this->destination_branch_id) : null;

        $pesoTotal = 0.0;
        $precioTotal = 0.0;
        $sinTarifa = false;

        foreach ($this->items as $i => $item) {
            $dim = fn (string $k) => blank($item[$k] ?? null) ? null : (float) $item[$k];

            $c = $tarifario->cotizar(
                $origen, $destino, (float) ($item['weight'] ?? 0),
                $dim('length_cm'), $dim('width_cm'), $dim('height_cm'),
                $this->shipment_type ?: null
            );

            $pesoTotal += $c['peso_facturable'];

            if ($c['precio'] === null) {
                $sinTarifa = true;
                continue;
            }

            $precioTotal += $c['precio'];

            $actual = $this->items[$i]['price'] ?? '';
            $loPusoElSistema = blank($actual)
                || (isset($this->preciosSugeridos[$i])
                    && abs((float) $actual - (float) $this->preciosSugeridos[$i]) < 0.01);

            if ($loPusoElSistema) {
                $this->items[$i]['price'] = $c['precio'];
                $this->preciosSugeridos[$i] = $c['precio'];
            }
        }

        $this->quote = [
            'peso_total' => round($pesoTotal, 2),
            'precio_total' => round($precioTotal, 2),
            'sin_tarifa' => $sinTarifa,
        ];
    }

    public function getSubtotalProperty(): float
    {
        return round(collect($this->items)->sum(fn ($i) => (float) ($i['price'] ?? 0)), 2);
    }

    public function getTaxTotalProperty(): float
    {
        if (! $this->aplicarImpuesto) {
            return 0.0;
        }

        $porcentaje = (float) (Tax::where('is_active', true)->where('is_default', true)->value('percent') ?? 0);

        return round($this->subtotal * $porcentaje / 100, 2);
    }

    public function getTotalProperty(): float
    {
        return round($this->subtotal + $this->taxTotal, 2);
    }

    public function addItem(): void
    {
        $this->items[] = $this->renglonEnBlanco();
    }

    public function removeItem(int $index): void
    {
        if (count($this->items) <= 1) {
            return;
        }

        unset($this->items[$index], $this->preciosSugeridos[$index]);
        $this->items = array_values($this->items);
        $this->preciosSugeridos = array_values($this->preciosSugeridos);
    }

    public function create(): void
    {
        $this->feedback = null;
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->feedback = null;

        if (! $cot = Quote::with('items')->find($id)) {
            $this->notify('error', 'La cotización ya no existe.');

            return;
        }

        if ($cot->fueAceptada()) {
            $this->notify('error', "«{$cot->code}» ya se convirtió en la guía {$cot->invoice?->code}: "
                . 'no se puede modificar.');

            return;
        }

        $this->resetErrorBag();
        $this->editingId = $cot->id;
        $this->origin_branch_id = $cot->origin_branch_id;
        $this->destination_branch_id = $cot->destination_branch_id;
        $this->customer_id = $cot->customer_id;
        $this->customer_name = (string) $cot->customer_name;
        $this->customer_email = (string) $cot->customer_email;
        $this->customer_phone = (string) $cot->customer_phone;
        $this->shipment_type = (string) $cot->shipment_type;
        $this->notes = (string) $cot->notes;
        $this->valid_until = $cot->valid_until?->toDateString() ?? '';
        $this->aplicarImpuesto = (float) $cot->tax_total > 0;
        $this->items = $cot->items->map(fn ($i) => [
            'package_type_id' => $i->package_type_id,
            'description' => (string) $i->description,
            'weight' => $i->weight,
            'length_cm' => $i->length_cm,
            'width_cm' => $i->width_cm,
            'height_cm' => $i->height_cm,
            'price' => (float) $i->price,
        ])->toArray();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->feedback = null;
        $data = $this->validate();

        try {
            DB::transaction(function () use ($data) {
                $cot = $this->editingId ? Quote::findOrFail($this->editingId) : new Quote();

                $cot->fill([
                    'origin_branch_id' => $data['origin_branch_id'],
                    'destination_branch_id' => $data['destination_branch_id'],
                    'customer_id' => $data['customer_id'],
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'] ?: null,
                    'customer_phone' => $data['customer_phone'] ?: null,
                    'shipment_type' => $this->shipment_type ?: null,
                    'notes' => $data['notes'] ?: null,
                    'valid_until' => $data['valid_until'] ?: null,
                    'subtotal' => $this->subtotal,
                    'tax_total' => $this->taxTotal,
                    'total' => $this->total,
                ]);

                if (! $cot->exists) {
                    $cot->code = Quote::siguienteCodigo();
                    $cot->created_by = auth()->id();
                }

                $cot->save();

                $cot->items()->delete();

                foreach ($data['items'] as $item) {
                    $cot->items()->create([
                        'package_type_id' => $item['package_type_id'] ?: null,
                        'description' => $item['description'] ?: null,
                        'weight' => $item['weight'] ?: null,
                        'length_cm' => $item['length_cm'] ?: null,
                        'width_cm' => $item['width_cm'] ?: null,
                        'height_cm' => $item['height_cm'] ?: null,
                        'price' => $item['price'],
                    ]);
                }

                $this->editingId = $cot->id;
            });
        } catch (QueryException $e) {
            report($e);
            $this->notify('error', 'No se pudo guardar la cotización.');

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->notify('success', 'Cotización guardada. Ya la podés descargar o enviar por correo.');
    }

    /** Abre el formulario de envío con el correo del cliente ya puesto. */
    public function abrirEnvio(int $id): void
    {
        $this->feedback = null;

        if (! $cot = Quote::find($id)) {
            $this->notify('error', 'La cotización ya no existe.');

            return;
        }

        $this->enviandoId = $cot->id;
        $this->enviarA = (string) $cot->customer_email;
    }

    public function cancelarEnvio(): void
    {
        $this->enviandoId = null;
        $this->enviarA = '';
    }

    public function enviar(): void
    {
        $this->feedback = null;

        $this->validate(
            ['enviarA' => 'required|email'],
            ['enviarA.required' => 'Hace falta el correo a donde mandarla.',
             'enviarA.email' => 'Ese correo no tiene un formato válido.']
        );

        if (! $cot = Quote::with(['items.packageType', 'originBranch', 'destinationBranch'])->find($this->enviandoId)) {
            $this->notify('error', 'La cotización ya no existe.');

            return;
        }

        try {
            Notification::route('mail', $this->enviarA)->notify(new EnviarProforma($cot));
        } catch (\Throwable $e) {
            report($e);
            Log::warning("No se pudo enviar la proforma {$cot->code}: " . $e->getMessage());
            $this->notify('error', 'No se pudo enviar el correo. Revisá la configuración de correo del sistema.');

            return;
        }

        $cot->forceFill(['sent_at' => now(), 'sent_to' => $this->enviarA])->save();

        $this->cancelarEnvio();
        $this->notify('success', "Cotización {$cot->code} enviada a {$cot->sent_to}.");
    }

    public function delete(int $id): void
    {
        $this->feedback = null;

        if (! $cot = Quote::find($id)) {
            $this->notify('error', 'La cotización ya no existe.');

            return;
        }

        if ($cot->fueAceptada()) {
            $this->notify('error', "«{$cot->code}» ya se convirtió en una guía: no se puede eliminar.");

            return;
        }

        $cot->delete();
        $this->notify('success', 'Cotización eliminada.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'origin_branch_id', 'destination_branch_id', 'customer_id',
            'customer_name', 'customer_email', 'customer_phone', 'shipment_type', 'notes', 'quote', 'preciosSugeridos']);
        $this->items = [$this->renglonEnBlanco()];
        $this->aplicarImpuesto = true;
        $this->valid_until = now()->addMonth()->toDateString();
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.quotes.quote-index', [
            'cotizaciones' => Quote::with(['originBranch', 'destinationBranch', 'creator'])
                ->latest('id')
                ->paginate(15),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name', 'prefix']),
            'clientes' => Customer::active()->orderBy('name')->get(['id', 'name', 'identification']),
            'tiposDeBulto' => PackageType::active()->get(),
        ])->layout('layouts.app', ['title' => 'Cotizaciones']);
    }
}
