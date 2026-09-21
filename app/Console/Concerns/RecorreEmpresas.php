<?php

namespace App\Console\Concerns;

use App\Models\Company;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Corre un comando programado una vez por empresa.
 *
 * Las tareas de fondo —el corte de crédito, la consulta a Hacienda, el desecho
 * de guías— corren sin usuario, y sin usuario el ámbito global no filtra: el
 * comando vería las guías de todas las empresas mezcladas y les aplicaría la
 * configuración de la primera que encontrara. Acá se recorre empresa por
 * empresa fijando el contexto, y cada pasada ve exactamente lo suyo.
 *
 * Si una empresa revienta, las demás siguen: que el certificado vencido de un
 * cliente deje sin correr la tarea de los otros veinte es peor que el problema
 * original.
 */
trait RecorreEmpresas
{
    /**
     * @param  Closure(Company):int  $callback  Devuelve un código de salida por empresa.
     */
    protected function porCadaEmpresa(Closure $callback): int
    {
        $empresas = $this->empresasDelRecorrido();

        if ($empresas->isEmpty()) {
            $this->components->warn('No hay ninguna empresa activa.');

            return self::SUCCESS;
        }

        $salida = self::SUCCESS;

        foreach ($empresas as $empresa) {
            if ($empresas->count() > 1) {
                $this->newLine();
                $this->components->info("Empresa: {$empresa->name}");
            }

            try {
                $resultado = CompanyContext::para($empresa, fn () => $callback($empresa));

                if ($resultado !== self::SUCCESS) {
                    $salida = self::FAILURE;
                }
            } catch (Throwable $e) {
                // Se reporta y se sigue: el resto de las empresas no tiene la
                // culpa de esta.
                $this->components->error("«{$empresa->name}» falló: " . $e->getMessage());
                report($e);
                $salida = self::FAILURE;
            }
        }

        return $salida;
    }

    /**
     * Las empresas de esta corrida.
     *
     * Con --empresa se atiende a una sola: sirve para reintentar la que falló
     * sin volver a procesar las demás. Acepta el id o el identificador de URL.
     *
     * @return Collection<int,Company>
     */
    private function empresasDelRecorrido(): Collection
    {
        $filtro = $this->hasOption('empresa') ? $this->option('empresa') : null;

        return Company::query()
            ->when(
                $filtro,
                fn ($q) => $q->where(fn ($q) => $q->where('id', $filtro)->orWhere('slug', $filtro)),
                fn ($q) => $q->active()
            )
            ->orderBy('id')
            ->get();
    }
}
