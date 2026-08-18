<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * Seguimiento público de una guía. Sin login: se llega escaneando el QR del
 * recibo o digitando el código.
 *
 * Muestra estado y recorrido, NADA de datos personales ni montos. Aunque
 * alguien recorriera los consecutivos a fuerza bruta, no obtendría información
 * aprovechable — que es la protección que de verdad importa, más que el límite
 * de intentos.
 */
class RastreoController extends Controller
{
    public function buscar(Request $request)
    {
        $codigo = trim((string) $request->query('codigo', ''));

        if ($codigo === '') {
            return view('rastreo.buscar', ['codigo' => '', 'error' => null]);
        }

        return redirect()->route('rastreo.ver', ['code' => $codigo]);
    }

    public function ver(string $code)
    {
        $guia = Invoice::withoutGlobalScopes()
            ->with([
                'pickupBranch:id,name,prefix',
                'deliveryBranch:id,name,prefix',
                'statusHistories.branch:id,name',
            ])
            ->where('code', $code)
            ->first();

        if (! $guia) {
            return view('rastreo.buscar', [
                'codigo' => $code,
                'error'  => "No encontramos ninguna encomienda con el código «{$code}». "
                    . 'Revisá que esté completo, incluidos los guiones.',
            ]);
        }

        return view('rastreo.ver', [
            'guia'       => $guia,
            'recorrido'  => $guia->statusHistories,
            // Nombre parcial: confirma al destinatario sin exponerlo.
            'receptor'   => $this->enmascarar($guia->recipient_name),
            'porVencer'  => $guia->status === Invoice::STATUS_NEAR_DISPOSAL,
            // Para que el portal muestre expectativas realistas de retiro.
            'sedeAbierta'     => $guia->deliveryBranch?->estaAbierta() ?? true,
            'proximaApertura' => $guia->deliveryBranch?->proximaApertura(),
            'fechaLimite' => $guia->disposal_warned_at
                ? $guia->disposal_warned_at->copy()->addDays((int) config('encomiendas.disposal.dispose_after_days', 15))
                : null,
        ]);
    }

    /** «José Fernández» → «José F.» */
    private function enmascarar(?string $nombre): string
    {
        $partes = preg_split('/\s+/', trim((string) $nombre)) ?: [];

        if (count($partes) <= 1) {
            return $partes[0] ?? '—';
        }

        return $partes[0] . ' ' . mb_strtoupper(mb_substr($partes[1], 0, 1)) . '.';
    }
}
