<?php

namespace App\Livewire\Invoices;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class InvoiceForm extends Component
{
    public ?Invoice $invoice = null;

    public ?int $pickup_branch_id = null;
    public ?int $delivery_branch_id = null;

    public string $sender_name = '';
    public string $sender_phone = '';
    public string $sender_identification = '';

    public string $recipient_name = '';
    public string $recipient_phone = '';
    public string $recipient_identification_type = '01';
    public string $recipient_identification = '';
    public string $recipient_email = '';

    public string $notes = '';
    public float $discount_amount = 0;
    public string $payment_method = 'cash';
    public ?int $assigned_to = null;

    /** @var array<int,array<string,mixed>> */
    public array $items = [];

    /** @var array<int> id de impuestos seleccionados */
    public array $selectedTaxes = [];

    public function mount(?Invoice $invoice = null): void
    {
        $this->items = [
            ['package_code' => '', 'size' => 'M', 'weight' => '', 'description' => '', 'price' => ''],
        ];

        if ($invoice && $invoice->exists) {
            $this->invoice = $invoice;
            $this->pickup_branch_id = $invoice->pickup_branch_id;
            $this->delivery_branch_id = $invoice->delivery_branch_id;
            $this->sender_name = $invoice->sender_name;
            $this->sender_phone = (string) $invoice->sender_phone;
            $this->sender_identification = (string) $invoice->sender_identification;
            $this->recipient_name = $invoice->recipient_name;
            $this->recipient_phone = (string) $invoice->recipient_phone;
            $this->recipient_identification_type = $invoice->recipient_identification_type ?: '01';
            $this->recipient_identification = (string) $invoice->recipient_identification;
            $this->recipient_email = (string) $invoice->recipient_email;
            $this->notes = (string) $invoice->notes;
            $this->discount_amount = (float) $invoice->discount_amount;
            $this->payment_method = $invoice->payment_method ?: 'cash';
            $this->assigned_to = $invoice->assigned_to;
            $this->items = $invoice->items->map(fn ($i) => [
                'package_code' => $i->package_code,
                'size' => $i->size,
                'weight' => $i->weight,
                'description' => $i->description,
                'price' => (float) $i->price,
            ])->toArray();
            $this->selectedTaxes = $invoice->taxes->pluck('tax_id')->filter()->toArray();
        } else {
            $this->selectedTaxes = Tax::where('is_default', true)->pluck('id')->toArray();
        }
    }

    public function addItem(): void
    {
        $this->items[] = ['package_code' => '', 'size' => 'M', 'weight' => '', 'description' => '', 'price' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->items)->sum(fn ($i) => (float) ($i['price'] ?? 0));
    }

    public function getTaxTotalProperty(): float
    {
        $base = $this->subtotal - (float) $this->discount_amount;
        $percent = Tax::whereIn('id', $this->selectedTaxes)->sum('percent');
        return round($base * $percent / 100, 2);
    }

    public function getTotalProperty(): float
    {
        return round($this->subtotal - (float) $this->discount_amount + $this->taxTotal, 2);
    }

    protected function rules(): array
    {
        return [
            'pickup_branch_id' => 'required|exists:branches,id',
            'delivery_branch_id' => 'required|exists:branches,id',
            'sender_name' => 'required|string|max:150',
            'sender_phone' => 'nullable|string|max:30',
            'sender_identification' => 'nullable|string|max:20',
            'recipient_name' => 'required|string|max:150',
            'recipient_phone' => 'nullable|string|max:30',
            'recipient_identification' => 'nullable|string|max:20',
            'recipient_email' => 'nullable|email',
            'assigned_to' => 'nullable|exists:users,id',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:' . implode(',', array_keys(Invoice::PAYMENT_METHODS)),
            'items' => 'required|array|min:1',
            'items.*.package_code' => 'required|string|max:100',
            'items.*.price' => 'required|numeric|min:0',
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        DB::transaction(function () use ($data) {
            $invoice = $this->invoice ?: new Invoice();
            $invoice->fill([
                'pickup_branch_id' => $data['pickup_branch_id'],
                'delivery_branch_id' => $data['delivery_branch_id'],
                'sender_name' => $data['sender_name'],
                'sender_phone' => $data['sender_phone'],
                'sender_identification' => $data['sender_identification'],
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'],
                'recipient_identification_type' => $this->recipient_identification_type,
                'recipient_identification' => $data['recipient_identification'],
                'recipient_email' => $data['recipient_email'],
                'notes' => $this->notes,
                'discount_amount' => $data['discount_amount'] ?: 0,
                'payment_method' => $data['payment_method'],
                'assigned_to' => $data['assigned_to'],
                'subtotal' => $this->subtotal,
                'tax_total' => $this->taxTotal,
                'total' => $this->total,
            ]);
            if (!$invoice->exists) {
                $invoice->created_by = auth()->id();
                $invoice->status = Invoice::STATUS_PENDING;
            }
            $invoice->save();

            $invoice->items()->delete();
            foreach ($data['items'] as $item) {
                $invoice->items()->create([
                    'package_code' => $item['package_code'],
                    'size' => $item['size'] ?? null,
                    'weight' => $item['weight'] !== '' ? $item['weight'] : null,
                    'description' => $item['description'] ?? null,
                    'price' => $item['price'],
                ]);
            }

            $invoice->taxes()->delete();
            foreach (Tax::whereIn('id', $this->selectedTaxes)->get() as $tax) {
                $base = $this->subtotal - (float) $this->discount_amount;
                $invoice->taxes()->create([
                    'tax_id' => $tax->id,
                    'name' => $tax->name,
                    'percent' => $tax->percent,
                    'hacienda_code' => $tax->hacienda_code,
                    'amount' => round($base * $tax->percent / 100, 2),
                ]);
            }

            $this->invoice = $invoice;
        });

        session()->flash('success', 'Factura guardada correctamente.');
        $this->redirect(route('invoices.show', $this->invoice), navigate: false);
    }

    public function render()
    {
        return view('livewire.invoices.invoice-form', [
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'taxes' => Tax::where('is_active', true)->orderBy('name')->get(),
            'repartidores' => User::where('role', User::ROLE_REPARTIDOR)->where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => $this->invoice ? 'Editar factura' : 'Nueva factura']);
    }
}
