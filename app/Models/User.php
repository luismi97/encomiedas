<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * Atiende el mostrador: crea guías, entrega paquetes y arma y despacha los
     * cierres de envío. No abre caja (sus guías de contado quedan esperando
     * que un cajero las cobre, y no entrega lo que falta cobrar), no ve sumas
     * de dinero ni reportes, y puede atender varias sedes (branch_user).
     */
    public const ROLE_DEPENDIENTE = 'dependiente';

    public const ROLES = [
        self::ROLE_ADMIN      => 'Administrador',
        self::ROLE_CAJERO     => 'Cajero',
        self::ROLE_DEPENDIENTE => 'Dependiente',
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
        self::ROLE_DEPENDIENTE => 'Dependiente',
        self::ROLE_REPARTIDOR => 'Repartidor',
        self::ROLE_DESPACHADOR => 'Despachador',
    ];

    /** Un color por rol. Con un condicional binario, todo lo que no era admin salía como repartidor. */
    public const ROLE_BADGE_CLASSES = [
        self::ROLE_ADMIN      => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200',
        self::ROLE_CAJERO     => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        self::ROLE_DEPENDIENTE => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
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
    public const ROLES_CON_SEDE = [self::ROLE_CAJERO, self::ROLE_DESPACHADOR, self::ROLE_DEPENDIENTE];

    /** Roles que pueden atender otras sedes además de la base (branch_user). */
    public const ROLES_MULTISEDE = [self::ROLE_CAJERO, self::ROLE_DEPENDIENTE];

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
        'can_collect',
        'phone',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    /** @var array<int,int>|null */
    private ?array $sedesCache = null;

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
            'can_collect' => 'boolean',
            'guide_state' => 'array',
        ];
    }

    // ── Guías de pantalla ─────────────────────────────────────────────

    /** Ya la terminó o dijo que no la quiere ver: en los dos casos, no se abre sola. */
    public function yaVioLaGuia(string $clave): bool
    {
        return isset(($this->guide_state ?? [])[$clave]);
    }

    /**
     * Deja constancia de qué pasó con la guía de una pantalla.
     *
     * Se distingue «terminada» de «descartada» aunque las dos la cierren igual:
     * sirve para saber si el recorrido ayuda o si todo el mundo lo salta, que es
     * lo único que dice si vale la pena mantenerlo.
     */
    public function marcarGuia(string $clave, string $estado): void
    {
        $estados = $this->guide_state ?? [];
        $estados[$clave] = ['estado' => $estado, 'en' => now()->toIso8601String()];

        $this->forceFill(['guide_state' => $estados])->save();
    }

    /** La vuelve a abrir la próxima vez que se entre a esa pantalla. */
    public function olvidarGuia(string $clave): void
    {
        $estados = $this->guide_state ?? [];
        unset($estados[$clave]);

        $this->forceFill(['guide_state' => $estados])->save();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Sedes adicionales que atiende (cajero y dependiente, ver ROLES_MULTISEDE). */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withTimestamps();
    }

    /**
     * Las sedes cuyas guías y cajas puede ver y operar: la base y, si es
     * cajero o dependiente, las que el administrador le marcó.
     *
     * @return array<int,int>
     */
    public function sedesIds(): array
    {
        // Se guarda en la instancia: BranchScope lo pide en cada consulta, y
        // auth()->user() es la misma instancia durante toda la petición.
        return $this->sedesCache ??= $this->calcularSedes();
    }

    /** Tras cambiarle las sedes, para que la misma instancia no siga con las viejas. */
    public function olvidarSedes(): void
    {
        $this->sedesCache = null;
        $this->unsetRelation('branches');
    }

    /** @return array<int,int> */
    private function calcularSedes(): array
    {
        $ids = $this->branch_id ? [(int) $this->branch_id] : [];

        if (in_array($this->role, self::ROLES_MULTISEDE, true)) {
            $ids = [...$ids, ...$this->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all()];
        }

        return array_values(array_unique($ids));
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

    /**
     * Anula guías. Solo el administrador: anular borra un cobro de la
     * operación y una guía anulada no vuelve. El cajero que se equivocó le
     * pide al administrador que la anule, y queda a nombre de quien lo hizo.
     */
    public function puedeAnular(): bool
    {
        return $this->isAdmin();
    }

    /** Configura el sistema: sedes, tarifas, impuestos, usuarios, Hacienda. */
    public function puedeConfigurar(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Si maneja dinero: abre caja y cobra.
     *
     * En una sede puede haber quien solo recibe paquetes y quien cobra. El que
     * no cobra igual registra guías de contado, pero quedan esperando su pago
     * en caja (Invoice::awaiting_cashier) en vez de exigirle un turno abierto.
     * El administrador siempre puede; repartidor y despachador nunca cobran.
     */
    public function puedeCobrar(): bool
    {
        return $this->isAdmin() || ($this->isCajero() && $this->can_collect !== false);
    }

    /** Un cajero solo ve lo de su sede; el administrador ve todo. */
    public function limitadoASuSede(): bool
    {
        return $this->isCajero() || $this->isDespachador() || $this->isDependiente();
    }

    /** Registra guías en el mostrador. */
    public function puedeCrearGuias(): bool
    {
        return $this->isAdmin() || $this->isCajero() || $this->isDependiente();
    }

    /**
     * Ve sumas de dinero: el PDF del listado de guías, Reportes y Crédito.
     * Solo administración; cajeros y dependientes ven el monto de cada guía,
     * no los totales.
     */
    public function puedeVerDinero(): bool
    {
        return $this->isAdmin();
    }

    public function isDependiente(): bool
    {
        return $this->role === self::ROLE_DEPENDIENTE;
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
     * Para el despachador es todo lo que hace: no cobra, no crea guías y no
     * toca la configuración; el rol existe para la persona de bodega que carga
     * el camión. El dependiente también: en una sede chica, quien atiende el
     * mostrador es el mismo que carga el camión.
     */
    public function puedeDespachar(): bool
    {
        return $this->isAdmin() || $this->isCajero() || $this->isDespachador() || $this->isDependiente();
    }
}
