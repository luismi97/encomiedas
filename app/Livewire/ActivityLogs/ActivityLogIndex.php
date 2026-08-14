<?php

namespace App\Livewire\ActivityLogs;

use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class ActivityLogIndex extends Component
{
    use WithPagination;

    public ?int $userId = null;
    public string $from = '';
    public string $to = '';

    public function updating($name): void
    {
        if (in_array($name, ['userId', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $query = ActivityLog::query()->with(['user', 'invoice'])->latest();

        if ($this->userId) {
            $query->where('user_id', $this->userId);
        }
        if ($this->from) {
            $query->whereDate('created_at', '>=', $this->from);
        }
        if ($this->to) {
            $query->whereDate('created_at', '<=', $this->to);
        }

        return view('livewire.activity-logs.activity-log-index', [
            'logs' => $query->paginate(20),
            'users' => User::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Actividad de usuarios']);
    }
}
