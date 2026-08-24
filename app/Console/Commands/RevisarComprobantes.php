<?php

namespace App\Console\Commands;

use App\Models\ElectronicInvoice;
use App\Services\Hacienda\PdfGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diagnostica y repara los archivos de los comprobantes electrónicos.
 *
 * El PDF y el XML viven en storage/app/hacienda, que no viaja con el código:
 * al mover el sitio de hosting quedan atrás y las descargas dan 404 sin decir
 * por qué. El PDF se puede rehacer desde el XML firmado; el XML no se puede
 * rehacer desde nada, así que conviene saber cuáles están completos y cuáles no.
 *
 *   php artisan hacienda:revisar             solo informa
 *   php artisan hacienda:revisar --reparar   regenera los PDF que falten
 */
class RevisarComprobantes extends Command
{
    protected $signature = 'hacienda:revisar
        {--reparar : Regenera los PDF que falten y se puedan rehacer.}';

    protected $description = 'Revisa qué comprobantes tienen su PDF y su XML en disco';

    public function handle(PdfGenerator $generador): int
    {
        $disco = Storage::disk(config('hacienda.disk'));

        $this->components->twoColumnDetail('Disco', config('hacienda.disk'));
        $this->components->twoColumnDetail('Ruta', config('filesystems.disks.' . config('hacienda.disk') . '.root'));

        $comprobantes = ElectronicInvoice::orderBy('id')->get();

        if ($comprobantes->isEmpty()) {
            $this->components->info('No hay comprobantes registrados.');

            return self::SUCCESS;
        }

        $filas = [];
        $reparables = [];
        $perdidos = 0;

        foreach ($comprobantes as $c) {
            $tienePdf = $c->pdf_path && $disco->exists($c->pdf_path);
            $tieneXml = $c->signed_xml_path && $disco->exists($c->signed_xml_path);

            $situacion = match (true) {
                $tienePdf            => '<fg=green>completo</>',
                $tieneXml            => '<fg=yellow>falta el PDF (se puede rehacer)</>',
                (bool) $c->pdf_path  => '<fg=red>los archivos no están</>',
                default              => '<fg=gray>sin transmitir</>',
            };

            if (! $tienePdf && $tieneXml) {
                $reparables[] = $c;
            }

            if (! $tienePdf && ! $tieneXml && $c->pdf_path) {
                $perdidos++;
            }

            $filas[] = [$c->id, $c->consecutivo, $c->statusLabel(), $situacion];
        }

        $this->newLine();
        $this->table(['#', 'Consecutivo', 'Estado', 'Archivos'], $filas);

        if ($perdidos > 0) {
            $this->components->error("{$perdidos} comprobante(s) sin PDF ni XML: no se pueden rehacer.");
            $this->components->bulletList([
                'Sus archivos quedaron en el servidor anterior. Si tenés respaldo, copiá storage/app/hacienda.',
                'Hacienda conserva el comprobante: el sistema no lo pierde, solo la copia local.',
            ]);
        }

        if (! $reparables) {
            $this->components->info('No hay PDF que regenerar.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->warn(count($reparables) . ' PDF se pueden regenerar desde su XML firmado.');

        if (! $this->option('reparar')) {
            $this->components->info('Volvé a correrlo con --reparar para rehacerlos.');

            return self::SUCCESS;
        }

        foreach ($reparables as $c) {
            $this->components->task("Regenerando {$c->consecutivo}", function () use ($generador, $c) {
                try {
                    $generador->generate($c);

                    return true;
                } catch (Throwable $e) {
                    $this->newLine();
                    $this->components->error($e->getMessage());

                    return false;
                }
            });
        }

        return self::SUCCESS;
    }
}
