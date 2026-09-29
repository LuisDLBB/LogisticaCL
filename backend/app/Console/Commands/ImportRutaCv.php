<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\RutaCvManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('ruta-cv:import {file}')]
#[Description('Carga una planilla Ruta CV en su período de origen')]
class ImportRutaCv extends Command
{
    public function handle(RutaCvManager $manager): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error('No se encontró el archivo indicado.');

            return self::FAILURE;
        }
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        try {
            $result = $manager->import($file, $tenant->id);
        } catch (ValidationException $exception) {
            $this->error($exception->errors()['file'][0] ?? $exception->getMessage());

            return self::FAILURE;
        }
        $this->info("{$result['imported']} rutas cargadas en {$result['periodo']}; {$result['unmatched']} proveedores requieren revisión.");

        return self::SUCCESS;
    }
}
