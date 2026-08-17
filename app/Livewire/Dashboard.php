<?php

namespace App\Livewire;

use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $user = auth()->user();

        $baseQuery = Invoice::query();
        if ($user->isRepartidor()) {
            $baseQuery->where('assigned_to', $user->id);
        }

        // whereDate() envuelve la columna en DATE() y anula el indice: MySQL
        // termina recorriendo la tabla entera en cada carga del panel. Un rango
        // sobre la columna cruda si usa el indice.
        $desde = today()->startOfDay();
        $hasta = today()->endOfDay();

        // Un solo recorrido en vez de cuatro COUNT separados.
        $conteos = (clone $baseQuery)->selectRaw(
            'SUM(created_at BETWEEN ? AND ?) AS hoy,
             SUM(status = ?) AS pendientes,
             SUM(status = ?) AS en_transito,
             SUM(status = ? AND delivered_at BETWEEN ? AND ?) AS entregadas_hoy',
            [
                $desde, $hasta,
                Invoice::STATUS_PENDING,
                Invoice::STATUS_IN_TRANSIT,
                Invoice::STATUS_DELIVERED, $desde, $hasta,
            ]
        )->first();

        $todayCount = (int) ($conteos->hoy ?? 0);
        $pendingCount = (int) ($conteos->pendientes ?? 0);
        $inTransitCount = (int) ($conteos->en_transito ?? 0);
        $deliveredCount = (int) ($conteos->entregadas_hoy ?? 0);

        $haciendaPending = $user->isAdmin()
            ? ElectronicInvoice::where('status', ElectronicInvoice::STATUS_PENDING)->count()
            : 0;

        // Sin eager loading, la tabla de recientes hacia 2 consultas por fila
        // para pintar las sucursales.
        $recent = (clone $baseQuery)
            ->with(['pickupBranch:id,name', 'deliveryBranch:id,name'])
            ->latest()
            ->limit(8)
            ->get();

        return view('livewire.dashboard', [
            'todayCount' => $todayCount,
            'pendingCount' => $pendingCount,
            'inTransitCount' => $inTransitCount,
            'deliveredCount' => $deliveredCount,
            'haciendaPending' => $haciendaPending,
            'recent' => $recent,
        ])->layout('layouts.app', ['title' => 'Inicio']);
    }
}
