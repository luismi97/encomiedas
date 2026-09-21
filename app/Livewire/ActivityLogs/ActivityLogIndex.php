<?php

namespace App\Livewire\ActivityLogs;

use Illuminate\Support\Carbon;
use App\Models\ActivityLog;
use App\Models\User;
use App\Livewire\Concerns\ScrollInfinito;
use Livewire\Component;

class ActivityLogIndex extends Component
{
    use ScrollInfinito;

    public $userId = null;
    public string $from = '';
    public string $to = '';

    public function updating($name): void
    {
        if (in_array($name, ['userId', 'from', 'to'], true)) {
            $this->reiniciarScroll();
        }
    }

    public function render()
    {
        $query = ActivityLog::query()->with(['user', 'invoice'])->latest();

        if ($this->userId) {
            $query->where('user_id', $this->userId);
        }
        if ($this->from) {
            // Rango sobre la columna cruda: whereDate() anula el índice.
            $query->where('created_at', '>=', Carbon::parse($this->from)->startOfDay());
        }
        if ($this->to) {
            $query->where('created_at', '<=', Carbon::parse($this->to)->endOfDay());
        }

        $tanda = $this->tanda($query);

        return view('livewire.activity-logs.activity-log-index', [
            'logs' => $tanda['items'],
            'scroll' => $tanda,
            'users' => User::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Actividad de usuarios']);
    }
}
