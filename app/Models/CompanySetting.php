<?php

namespace App\Models;

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
        return static::query()->firstOrCreate([], ['environment' => 'sandbox']);
    }

    public function environmentConfig(): array
    {
        return config('hacienda.environments.' . $this->environment);
    }

    /** ¿Hay lo mínimo para firmar y transmitir un comprobante? */
    public function isReady(): bool
    {
        return $this->enabled
            && $this->identification_number
            && $this->certificate_path
            && $this->certificate_pin
            && $this->atv_username
            && $this->atv_password;
    }
}
