<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Guarda una guía: la fila, sus bultos, sus impuestos y su cobro.
 *
 * Vivía dentro de InvoiceForm::save(). Salió de ahí cuando apareció un segundo
 * camino para crear guías —las hechas sin conexión, que se suben después—: una
 * guía sincronizada tiene que quedar IDÉNTICA a una hecha en línea, y la única
 * forma de garantizarlo es que las dos pasen por el mismo código.
 *
 * No valida ni calcula: recibe los montos ya resueltos. Quien llama decide
 * cuáles son (el formulario los calcula; la sincronización respeta los que se
 * cobraron sin conexión) y valida a su manera.
 */
class RegistroDeGuia
{
    public const COBRO_PREPAID = 'prepaid';
    public const COBRO_COLLECT = 'collect';
    public const COBRO_CREDIT  = 'credit';

    public function __construct(private CajaService $caja)
    {
    }

    /**
     * @param  array<string,mixed>  $datos  columnas de la guía más 'cobro', 'items' y 'tax_ids'
     */
    public function guardar(?Invoice $invoice, array $datos, User $usuario): Invoice
    {
        return DB::transaction(function () use ($invoice, $datos, $usuario) {
            $invoice ??= new Invoice();
            $cobro = $datos['cobro'];

            $invoice->fill(collect($datos)->except(['cobro', 'items', 'tax_ids', 'created_at'])->all());

            $invoice->sale_condition = $cobro === self::COBRO_CREDIT
                ? Invoice::SALE_CREDIT
                : Invoice::SALE_CASH;

            $invoice->payment_timing = $cobro === self::COBRO_COLLECT
                ? Invoice::TIMING_COLLECT
                : Invoice::TIMING_PREPAID;

            // De contado y recibida por alguien que no cobra: queda esperando
            // su pago en caja. Una que ya esperaba sigue esperando aunque la
            // edite un cajero —se cobra en la caja, no guardando el
            // formulario—, y una que ya se cobró no vuelve atrás.
            $yaCobrada = $invoice->exists
                && $invoice->getOriginal('payment_timing') === Invoice::TIMING_PREPAID
                && $invoice->getOriginal('sale_condition') === Invoice::SALE_CASH
                && ! $invoice->getOriginal('awaiting_cashier');

            $invoice->awaiting_cashier = $cobro === self::COBRO_PREPAID
                && ! $yaCobrada
                && ($invoice->awaiting_cashier || ! $usuario->puedeCobrar());

            if (! $invoice->exists) {
                $invoice->created_by = $usuario->id;
                $invoice->status = Invoice::STATUS_PENDING;

                // Una guía hecha sin conexión nació cuando se recibió el
                // paquete, no cuando volvió el internet.
                if (! empty($datos['created_at'])) {
                    $invoice->created_at = $datos['created_at'];
                }
            }
            $invoice->save();

            $invoice->items()->delete();
            foreach ($datos['items'] as $item) {
                $invoice->items()->create([
                    'package_type_id' => $item['package_type_id'],
                    'size' => $item['size'] ?? null,
                    'weight' => $item['weight'] ?? null,
                    'length_cm' => $item['length_cm'] ?? null,
                    'width_cm' => $item['width_cm'] ?? null,
                    'height_cm' => $item['height_cm'] ?? null,
                    'description' => $item['description'] ?? null,
                    'price' => $item['price'],
                ]);
            }

            $invoice->taxes()->delete();
            foreach (Tax::whereIn('id', $datos['tax_ids'] ?? [])->get() as $tax) {
                $base = (float) $datos['subtotal'] - (float) ($datos['discount_amount'] ?? 0);
                $invoice->taxes()->create([
                    'tax_id' => $tax->id,
                    'name' => $tax->name,
                    'percent' => $tax->percent,
                    'hacienda_code' => $tax->hacienda_code,
                    'amount' => round($base * $tax->percent / 100, 2),
                ]);
            }

            // A la caja de origen solo entra lo que se paga aquí y ahora. Un
            // «por cobrar» se cobra en destino al entregar, y una guía a
            // crédito no se cobra: suma al saldo del cliente.
            // Quien llama ya comprobó que hay caja abierta: acá solo se registra.
            if ($cobro === self::COBRO_PREPAID && ! $invoice->awaiting_cashier) {
                $this->caja->registrarCobro($invoice, $usuario);
            }

            return $invoice;
        });
    }
}
