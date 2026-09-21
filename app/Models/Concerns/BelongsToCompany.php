<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Scopes\CompanyScope;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca un modelo como propiedad de una empresa.
 *
 * Hace las dos mitades del aislamiento, que siempre van juntas:
 *  - al consultar, el ámbito global recorta a la empresa activa;
 *  - al crear, se llena company_id solo.
 *
 * Lo segundo importa tanto como lo primero: una fila guardada sin company_id
 * no la ve nadie después —ni su propia empresa—, y el rastro de por qué
 * «desapareció» una guía es de los más caros de seguir.
 */
trait BelongsToCompany
{
    /**
     * Puesto por sinEmpresa(): «esta fila no es de nadie, y es a propósito».
     *
     * Es una propiedad del objeto y no un atributo: no se guarda ni viaja a la
     * base, solo le dice al relleno automático que se quede quieto.
     */
    protected bool $deliberadamenteSinEmpresa = false;

    /**
     * Declara que la fila no pertenece a ninguna empresa.
     *
     * Hoy solo lo usa el superadministrador, que es del sistema y no de un
     * cliente. Sin esto no había forma de expresarlo: poner company_id en null
     * a mano no servía, porque el relleno automático lo volvía a llenar con la
     * empresa que hubiera activa al guardar —y el dueño del sistema terminaba
     * de empleado de su primer cliente—.
     */
    public function sinEmpresa(): static
    {
        $this->deliberadamenteSinEmpresa = true;
        $this->company_id = null;

        return $this;
    }

    /** @internal Lo consulta el relleno automático. */
    public function estaSinEmpresaAPosta(): bool
    {
        return $this->deliberadamenteSinEmpresa;
    }

    /**
     * Se llena al construir el modelo, no solo al guardarlo.
     *
     * El evento `creating` por sí solo no alcanza: `Event::fake()` lo silencia
     * —y con él media suite de pruebas empezó a crear filas sin empresa—, y lo
     * mismo hacen `saveQuietly()` y `Model::withoutEvents()`. Acá el valor se
     * pone en el constructor, que no hay forma de saltarse.
     *
     * En un modelo que viene de la base esto no molesta: newFromBuilder
     * construye primero y después reemplaza los atributos con los de la fila,
     * así que el company_id que manda es siempre el que está guardado.
     */
    public function initializeBelongsToCompany(): void
    {
        // Acceso directo al arreglo: esto corre en CADA instancia de modelo,
        // incluidas las sesenta que trae un listado, y getAttribute() se iría a
        // buscar una relación llamada «company_id» antes de rendirse.
        if (($this->attributes['company_id'] ?? null) === null) {
            $this->attributes['company_id'] = CompanyContext::id();
        }
    }

    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        // Red de seguridad del constructor: cubre el caso de un modelo armado
        // fuera de contexto y guardado dentro de él —lo típico de un job, que
        // arma la fila antes de saber de qué empresa es el comprobante—.
        static::creating(function ($model) {
            if ($model->company_id === null && ! $model->estaSinEmpresaAPosta()) {
                $model->company_id = CompanyContext::id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
