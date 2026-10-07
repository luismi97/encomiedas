<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            $esNueva = ! $invoice->exists;

            if ($esNueva) {
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
                // Llega el precio por bulto; se guarda el total de la línea.
                $cantidad = max(1, (int) ($item['quantity'] ?? 1));

                $invoice->items()->create([
                    'package_type_id' => $item['package_type_id'],
                    'quantity' => $cantidad,
                    'size' => $item['size'] ?? null,
                    'weight' => $item['weight'] ?? null,
                    'length_cm' => $item['length_cm'] ?? null,
                    'width_cm' => $item['width_cm'] ?? null,
                    'height_cm' => $item['height_cm'] ?? null,
                    'description' => $item['description'] ?? null,
                    'price' => round((float) $item['price'] * $cantidad, 5),
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

            if ($esNueva) {
                $this->reservarComprobante($invoice);
            }

            return $invoice;
        });
    }

    /**
     * Reserva el consecutivo del comprobante desde que se recibe el paquete,
     * para que el recibo del cliente salga con él.
     *
     * Solo reserva: el comprobante queda «pendiente de envío» y lo transmite
     * el administrador cuando decida, igual que antes. La fecha de emisión
     * —y con ella la clave definitiva— se pone al enviarlo: Hacienda no acepta
     * fechas anteriores a la generación del comprobante.
     *
     * Un fallo acá no puede tumbar la guía: el paquete ya se recibió. Queda
     * sin clave en el recibo y se reserva al entregar, como antes.
     */
    private function reservarComprobante(Invoice $invoice): void
    {
        try {
            app(ElectronicBillingService::class)->queueForInvoice($invoice->fresh());
        } catch (\Throwable $e) {
            Log::warning("No se pudo reservar el comprobante de la guía {$invoice->code}: {$e->getMessage()}");
        }
    }
}
