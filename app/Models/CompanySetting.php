<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = [
        'enabled',
        'environment',
        'name',
        'commercial_name',
        'identification_type',
        'identification_number',
        'activity_code',
        'province',
        'canton',
        'district',
        'barrio',
        'others_signs',
        'phone_code',
        'phone',
        'email',
        'atv_username',
        'atv_password',
        'certificate_path',
        'certificate_pin',
        'default_cabys_code',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'atv_username' => 'encrypted',
            'atv_password' => 'encrypted',
            'certificate_pin' => 'encrypted',
        ];
    }

    /** Fila única de configuración (crea una por defecto si no existe). */
    public static function instance(): self
    {
        $settings = static::query()->firstOrCreate([], ['environment' => 'sandbox']);

        // Los valores por defecto de las columnas (enabled, phone_code, ...) los
        // pone la base, no Eloquent: el modelo recien insertado no los tiene en
        // memoria y devolveria null en propiedades tipadas. Releerlo los trae.
        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }

    /**
     * ¿Se emite realmente contra el Hacienda de producción?
     *
     * Hacen falta DOS condiciones. `environment` vive en la base, y la base se
     * clona a local y a staging: una copia de la producción traería 'prod' y
     * emitiría contra Hacienda real desde la laptop de cualquiera, quemando
     * consecutivos verdaderos. `hacienda.live` es del servidor (variable de
     * entorno, no de la base) y solo se pone en true en el .env de producción.
     */
    public function isProduction(): bool
    {
        return $this->environment === 'prod' && config('hacienda.live');
    }

    public function environmentConfig(): array
    {
        return config('hacienda.environments.' . ($this->isProduction() ? 'prod' : 'sandbox'));
    }

    /** Ambiente contra el que se va a emitir de verdad (para mostrarlo en pantalla). */
    public function effectiveEnvironment(): string
    {
        return $this->isProduction() ? 'prod' : 'sandbox';
    }

    /**
     * Lee un campo cifrado sin reventar cuando no se puede descifrar.
     *
     * Si el APP_KEY del servidor cambia (restauro de .env, base copiada de otra
     * instalación), el cast 'encrypted' lanza DecryptException apenas se toca el
     * atributo, y eso tumbaba con un 500 la pantalla de Ajustes — justo la única
     * desde donde se pueden reescribir las credenciales. Devolviendo null la
     * pantalla abre, avisa cuál campo se perdió y el admin lo vuelve a digitar.
     */
    public function decryptedOrNull(string $attribute): ?string
    {
        try {
            return $this->getAttribute($attribute);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Campos que tienen algo guardado pero ya no se pueden descifrar.
     *
     * @return array<int,string>
     */
    public function undecryptableFields(): array
    {
        $fields = [];

        foreach (['atv_username', 'atv_password', 'certificate_pin'] as $attribute) {
            if (!empty($this->getRawOriginal($attribute)) && $this->decryptedOrNull($attribute) === null) {
                $fields[] = $attribute;
            }
        }

        return $fields;
    }

    /** ¿Hay lo mínimo para firmar y transmitir un comprobante? */
    public function isReady(): bool
    {
        return $this->enabled
            && $this->identification_number
            && $this->certificate_path
            && $this->decryptedOrNull('certificate_pin')
            && $this->decryptedOrNull('atv_username')
            && $this->decryptedOrNull('atv_password');
    }
}
