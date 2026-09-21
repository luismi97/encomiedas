<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use BelongsToCompany;

    /**
     * Dueño del sistema, no de una empresa.
     *
     * Es el único rol con company_id en null, y de ahí sale todo lo demás: sin
     * empresa en contexto el ámbito global no filtra, así que ve las de todos.
     * Por eso no entra a las pantallas de operación —no tendría con cuál de
     * todas trabajar—: para eso suplanta al administrador de una empresa.
     */
    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_ADMIN = 'admin';
    public const ROLE_CAJERO = 'cajero';
    public const ROLE_REPARTIDOR = 'repartidor';
    public const ROLE_DESPACHADOR = 'despachador';

    public const ROLES = [
        self::ROLE_ADMIN      => 'Administrador',
        self::ROLE_CAJERO     => 'Cajero',
        self::ROLE_REPARTIDOR => 'Repartidor',
        self::ROLE_DESPACHADOR => 'Despachador',
        self::ROLE_SUPERADMIN => 'Superadministrador',
    ];

    /**
     * Los roles que un administrador de empresa puede asignar.
     *
     * Sin el superadministrador: si apareciera en el selector de Usuarios,
     * cualquier administrador podría fabricarse acceso a las demás empresas.
     */
    public const ROLES_ASIGNABLES = [
        self::ROLE_ADMIN      => 'Administrador',
        self::ROLE_CAJERO     => 'Cajero',
        self::ROLE_REPARTIDOR => 'Repartidor',
        self::ROLE_DESPACHADOR => 'Despachador',
    ];

    /** Un color por rol. Con un condicional binario, todo lo que no era admin salía como repartidor. */
    public const ROLE_BADGE_CLASSES = [
        self::ROLE_ADMIN      => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200',
        self::ROLE_CAJERO     => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        self::ROLE_REPARTIDOR => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        self::ROLE_DESPACHADOR => 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200',
        self::ROLE_SUPERADMIN => 'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-100',
    ];

    /**
     * Roles que no pueden existir sin sede.
     *
     * Un cajero opera la caja de SU sede: sin sede asignada no habría contra
     * cuál validar, y terminaría viendo la caja de cualquiera.
     */
    public const ROLES_CON_SEDE = [self::ROLE_CAJERO, self::ROLE_DESPACHADOR];

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
        'company_id',
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
        return $this->isCajero() || $this->isDespachador();
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isSuperadmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function isCajero(): bool
    {
        return $this->role === self::ROLE_CAJERO;
    }

    public function isRepartidor(): bool
    {
        return $this->role === self::ROLE_REPARTIDOR;
    }

    public function isDespachador(): bool
    {
        return $this->role === self::ROLE_DESPACHADOR;
    }

    /**
     * Arma y despacha cierres de envío, y recibe los que llegan.
     *
     * Es todo lo que hace: no cobra, no crea guías y no toca la configuración.
     * El rol existe para la persona de bodega que carga el camión.
     */
    public function puedeDespachar(): bool
    {
        return $this->isAdmin() || $this->isCajero() || $this->isDespachador();
    }
}
