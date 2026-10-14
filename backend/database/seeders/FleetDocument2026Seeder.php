<?php

namespace Database\Seeders;

use App\Fleet\VehicleDocumentImporter;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class FleetDocument2026Seeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Carga documental habilitada exclusivamente en localhost y pruebas.');
        }
        $records = json_decode(file_get_contents(database_path('data/fleet_documents_2026.json')), true, 512, JSON_THROW_ON_ERROR);
        $actor = User::where('name', 'Hans de la Barra')->firstOrFail();
        app(VehicleDocumentImporter::class)->import($records, $actor);
    }
}
