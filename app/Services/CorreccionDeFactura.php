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
     * Solo el administrador. Si la guía ya tiene comprobante y no ha llegado a
     * Hacienda, se rehace con clave nueva. Si Hacienda ya lo aceptó, no se
     * puede tocar: se anula con una nota de crédito por lo que le quede y se
     * emite uno nuevo, que queda pendiente de envío como cualquier otro.
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
        $aceptado = $comprobante?->status === ElectronicInvoice::STATUS_ACCEPTED;

        if ($comprobante && ! $aceptado && ! in_array($comprobante->status, [ElectronicInvoice::STATUS_PENDING, ElectronicInvoice::STATUS_REJECTED], true)) {
            throw new RuntimeException('El comprobante de esta guía está en Hacienda («' . $comprobante->statusLabel() . '»). '
                . 'Esperá la respuesta antes de corregirlo.');
        }

        $antes = $guia->receptorIdentificado() ? $guia->receptorDeFactura() : null;

        // Anular y reemitir consume dos consecutivos: no por un guardar sin cambios.
        if ($aceptado && $this->mismoReceptor($antes, $datos)) {
            throw new RuntimeException('Los datos de facturación son los mismos del comprobante aceptado: no hay nada que corregir.');
        }

        $hacienda = app(ElectronicBillingService::class);
        $nota = null;

        // La nota va primero y por fuera: si falla, la guía queda como estaba.
        // Si lo que falla es lo de abajo, al reintentar el saldo ya está en
        // cero y no se emite una segunda nota.
        if ($aceptado && ($saldo = $comprobante->saldoSinAcreditar()) > 0) {
            $nota = $hacienda->issueNote(
                $comprobante,
                'NC',
                mb_substr("Se reemplaza por {$this->nombreDelNuevo($datos)} (guía {$guia->code}).", 0, 180),
                $saldo
            );
        }

        $nuevo = DB::transaction(function () use ($guia, $datos, $comprobante, $aceptado, $hacienda) {
            $guia->forceFill(self::columnas($datos))->save();

            if ($aceptado) {
                return $hacienda->reemitir($guia);
            }

            if ($comprobante) {
                $hacienda->rehacerPorCambioDeReceptor($comprobante);
            }

            return null;
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
                . ', ahora ' . ($datos ? "{$datos['nombre']} ({$datos['numero']})" : 'tiquete') . '.'
                . ($nuevo ? " Se anuló el {$comprobante->typeLabel()} {$comprobante->consecutivo}"
                    . ($nota ? " con la nota de crédito {$nota->consecutivo}" : '')
                    . " y quedó pendiente el {$nuevo->typeLabel()} {$nuevo->consecutivo}." : ''),
        ]);

        return $guia;
    }

    private function nombreDelNuevo(?array $datos): string
    {
        return $datos ? "Factura Electrónica a {$datos['nombre']} ({$datos['numero']})" : 'Tiquete Electrónico';
    }

    /** ¿Lo pedido es exactamente lo que ya dice el comprobante? */
    private function mismoReceptor(?array $antes, ?array $datos): bool
    {
        if ($antes === null || $datos === null) {
            return $antes === $datos;
        }

        return [$antes['nombre'], $antes['tipo'], $antes['numero'], $antes['email'] ?: null, $antes['activity_code'] ?: null]
            === [$datos['nombre'], $datos['tipo'], $datos['numero'], $datos['email'] ?? null, $datos['actividad'] ?? null];
    }
}
