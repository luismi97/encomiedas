<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Una empresa cliente del sistema.
 *
 * Es el inquilino: todo lo demás —sedes, guías, cajas, configuración fiscal—
 * cuelga de acá. Antes había que desplegar una copia entera del sistema por
 * cliente; ahora se crea una fila.
 *
 * La empresa NO se borra desde la operación diaria: se desactiva. Su historial
 * de comprobantes está transmitido a Hacienda y tiene que poder consultarse
 * aunque el cliente ya no sea cliente.
 */
class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'identification',
        'email',
        'phone',
        'is_active',
        'expires_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'expires_on' => 'date',
        ];
    }

    /*
     | Todas las relaciones van sin ámbito global, y no es un detalle.
     |
     | El ámbito recorta a la empresa que está operando; acá la empresa ya es
     | ESTA, la del objeto. Dejarlo puesto significa preguntar «dame los
     | usuarios de Transportes López que además sean de la empresa activa», que
     | da vacío en el único momento en que importa: el superadministrador
     | mirando una empresa que no es la suya —o sea, siempre—.
     */

    public function users(): HasMany
    {
        return $this->hasMany(User::class)->withoutGlobalScopes();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->withoutGlobalScopes();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->withoutGlobalScopes();
    }

    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class)->withoutGlobalScopes();
    }

    /** El administrador con el que se creó la empresa: a quien se le entrega el acceso. */
    public function admin(): ?User
    {
        return $this->users()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * ¿Puede operar hoy?
     *
     * Son dos cosas distintas y las dos cierran la puerta: desactivada a mano
     * por el superadministrador, o con la vigencia vencida. Separarlas permite
     * que el mensaje de la pantalla diga cuál de las dos pasó.
     */
    public function puedeOperar(): bool
    {
        return $this->is_active && ! $this->estaVencida();
    }

    public function estaVencida(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }

    /** Por qué no puede entrar, en palabras, o null si sí puede. */
    public function motivoDeBloqueo(): ?string
    {
        if (! $this->is_active) {
            return 'La cuenta de ' . $this->name . ' está suspendida. Contactá al proveedor del sistema.';
        }

        if ($this->estaVencida()) {
            return 'La suscripción de ' . $this->name . ' venció el '
                . $this->expires_on->format('d/m/Y') . '. Contactá al proveedor del sistema.';
        }

        return null;
    }

    /**
     * Un identificador de URL libre, derivado del nombre.
     *
     * Se numera si está tomado en vez de fallar: el superadministrador está
     * dando de alta a un cliente, y que dos se llamen «Transportes López» es
     * problema del sistema, no suyo.
     */
    public static function slugLibre(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'empresa';
        $candidato = Str::limit($base, 50, '');

        for ($i = 2; static::where('slug', $candidato)->exists(); $i++) {
            $candidato = Str::limit($base, 50, '') . '-' . $i;
        }

        return $candidato;
    }
}
