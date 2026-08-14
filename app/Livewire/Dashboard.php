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

        $todayCount = (clone $baseQuery)->whereDate('created_at', today())->count();
        $pendingCount = (clone $baseQuery)->where('status', Invoice::STATUS_PENDING)->count();
        $inTransitCount = (clone $baseQuery)->where('status', Invoice::STATUS_IN_TRANSIT)->count();
        $deliveredCount = (clone $baseQuery)->where('status', Invoice::STATUS_DELIVERED)
            ->whereDate('delivered_at', today())->count();

        $haciendaPending = $user->isAdmin()
            ? ElectronicInvoice::where('status', ElectronicInvoice::STATUS_PENDING)->count()
            : 0;

        $recent = (clone $baseQuery)->latest()->limit(8)->get();

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
