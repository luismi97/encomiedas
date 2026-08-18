<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_CAJERO = 'cajero';
    public const ROLE_REPARTIDOR = 'repartidor';

    public const ROLES = [
        self::ROLE_ADMIN      => 'Administrador',
        self::ROLE_CAJERO     => 'Cajero',
        self::ROLE_REPARTIDOR => 'Repartidor',
    ];

    /** Un color por rol. Con un condicional binario, todo lo que no era admin salía como repartidor. */
    public const ROLE_BADGE_CLASSES = [
        self::ROLE_ADMIN      => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200',
        self::ROLE_CAJERO     => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        self::ROLE_REPARTIDOR => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    ];

    /**
     * Roles que no pueden existir sin sede.
     *
     * Un cajero opera la caja de SU sede: sin sede asignada no habría contra
     * cuál validar, y terminaría viendo la caja de cualquiera.
     */
    public const ROLES_CON_SEDE = [self::ROLE_CAJERO];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'branch_id',
        'phone',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** Usa la notificación propia, en español. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\RestablecerContrasena($token));
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /** Opera guías, cobros y recepción de cierres en su sede. */
    public function puedeOperarCaja(): bool
    {
        return $this->isAdmin() || $this->isCajero();
    }

    /** Configura el sistema: sedes, tarifas, impuestos, usuarios, Hacienda. */
    public function puedeConfigurar(): bool
    {
        return $this->isAdmin();
    }

    /** Un cajero solo ve lo de su sede; el administrador ve todo. */
    public function limitadoASuSede(): bool
    {
        return $this->isCajero();
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isCajero(): bool
    {
        return $this->role === self::ROLE_CAJERO;
    }

    public function isRepartidor(): bool
    {
        return $this->role === self::ROLE_REPARTIDOR;
    }
}
