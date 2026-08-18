<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'prefix',
        'sucursal_code',
        'terminal_code',
        'address',
        'province',
        'canton',
        'district',
        'phone',
        'receipt_paper_width',
        'business_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'business_hours' => 'array',
        ];
    }

    /** Nombres de los días, indexados como los devuelve Carbon (0 = domingo). */
    public const DIAS = [
        0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles',
        4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado',
    ];

    /** Horario de un día concreto, o null si ese día no abre. */
    public function horarioDe(int $diaSemana): ?array
    {
        $horario = $this->business_hours[$diaSemana] ?? $this->business_hours[(string) $diaSemana] ?? null;

        return is_array($horario) && filled($horario['abre'] ?? null) ? $horario : null;
    }

    /**
     * ¿Está abierta en este momento?
     *
     * Un feriado cierra aunque el día tenga horario: es lo que evita prometerle
     * al cliente una entrega el 1 de enero.
     */
    public function estaAbierta(?\Illuminate\Support\Carbon $momento = null): bool
    {
        $momento ??= now();

        if (Holiday::esFeriado($momento)) {
            return false;
        }

        $horario = $this->horarioDe((int) $momento->dayOfWeek);

        if (! $horario) {
            return false;
        }

        $hora = $momento->format('H:i');

        return $hora >= $horario['abre'] && $hora <= ($horario['cierra'] ?? '23:59');
    }

    /** Próximo momento en que abre, para decirle al cliente cuándo volver. */
    public function proximaApertura(?\Illuminate\Support\Carbon $desde = null): ?\Illuminate\Support\Carbon
    {
        $cursor = ($desde ?? now())->copy();

        // Se buscan 14 días: más allá, algo está mal configurado y es mejor no
        // responder que dar una fecha inventada.
        for ($i = 0; $i < 14; $i++) {
            $dia = $cursor->copy()->addDays($i);

            if (Holiday::esFeriado($dia)) {
                continue;
            }

            if (! $horario = $this->horarioDe((int) $dia->dayOfWeek)) {
                continue;
            }

            $apertura = $dia->copy()->setTimeFromTimeString($horario['abre']);

            // Contra $cursor y no contra now(): el método recibe desde cuándo
            // buscar, y compararlo con la hora real ignoraba ese parámetro.
            if ($apertura->greaterThan($cursor)) {
                return $apertura;
            }
        }

        return null;
    }

    protected static function booted(): void
    {
        // Sin prefijo no hay código guía: GuideCodeGenerator no puede armar
        // SJ-LIM-00005 y el observer cae al ENC-000045 de respaldo. El
        // formulario lo exige, pero los seeders creaban sedes sin él y ahí se
        // perdía el formato de ruta sin que nada avisara.
        static::creating(function (self $sede) {
            if (blank($sede->prefix)) {
                $sede->prefix = self::prefijoSugerido((string) $sede->name);
            }

            $sede->prefix = strtoupper(trim((string) $sede->prefix));
        });

        // Una sede recién creada nace con su caja: antes solo existían si
        // alguien corría CajaSeeder a mano.
        static::created(function (self $sede) {
            $sede->cashRegisters()->create([
                'name' => self::CAJA_PRINCIPAL,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Prefijo tentativo a partir del nombre: «Limón Centro» => LIM.
     *
     * La columna es única, así que ante un choque prueba con cuatro letras y
     * después numera. Un prefijo feo es mejor que una sede que no se deja
     * crear, y el administrador lo corrige desde la pantalla de sedes.
     */
    public static function prefijoSugerido(string $nombre, ?int $ignorarId = null): string
    {
        $limpio = strtoupper(preg_replace('/[^A-Za-z]/', '', Str::ascii($nombre)) ?? '');
        $base = substr($limpio ?: 'SED', 0, 3);

        $libre = function (string $candidato) use ($ignorarId): bool {
            return ! static::query()
                ->where('prefix', $candidato)
                ->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))
                ->exists();
        };

        foreach ([$base, substr($limpio, 0, 4)] as $candidato) {
            if (strlen($candidato) >= 2 && $libre($candidato)) {
                return $candidato;
            }
        }

        $raiz = substr($base, 0, 2) ?: 'SE';

        for ($i = 2; $i <= 99; $i++) {
            if ($libre($raiz . $i)) {
                return $raiz . $i;
            }
        }

        throw new \RuntimeException("No hay prefijo libre para la sede «{$nombre}».");
    }

    /** Nombre de la caja que se crea sola con cada sede. */
    public const CAJA_PRINCIPAL = 'Caja principal';

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pickupInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'pickup_branch_id');
    }

    public function deliveryInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'delivery_branch_id');
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(ElectronicBillingSequence::class);
    }

    /** Encomiendas que todavia no llegaron a un estado final. */
    public function inProgressInvoices(): Builder
    {
        return Invoice::query()
            ->where(fn (Builder $q) => $q->where('pickup_branch_id', $this->id)->orWhere('delivery_branch_id', $this->id))
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_IN_TRANSIT]);
    }

    /** Cualquier encomienda ligada a la sucursal, en cualquier estado. */
    public function allInvoices(): Builder
    {
        return Invoice::query()
            ->where(fn (Builder $q) => $q->where('pickup_branch_id', $this->id)->orWhere('delivery_branch_id', $this->id));
    }

    /**
     * El consecutivo de Hacienda se construye con sucursal + terminal. Si ya se
     * emitio un comprobante con estos codigos, cambiarlos rompe la numeracion.
     */
    public function hasHaciendaHistory(): bool
    {
        return $this->sequences()->where('last_number', '>', 0)->exists();
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** Anchos de rollo que usan las térmicas del mercado, en milímetros. */
    public const PAPER_WIDTHS = [58, 80];

    /**
     * Ancho del rollo saneado: una fila vieja puede traer null o un valor raro,
     * y una etiqueta con el ancho equivocado sale cortada.
     */
    public function receiptPaperWidthMm(): int
    {
        $ancho = (int) ($this->receipt_paper_width ?? 0);

        return in_array($ancho, self::PAPER_WIDTHS, true) ? $ancho : 80;
    }

    /** Prefijo del código guía (SJ-LIM-00005), en mayúsculas. */
    public function prefixLabel(): string
    {
        return strtoupper((string) $this->prefix);
    }

    public function codeLabel(): string
    {
        return $this->sucursal_code . '-' . $this->terminal_code;
    }
}
