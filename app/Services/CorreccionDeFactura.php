<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corrige a quién se factura una guía ya creada, sin tocar ningún monto.
 *
 * Cubre los dos casos que pidió el negocio: un dato de Hacienda mal digitado
 * (cédula, nombre, actividad) y una guía que salió como tiquete y después
 * el cliente pidió factura con cédula.
 */
class CorreccionDeFactura
{
    /**
     * Las columnas de la guía para facturar a esta persona, o para tiquete.
     *
     * Siempre como «otra persona»: así no se pisan los datos del remitente ni
     * del destinatario, que son los del envío.
     *
     * @param  array{nombre:string, tipo:string, numero:string, email:?string, actividad:?string}|null  $datos
     */
    public static function columnas(?array $datos): array
    {
        if ($datos === null) {
            return ['bill_type' => Invoice::BILL_TICKET];
        }

        return [
            'bill_type'                   => Invoice::BILL_INVOICE,
            'bill_to'                     => Invoice::BILL_TO_OTHER,
            'billing_name'                => $datos['nombre'],
            'billing_identification_type' => $datos['tipo'],
            'billing_identification'      => $datos['numero'],
            'billing_email'               => $datos['email'] ?? null,
            'billing_activity_code'       => $datos['actividad'] ?? null,
        ];
    }

    /**
     * Solo el administrador. Si la guía ya tiene comprobante, se rehace con
     * clave nueva siempre que no haya llegado a Hacienda; si ya fue aceptado,
     * lo que corresponde es una nota de crédito y no se toca.
     */
    public function corregir(Invoice $guia, User $usuario, ?array $datos): Invoice
    {
        if (! $usuario->isAdmin()) {
            throw new RuntimeException('Solo un administrador puede corregir los datos de facturación de una guía.');
        }

        if ($guia->status === Invoice::STATUS_CANCELLED) {
            throw new RuntimeException("La guía {$guia->code} está anulada: no se factura.");
        }

        $comprobante = $guia->electronicInvoice()->first();

        if ($comprobante && ! in_array($comprobante->status, [ElectronicInvoice::STATUS_PENDING, ElectronicInvoice::STATUS_REJECTED], true)) {
            throw new RuntimeException($comprobante->status === ElectronicInvoice::STATUS_ACCEPTED
                ? 'El comprobante de esta guía ya fue aceptado por Hacienda: no se puede cambiar. '
                    . 'Corregilo con una nota de crédito y emití uno nuevo.'
                : 'El comprobante de esta guía está en Hacienda («' . $comprobante->statusLabel() . '»). '
                    . 'Esperá la respuesta antes de corregirlo.');
        }

        $antes = $guia->receptorIdentificado() ? $guia->receptorDeFactura() : null;

        DB::transaction(function () use ($guia, $datos, $comprobante) {
            $guia->forceFill(self::columnas($datos))->save();

            if ($comprobante) {
                app(ElectronicBillingService::class)->rehacerPorCambioDeReceptor($comprobante);
            }
        });

        $guia = $guia->fresh();

        // A nombre de quien corrigió, no de auth(): el servicio también se usa
        // fuera de una petición.
        ActivityLog::create([
            'user_id'     => $usuario->id,
            'invoice_id'  => $guia->id,
            'action'      => 'billing_corrected',
            'description' => "{$usuario->name} corrigió la facturación de {$guia->code}: "
                . ($antes ? "antes {$antes['nombre']} ({$antes['numero']})" : 'antes tiquete')
                . ', ahora ' . ($datos ? "{$datos['nombre']} ({$datos['numero']})" : 'tiquete') . '.',
        ]);

        return $guia;
    }
}
