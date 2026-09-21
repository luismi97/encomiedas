<?php

namespace App\Support;

use App\Models\Company;
use Closure;

/**
 * Qué empresa está operando en este momento.
 *
 * Es la única fuente de verdad del aislamiento: el ámbito global la consulta en
 * cada consulta, y BelongsToCompany la usa para llenar company_id al insertar.
 *
 * Hay dos maneras de saberlo y por eso esto existe en vez de leer
 * `auth()->user()->company_id` donde haga falta:
 *
 *  1. En el navegador la trae el usuario autenticado.
 *  2. En la cola y en los comandos programados NO hay usuario —el worker de
 *     Hacienda procesa comprobantes de todas las empresas, el corte de crédito
 *     recorre todas— y ahí la empresa se fija a mano, comprobante por
 *     comprobante, con para(). Sin esto, el job de una empresa firmaría con el
 *     certificado de otra.
 *
 * El estado es estático y el worker de la cola vive horas atendiendo trabajos
 * de empresas distintas, así que AppServiceProvider lo limpia antes de cada
 * trabajo. Sin esa limpieza, el segundo trabajo heredaría la empresa del
 * primero, que es exactamente la fuga que todo esto viene a evitar.
 */
final class CompanyContext
{
    private static ?Company $empresa = null;

    /** Distingue «todavía no lo busqué» de «busqué y no hay». */
    private static bool $resuelta = false;

    /** Fijada a mano con usar()/para(): manda sobre el usuario autenticado. */
    private static bool $forzada = false;

    /** Suspende el aislamiento dentro de sinAlcance(). */
    private static bool $suspendida = false;

    public static function actual(): ?Company
    {
        if (self::$suspendida) {
            return null;
        }

        if (self::$forzada || self::$resuelta) {
            return self::$empresa;
        }

        /*
         | Sin usuario NO se memoriza nada, y esa es la parte importante.
         |
         | Esto se consulta desde el constructor de cada modelo, y el arranque de
         | la aplicación construye uno —AppServiceProvider registra el observador
         | de guías— antes de que exista sesión. Memorizando ahí, el «no hay
         | empresa» de ese instante quedaba grabado para TODA la petición: el
         | usuario entraba bien, veía su nombre y su sede, y sin embargo el
         | sistema operaba como si no perteneciera a ninguna empresa.
         |
         | Cuesta una llamada barata de más mientras no hay sesión; a cambio, el
         | orden en que se toque el contexto deja de importar.
         */
        if (! auth()->hasUser()) {
            return null;
        }

        self::$resuelta = true;
        self::$empresa = self::deLaSesion();

        return self::$empresa;
    }

    public static function id(): ?int
    {
        return self::actual()?->id;
    }

    public static function hay(): bool
    {
        return self::actual() !== null;
    }

    /** Fija la empresa activa. Con null se opera sin empresa (superadministrador). */
    public static function usar(Company|int|null $empresa): void
    {
        self::$empresa = is_int($empresa) ? Company::find($empresa) : $empresa;
        self::$resuelta = true;
        self::$forzada = true;
    }

    /**
     * Vuelve a no saber nada: la próxima consulta la deduce del usuario.
     *
     * Lo llama el worker de la cola entre trabajo y trabajo, y las pruebas
     * cuando cambian de usuario autenticado.
     */
    public static function olvidar(): void
    {
        self::$empresa = null;
        self::$resuelta = false;
        self::$forzada = false;
        self::$suspendida = false;
    }

    /**
     * Corre algo en nombre de una empresa y deja todo como estaba.
     *
     *     CompanyContext::para($guia->company_id, fn () => $servicio->emitir($guia));
     *
     * @template T
     * @param  Closure():T  $callback
     * @return T
     */
    public static function para(Company|int|null $empresa, Closure $callback): mixed
    {
        $anterior = [self::$empresa, self::$resuelta, self::$forzada, self::$suspendida];

        self::usar($empresa);

        try {
            return $callback();
        } finally {
            [self::$empresa, self::$resuelta, self::$forzada, self::$suspendida] = $anterior;
        }
    }

    /**
     * Corre algo viendo TODAS las empresas.
     *
     * Para los recorridos del superadministrador y los comandos programados que
     * por definición son globales. Se usa con cuidado: es la llave que abre el
     * aislamiento.
     *
     * @template T
     * @param  Closure():T  $callback
     * @return T
     */
    public static function sinAlcance(Closure $callback): mixed
    {
        $anterior = self::$suspendida;
        self::$suspendida = true;

        try {
            return $callback();
        } finally {
            self::$suspendida = $anterior;
        }
    }

    /**
     * La empresa del usuario autenticado.
     *
     * Devuelve null para el superadministrador, que no pertenece a ninguna, y
     * entonces el ámbito global no filtra: es lo que le permite ver las de
     * todos desde su panel.
     */
    private static function deLaSesion(): ?Company
    {
        $companyId = auth()->user()?->company_id;

        return $companyId ? Company::withoutGlobalScopes()->find($companyId) : null;
    }
}
