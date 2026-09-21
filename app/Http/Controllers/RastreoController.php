<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Seguimiento público de una guía. Sin login: se llega escaneando el QR del
 * recibo o digitando el código.
 *
 * Muestra estado y recorrido, NADA de datos personales ni montos. Aunque
 * alguien recorriera los consecutivos a fuerza bruta, no obtendría información
 * aprovechable — que es la protección que de verdad importa, más que el límite
 * de intentos.
 *
 * Con varias empresas en la misma instalación el código guía dejó de ser único:
 * dos clientes con una sede «SJ» emiten los dos un SJ-LIM-00005. Por eso el QR
 * lleva la empresa en la URL. La forma corta —los recibos impresos antes, y
 * quien digita el código a mano— sigue funcionando: si el código existe en una
 * sola empresa se muestra, y si existe en varias se pregunta cuál.
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

    public function ver(Request $request)
    {
        $code = (string) $request->route('code');
        $slug = $request->route('empresa');

        // Fuera del aislamiento a propósito: el portal es público y tiene que
        // llegar a la guía de cualquier empresa. No alcanza con el
        // withoutGlobalScopes de la consulta —las sedes del recorrido se cargan
        // aparte, y un cajero de otra empresa mirando este código las vería
        // vacías—. El recorte por empresa lo hace este método.
        $coincidencias = CompanyContext::sinAlcance(
            fn () => $this->guiasConEseCodigo($code, $slug)
        );

        if ($coincidencias->isEmpty()) {
            return view('rastreo.buscar', [
                'codigo' => $code,
                'error'  => "No encontramos ninguna encomienda con el código «{$code}». "
                    . 'Revisá que esté completo, incluidos los guiones.',
            ]);
        }

        // Mismo código en dos empresas: se pregunta en vez de adivinar. Mostrar
        // la de una de las dos sería mostrarle a alguien el paquete de otro.
        if ($coincidencias->count() > 1) {
            return view('rastreo.elegir-empresa', [
                'codigo'  => $code,
                'opciones' => $coincidencias->map(fn (Invoice $guia) => [
                    'empresa' => $guia->company?->name ?? 'Empresa',
                    'slug'    => $guia->company?->slug,
                ])->filter(fn (array $o) => $o['slug'] !== null)->values(),
            ]);
        }

        $guia = $coincidencias->first();

        return view('rastreo.ver', [
            'guia'       => $guia,
            'empresa'    => $guia->company,
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

    /**
     * Las guías con ese código: una si la empresa viene en la URL, todas las
     * que coincidan si no.
     *
     * El límite no es cosmético: sin él, un código repetido en cincuenta
     * empresas cargaría cincuenta guías con sus relaciones para una pantalla
     * que solo va a listar nombres.
     *
     * @return Collection<int,Invoice>
     */
    private function guiasConEseCodigo(string $code, ?string $slug): Collection
    {
        $empresa = $slug ? Company::where('slug', $slug)->first() : null;

        if ($slug && ! $empresa) {
            return collect();
        }

        return Invoice::withoutGlobalScopes()
            ->with([
                'company:id,name,slug',
                'pickupBranch:id,name,prefix',
                'deliveryBranch:id,name,prefix',
                'statusHistories.branch:id,name',
            ])
            ->where('code', $code)
            ->when($empresa, fn ($q) => $q->where('company_id', $empresa->id))
            ->limit(10)
            ->get();
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
