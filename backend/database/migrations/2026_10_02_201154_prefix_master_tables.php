<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'users' => 'MBA_users',
        'providers' => 'MBA_providers',
        'clients' => 'MBA_clients',
        'tenants' => 'MBA_tenants',
        'tenant_users' => 'MBA_tenant_users',
        'PPR_vehicles' => 'MBA_vehicles',
    ];

    public function up(): void
    {
        $this->rename(false);
    }

    public function down(): void
    {
        $this->rename(true);
    }

    private function rename(bool $reverse): void
    {
        $pairs = [];
        foreach (self::TABLES as $source => $target) {
            $pairs[] = $reverse ? [$target, $source] : [$source, $target];
        }

        foreach ($pairs as [$source, $target]) {
            if (! Schema::hasTable($source) || Schema::hasTable($target)) {
                throw new RuntimeException("No se puede renombrar {$source} a {$target}: falta la tabla de origen o ya existe la tabla de destino.");
            }
        }

        if (DB::getDriverName() === 'mysql') {
            $renames = array_map(
                fn (array $pair): string => sprintf('`%s` TO `%s`', $pair[0], $pair[1]),
                $pairs,
            );
            DB::statement('RENAME TABLE '.implode(', ', $renames));

            return;
        }

        foreach ($pairs as [$source, $target]) {
            Schema::rename($source, $target);
        }
    }
};
