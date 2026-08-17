<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateDeployToken extends Command
{
    protected $signature = 'deploy:token';
    protected $description = 'Genera un token para habilitar los endpoints de mantenimiento /__deploy/*';

    public function handle(): int
    {
        $token = Str::random(64);

        $this->newLine();
        $this->info('Agregá esta línea al .env del servidor:');
        $this->newLine();
        $this->line("DEPLOY_TOKEN={$token}");
        $this->newLine();
        $this->warn('Quien tenga este token puede correr migraciones y limpiar cachés de la aplicación.');
        $this->line('Después de guardarlo: php artisan config:clear');

        return self::SUCCESS;
    }
}
