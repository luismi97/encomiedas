<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\CompanyContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CompanySetting extends Model
{
    use BelongsToCompany;

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
        'insurance_percent',
        'discount_authorization_code',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'atv_username' => 'encrypted',
            'atv_password' => 'encrypted',
            'certificate_pin' => 'encrypted',
            'discount_authorization_code' => 'encrypted',
            'insurance_percent' => 'decimal:2',
        ];
    }

    /**
     * La configuración fiscal de la empresa activa (la crea vacía si no existe).
     *
     * Era una fila única del sistema entero. Con varias empresas hay una por
     * empresa, y el ámbito global de BelongsToCompany es el que decide cuál: el
     * firstOrCreate sale ya recortado a la empresa en contexto, y el company_id
     * lo pone el trait al insertar.
     *
     * Ojo con el certificado: quien llame a esto fuera del navegador —la cola,
     * un comando— tiene que haber fijado la empresa con CompanyContext, o sin
     * empresa en contexto se traería la primera fila que encuentre, que es la
     * de otro emisor. Por eso lanza en vez de adivinar.
     */
    /**
     * El nombre con el que la empresa se presenta en pantalla.
     *
     * Sale de la base y no de APP_NAME: el .env lo fija quien monta el servidor
     * una sola vez, y el nombre de la empresa lo cambia su administrador desde
     * Configuración. Con varias empresas en el mismo sistema, además, un nombre
     * del entorno sería el mismo para todas.
     *
     * Se prefiere el nombre comercial sobre la razón social, que es la misma
     * regla del recibo y de la etiqueta: lo que el cliente ve impreso y lo que
     * el cajero ve en pantalla tienen que ser la misma empresa.
     */
    public static function marca(): string
    {
        $configuracion = static::deLaMarca();

        $nombre = trim((string) ($configuracion?->commercial_name ?: $configuracion?->name));

        // La fila de Company existe aunque la configuración fiscal esté en
        // blanco: recién instalada, la empresa ya tiene nombre y todavía no
        // tiene cédula ni certificado.
        return $nombre
            ?: trim((string) CompanyContext::actual()?->name)
            ?: (string) config('app.name');
    }

    /**
     * La configuración con la que rotular la pantalla, o null si no hay una sola.
     *
     * A diferencia de instance(), esto NUNCA lanza ni crea filas: poner el
     * rótulo del menú no puede tumbar la pantalla. El superadministrador no está
     * dentro de ninguna empresa, así que para él no hay nombre que mostrar y se
     * cae al del sistema, que es donde está parado de verdad.
     */
    private static function deLaMarca(): ?self
    {
        if (CompanyContext::hay()) {
            // El ámbito global ya recorta a la empresa activa.
            return static::query()->first();
        }

        // Instalación de una sola empresa: no hay ambigüedad que resolver.
        $todas = static::query()->limit(2)->get();

        return $todas->count() === 1 ? $todas->first() : null;
    }

    public static function instance(): self
    {
        if (! CompanyContext::hay()) {
            $fueraDeContexto = static::query()->orderBy('id')->get();

            // Instalación de una sola empresa: no hay ambigüedad que resolver y
            // los comandos de siempre siguen funcionando sin cambios.
            if ($fueraDeContexto->count() === 1) {
                return $fueraDeContexto->first();
            }

            if ($fueraDeContexto->count() > 1) {
                throw new RuntimeException(
                    'Se pidió la configuración fiscal sin empresa en contexto y hay varias. '
                    . 'Envolvé la llamada en CompanyContext::para($companyId, ...) para saber con '
                    . 'qué certificado firmar.'
                );
            }
        }

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
    /**
     * Porcentaje del valor declarado que se cobra como seguro.
     *
     * Vive en configuración y no como constante: es una política comercial que
     * cambia, y tocarla no debería requerir un despliegue.
     */
    public function porcentajeDeSeguro(): float
    {
        return max(0.0, (float) ($this->insurance_percent ?? 0));
    }

    /** ¿Hace falta una clave para que un cajero descuente? */
    public function exigeClaveParaDescuento(): bool
    {
        return filled($this->decryptedOrNull('discount_authorization_code'));
    }

    /**
     * Comprueba la clave de autorización de descuentos.
     *
     * hash_equals y no ==: comparar cadenas de largo variable filtra el tamaño
     * de la clave por el tiempo de respuesta.
     */
    public function claveDeDescuentoValida(?string $clave): bool
    {
        $esperada = (string) $this->decryptedOrNull('discount_authorization_code');

        if ($esperada === '') {
            return true; // sin clave configurada, no se exige nada
        }

        return is_string($clave) && $clave !== '' && hash_equals($esperada, $clave);
    }

    public function isReady(): bool
    {
        return $this->faltantesParaFacturar() === [];
    }

    /**
     * Qué falta para poder emitir, en palabras.
     *
     * Devolver la lista y no un booleano permite que la pantalla diga el
     * motivo: sin esto, una guía entregada aparecía sin comprobante y el
     * mensaje culpaba a la entrega, que no tenía nada que ver.
     *
     * @return array<int,string>
     */
    public function faltantesParaFacturar(): array
    {
        $requisitos = [
            'Activar la facturación electrónica'   => (bool) $this->enabled,
            'La cédula del emisor'                 => (bool) $this->identification_number,
            'El certificado digital (.p12)'        => (bool) $this->certificate_path,
            'El PIN del certificado'               => (bool) $this->decryptedOrNull('certificate_pin'),
            'El usuario de ATV'                    => (bool) $this->decryptedOrNull('atv_username'),
            'La contraseña de ATV'                 => (bool) $this->decryptedOrNull('atv_password'),
        ];

        return array_keys(array_filter($requisitos, fn ($cumple) => ! $cumple));
    }
}
