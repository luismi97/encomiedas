<?php

namespace App\Services\Hacienda;

use App\Models\Branch;
use App\Models\ElectronicInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Construye la clave numérica (50 dígitos) y el consecutivo (20 dígitos) de
 * un comprobante electrónico de Costa Rica (Hacienda v4.4).
 *
 *   Clave (50) = país(3) + DD(2) + MM(2) + YY(2) + cédula(12)
 *              + consecutivo(20) + situación(1) + códigoSeguridad(8)
 *
 *   Consecutivo (20) = sucursal(3) + terminal(5) + tipoComprobante(2) + secuencia(10)
 */
class ClaveGenerator
{
    private const MAX_CONSECUTIVO_ATTEMPTS = 50;

    /**
     * @return array{clave:string, consecutivo:string, security_code:string}
     */
    public function generate(
        Branch $branch,
        string $documentCode,
        string $emisorCedula,
        Carbon $issuedAt,
        string $situacion = '1'
    ): array {
        $consecutivo  = $this->allocateUnusedConsecutivo($branch, $documentCode);
        $securityCode = $this->securityCode();

        $clave = config('hacienda.country_code')
            . $issuedAt->format('dmy')
            . $this->padCedula($emisorCedula)
            . $consecutivo
            . $situacion
            . $securityCode;

        return [
            'clave'         => $clave,
            'consecutivo'   => $consecutivo,
            'security_code' => $securityCode,
        ];
    }

    /** Consecutivo que la empresa no haya emitido nunca (evita duplicados tras restaurar un backup). */
    private function allocateUnusedConsecutivo(Branch $branch, string $documentCode): string
    {
        for ($attempt = 0; $attempt < self::MAX_CONSECUTIVO_ATTEMPTS; $attempt++) {
            $consecutivo = $this->allocateConsecutivo($branch, $documentCode);

            $taken = ElectronicInvoice::where('consecutivo', $consecutivo)->exists();

            if (!$taken) {
                return $consecutivo;
            }
        }

        throw new \RuntimeException(
            'No se pudo asignar un consecutivo libre para la sucursal ' . $branch->id .
            ' (tipo ' . $documentCode . '). Ejecute: php artisan hacienda:repair-sequences'
        );
    }

    public function allocateConsecutivo(Branch $branch, string $documentCode): string
    {
        $sucursalCode = preg_replace('/\D/', '', (string) ($branch->sucursal_code ?: '1'));
        $terminalCode = preg_replace('/\D/', '', (string) ($branch->terminal_code ?: '1'));

        $sucursal = str_pad($sucursalCode ?: '1', 3, '0', STR_PAD_LEFT);
        $terminal = str_pad($terminalCode ?: '1', 5, '0', STR_PAD_LEFT);

        if (!preg_match('/^\d{2}$/', $documentCode)) {
            throw new \RuntimeException("Código de documento inválido: $documentCode");
        }

        $next = DB::transaction(function () use ($branch, $documentCode) {
            $sequence = \App\Models\ElectronicBillingSequence::where('branch_id', $branch->id)
                ->where('document_type', $documentCode)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                $sequence = \App\Models\ElectronicBillingSequence::create([
                    'branch_id'     => $branch->id,
                    'document_type' => $documentCode,
                    'last_number'   => 0,
                ]);
            }

            $sequence->last_number = $sequence->last_number + 1;
            $sequence->save();

            return $sequence->last_number;
        });

        return $sucursal . $terminal . $documentCode . str_pad((string) $next, 10, '0', STR_PAD_LEFT);
    }

    private function padCedula(string $cedula): string
    {
        return str_pad(preg_replace('/\D/', '', $cedula), 12, '0', STR_PAD_LEFT);
    }

    private function securityCode(): string
    {
        return str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
