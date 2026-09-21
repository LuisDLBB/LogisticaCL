<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('billing_company_code', 20)->nullable()->after('tenant_id');
        });

        $tenantId = DB::table('tenants')->where('code', '4N')->value('id');
        $path = database_path('data/initial_clients.tsv');

        if (! $tenantId || ! is_file($path)) {
            return;
        }

        $rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($rows);

        foreach ($rows as $row) {
            $columns = str_getcsv($row, "\t");
            if (count($columns) < 8) {
                continue;
            }

            [$billingCompany, $taxId, $taxIdNumber, $merchant, $legalName, $address, $commune, $activity] = $columns;
            $checkDigit = strtoupper(substr($taxId, strrpos($taxId, '-') + 1));

            DB::table('clients')->updateOrInsert(
                ['tenant_id' => $tenantId, 'tax_id_number' => $taxIdNumber],
                [
                    'billing_company_code' => $billingCompany,
                    'tax_id' => strtoupper($taxId),
                    'tax_id_check_digit' => $checkDigit,
                    'source_merchant_name' => $merchant,
                    'commercial_name' => $merchant,
                    'legal_name' => $legalName,
                    'billing_address' => $address,
                    'billing_commune_name' => $commune,
                    'business_activity' => $activity,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        $tenantId = DB::table('tenants')->where('code', '4N')->value('id');
        if ($tenantId) {
            DB::table('clients')->where('tenant_id', $tenantId)->delete();
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('billing_company_code');
        });
    }
};
