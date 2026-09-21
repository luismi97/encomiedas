<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carga de clientes desde un archivo CSV.
 *
 * La cartera de una empresa de encomiendas ya existe antes que el sistema: está
 * en una hoja de cálculo que alguien mantiene desde hace años. Pedirle a esa
 * persona que la digite cliente por cliente es pedirle que no migre.
 *
 * El importador trabaja en dos tiempos a propósito: primero analiza y muestra
 * qué va a pasar con cada fila, y solo después escribe. Un archivo de dos mil
 * clientes que se importa a ciegas y sale mal no se deshace con un botón.
 *
 * Lo que se acepta del archivo es deliberadamente ancho —punto y coma o coma,
 * con acentos o sin ellos, «crédito» o «credit»— porque el archivo lo exportó
 * Excel en una computadora que nadie configuró, y rechazarlo por el separador
 * sería rechazarlo por algo que al usuario no le consta que exista.
 */
class ImportadorDeClientes
{
    /** Tope por archivo. Más que esto es una migración, no una carga. */
    public const MAXIMO_FILAS = 5000;

    /**
     * Columnas del archivo, en orden.
     *
     * El encabezado se compara sin acentos ni mayúsculas, así que «Identificación»
     * y «identificacion» son la misma columna.
     *
     * @var array<string,string>
     */
    public const COLUMNAS = [
        'nombre'              => 'Nombre o razón social (obligatorio)',
        'nombre_comercial'    => 'Nombre comercial',
        'tipo_identificacion' => 'fisica, juridica, dimex o nite',
        'identificacion'      => 'Solo dígitos, de 9 a 12',
        'correo'              => 'Correo electrónico',
        'telefono'            => 'Teléfono',
        'direccion'           => 'Dirección',
        'sede'                => 'Nombre o prefijo de la sucursal',
        'condicion_pago'      => 'contado o credito',
        'limite_credito'      => 'Solo si es de crédito',
        'dia_corte'           => 'Día del mes, del 1 al 31',
        'notas'               => 'Lo que haga falta recordar',
    ];

    /** Cómo se escribe cada tipo de identificación en el archivo. */
    private const TIPOS = [
        'fisica' => '01', 'física' => '01', 'f' => '01', '01' => '01', '1' => '01',
        'juridica' => '02', 'jurídica' => '02', 'j' => '02', '02' => '02', '2' => '02',
        'dimex' => '03', 'd' => '03', '03' => '03', '3' => '03',
        'nite' => '04', 'n' => '04', '04' => '04', '4' => '04',
    ];

    private const CONDICIONES = [
        'contado' => Customer::PAYMENT_CASH,
        'cash'    => Customer::PAYMENT_CASH,
        'credito' => Customer::PAYMENT_CREDIT,
        'crédito' => Customer::PAYMENT_CREDIT,
        'credit'  => Customer::PAYMENT_CREDIT,
    ];

    /**
     * El archivo de ejemplo que se descarga.
     *
     * Lleva una fila de contado y una de crédito: son los dos casos que se
     * comportan distinto, y verlos al lado explica el formato mejor que
     * cualquier instructivo.
     */
    public function plantilla(): string
    {
        $filas = [
            array_keys(self::COLUMNAS),
            ['Marta Solano Vargas', '', 'fisica', '112340567', 'marta@ejemplo.cr', '88887777',
                'San José centro', 'SJ', 'contado', '', '', ''],
            ['Distribuidora El Roble S.A.', 'El Roble', 'juridica', '3101234567', 'cuentas@elroble.cr',
                '22223333', 'Heredia, frente al parque', 'HER', 'credito', '500000', '15',
                'Factura a fin de mes'],
        ];

        // Punto y coma: es lo que Excel en español espera al abrir un CSV de un
        // doble clic. Con coma, todo cae en una sola columna y el usuario
        // concluye que la plantilla está mala.
        $csv = '';
        foreach ($filas as $fila) {
            $csv .= implode(';', array_map([$this, 'escapar'], $fila)) . "\r\n";
        }

        // BOM para que Excel reconozca UTF-8 y no destroce los acentos.
        return "\u{FEFF}" . $csv;
    }

    private function escapar(string $valor): string
    {
        return str_contains($valor, ';') || str_contains($valor, '"')
            ? '"' . str_replace('"', '""', $valor) . '"'
            : $valor;
    }

    /**
     * Lee el archivo y dice qué pasaría con cada fila, sin escribir nada.
     *
     * @return array{filas: array<int,array<string,mixed>>, errores: array<int,string>, total: int}
     */
    public function analizar(string $ruta): array
    {
        if (! is_readable($ruta)) {
            return ['filas' => [], 'errores' => ['No se pudo leer el archivo.'], 'total' => 0];
        }

        $manejador = fopen($ruta, 'r');
        $separador = $this->separadorDe($ruta);

        $encabezado = fgetcsv($manejador, 0, $separador);

        if (! $encabezado) {
            fclose($manejador);

            return ['filas' => [], 'errores' => ['El archivo está vacío.'], 'total' => 0];
        }

        $mapa = $this->mapearColumnas($encabezado);

        if (! isset($mapa['nombre'])) {
            fclose($manejador);

            return [
                'filas' => [],
                'errores' => ['El archivo no tiene una columna «nombre». Descargá la plantilla y usá sus encabezados.'],
                'total' => 0,
            ];
        }

        // Las cédulas que ya existen, de una sola consulta: preguntar por fila
        // serían dos mil consultas para un archivo de dos mil clientes.
        $existentes = Customer::withoutGlobalScopes()
            ->where('company_id', \App\Support\CompanyContext::id())
            ->whereNotNull('identification')
            ->pluck('id', 'identification')
            ->all();

        $sedes = $this->sedesPorClave();

        $filas = [];
        $errores = [];
        $vistasEnElArchivo = [];
        $numero = 1; // el encabezado es la fila 1

        while (($cruda = fgetcsv($manejador, 0, $separador)) !== false) {
            $numero++;

            if ($this->estaVacia($cruda)) {
                continue;
            }

            if (count($filas) >= self::MAXIMO_FILAS) {
                $errores[] = 'El archivo pasa de ' . number_format(self::MAXIMO_FILAS)
                    . ' filas. Partilo en varios: así se puede revisar lo que entra.';
                break;
            }

            $filas[] = $this->analizarFila($cruda, $mapa, $numero, $existentes, $vistasEnElArchivo, $sedes);
        }

        fclose($manejador);

        return ['filas' => $filas, 'errores' => $errores, 'total' => count($filas)];
    }

    /**
     * @param  array<string,int>  $mapa
     * @param  array<string,int>  $existentes
     * @param  array<string,int>  $vistasEnElArchivo  cédulas ya tomadas por filas buenas
     * @param  array<string,int>  $sedes
     * @return array<string,mixed>
     */
    private function analizarFila(
        array $cruda,
        array $mapa,
        int $numero,
        array $existentes,
        array &$vistasEnElArchivo,
        array $sedes
    ): array {
        $valor = function (string $columna) use ($cruda, $mapa): string {
            $indice = $mapa[$columna] ?? null;

            return $indice !== null ? trim((string) ($cruda[$indice] ?? '')) : '';
        };

        /*
         | Dos listas y no una.
         |
         | Un error deja la fila afuera: sin nombre no hay cliente, y una cédula
         | a medias se convierte mañana en un duplicado que nadie puede deshacer.
         | Un aviso entra igual: una sucursal mal escrita o un correo con un dedazo
         | no valen perder el cliente, y quien importa prefiere corregir eso después
         | a que se le rechacen doscientas filas por un detalle.
         */
        $errores = [];
        $avisos  = [];

        $nombre = $valor('nombre');
        if ($nombre === '') {
            $errores[] = 'Sin nombre.';
        } elseif (mb_strlen($nombre) > 150) {
            $avisos[] = 'El nombre pasa de 150 caracteres: se recorta.';
            $nombre = mb_substr($nombre, 0, 150);
        }

        // Los guiones de una cédula escrita a mano se quitan acá: rechazar
        // «1-1234-0567» sería rechazar la forma en que la escribe todo el país.
        $identificacion = preg_replace('/\D/', '', $valor('identificacion'));

        if ($identificacion !== '' && ! preg_match('/^\d{9,12}$/', $identificacion)) {
            $errores[] = 'La identificación no tiene entre 9 y 12 dígitos.';
            $identificacion = '';
        }

        $condicion = self::CONDICIONES[Str::lower($valor('condicion_pago'))] ?? Customer::PAYMENT_CASH;

        if ($condicion === Customer::PAYMENT_CREDIT && $identificacion === '') {
            $errores[] = 'Un cliente de crédito necesita identificación para poder facturarle.';
        }

        $correo = $valor('correo');
        if ($correo !== '' && ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $avisos[] = 'El correo no tiene forma de correo: se deja en blanco.';
            $correo = '';
        }

        $diaCorte = $valor('dia_corte');
        if ($diaCorte !== '' && (! ctype_digit($diaCorte) || (int) $diaCorte < 1 || (int) $diaCorte > 31)) {
            $avisos[] = 'El día de corte va del 1 al 31: se deja en blanco.';
            $diaCorte = '';
        }

        $limite = str_replace([',', ' '], '', $valor('limite_credito'));
        if ($limite !== '' && ! is_numeric($limite)) {
            $avisos[] = 'El límite de crédito no es un número: queda en cero.';
            $limite = '';
        }

        $sedeTexto = $valor('sede');
        $sedeId = $sedeTexto === '' ? null : ($sedes[$this->clave($sedeTexto)] ?? null);
        if ($sedeTexto !== '' && $sedeId === null) {
            $avisos[] = "No existe la sucursal «{$sedeTexto}»: el cliente queda sin sucursal.";
        }

        /*
         | La cédula se reserva al final y solo si la fila entra.
         |
         | Antes se reservaba al leerla, así que una fila rechazada por otra cosa
         | —un nombre en blanco— le robaba la cédula a la fila buena de más abajo,
         | y se perdían las dos por un problema que era de una sola.
         */
        if ($errores === [] && $identificacion !== '') {
            if (isset($vistasEnElArchivo[$identificacion])) {
                $errores[] = 'La identificación se repite en la fila '
                    . $vistasEnElArchivo[$identificacion] . ' del mismo archivo.';
            } else {
                $vistasEnElArchivo[$identificacion] = $numero;
            }
        }

        $tipo = $identificacion === ''
            ? null
            : (self::TIPOS[Str::lower($valor('tipo_identificacion'))] ?? $this->tipoSegunCedula($identificacion));

        $esCredito = $condicion === Customer::PAYMENT_CREDIT;

        return [
            'numero'    => $numero,
            'nombre'    => $nombre,
            'problemas' => $errores,
            'avisos'    => $avisos,
            'importable' => $errores === [],
            'existente' => $identificacion !== '' && isset($existentes[$identificacion]),
            'datos' => [
                'name'                => $nombre,
                'commercial_name'     => $valor('nombre_comercial') ?: null,
                'identification'      => $identificacion ?: null,
                'identification_type' => $tipo,
                'email'               => $correo ?: null,
                'phone'               => Str::limit($valor('telefono'), 30, ''),
                'address'             => Str::limit($valor('direccion'), 255, ''),
                'branch_id'           => $sedeId,
                'payment_condition'   => $condicion,
                'credit_limit'        => $esCredito ? (float) ($limite ?: 0) : 0.0,
                'credit_cutoff_day'   => $esCredito && $diaCorte !== '' ? (int) $diaCorte : null,
                'notes'               => $valor('notas') ?: null,
                'is_active'           => true,
            ],
        ];
    }

    /**
     * Escribe las filas sanas.
     *
     * @param  array<int,array<string,mixed>>  $filas  lo que devolvió analizar()
     * @return array{creados: int, actualizados: int, omitidos: int}
     */
    public function importar(array $filas, bool $actualizarExistentes = false): array
    {
        $creados = 0;
        $actualizados = 0;
        $omitidos = 0;

        // Todo o nada: media cartera importada es peor que ninguna, porque
        // nadie sabe dónde quedó el corte para reintentar desde ahí.
        DB::transaction(function () use ($filas, $actualizarExistentes, &$creados, &$actualizados, &$omitidos) {
            foreach ($filas as $fila) {
                if (! ($fila['importable'] ?? false)) {
                    $omitidos++;
                    continue;
                }

                $datos = $fila['datos'];
                $cedula = $datos['identification'];

                $existente = $cedula ? Customer::where('identification', $cedula)->first() : null;

                if ($existente && ! $actualizarExistentes) {
                    $omitidos++;
                    continue;
                }

                if ($existente) {
                    // Sin `is_active`: si alguien desactivó a ese cliente a
                    // propósito, una carga masiva no es quién para revivirlo.
                    $existente->update(collect($datos)->except('is_active')->all());
                    $actualizados++;

                    continue;
                }

                Customer::create($datos);
                $creados++;
            }
        });

        return ['creados' => $creados, 'actualizados' => $actualizados, 'omitidos' => $omitidos];
    }

    // ── Lectura del archivo ───────────────────────────────────────────

    /**
     * Punto y coma o coma, según cuál aparezca más en la primera línea.
     *
     * Excel en español exporta con punto y coma y en inglés con coma, y el
     * usuario no sabe cuál le tocó: si el importador exige uno, la mitad de los
     * archivos «no sirven» por una razón invisible.
     */
    private function separadorDe(string $ruta): string
    {
        $manejador = fopen($ruta, 'r');
        $primera = (string) fgets($manejador);
        fclose($manejador);

        return substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    }

    /**
     * Dónde quedó cada columna conocida.
     *
     * @param  array<int,string>  $encabezado
     * @return array<string,int>
     */
    private function mapearColumnas(array $encabezado): array
    {
        $mapa = [];

        foreach ($encabezado as $indice => $titulo) {
            // El BOM viaja pegado al primer encabezado y lo vuelve irreconocible.
            $clave = $this->clave(str_replace("\u{FEFF}", '', (string) $titulo));

            if ($clave !== '' && array_key_exists($clave, self::COLUMNAS)) {
                $mapa[$clave] = $indice;
            }
        }

        return $mapa;
    }

    /** Sin acentos, sin mayúsculas y con guion bajo: «Día de corte» y «dia_corte». */
    private function clave(string $texto): string
    {
        $sinAcentos = Str::ascii(trim($texto));

        return Str::of($sinAcentos)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
    }

    /** @param array<int,string|null> $fila */
    private function estaVacia(array $fila): bool
    {
        foreach ($fila as $celda) {
            if (trim((string) $celda) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,int> */
    private function sedesPorClave(): array
    {
        $sedes = [];

        foreach (Branch::get(['id', 'name', 'prefix']) as $sede) {
            $sedes[$this->clave((string) $sede->name)] = $sede->id;

            if ($sede->prefix) {
                $sedes[$this->clave((string) $sede->prefix)] = $sede->id;
            }
        }

        return $sedes;
    }

    /**
     * El tipo deducido de la cédula, cuando la columna vino vacía.
     *
     * Nueve dígitos es una persona física y las que empiezan por 3 con diez
     * dígitos son jurídicas. No es infalible —por eso la columna existe—, pero
     * acierta en la enorme mayoría y evita dejar el campo en blanco.
     */
    private function tipoSegunCedula(string $cedula): string
    {
        return match (true) {
            strlen($cedula) === 9                              => '01',
            strlen($cedula) === 10 && str_starts_with($cedula, '3') => '02',
            strlen($cedula) >= 11                              => '03',
            default                                            => '01',
        };
    }
}
