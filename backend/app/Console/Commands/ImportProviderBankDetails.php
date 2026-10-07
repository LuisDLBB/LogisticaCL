<?php

namespace App\Console\Commands;

use App\Models\Banco;
use App\Models\Provider;
use App\Models\ProviderBankAccount;
use App\Models\Tenant;
use App\Models\TipoCuentaBancaria;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

#[Signature('providers:import-bank-details {file} {--dry-run}')]
#[Description('Actualiza correos y cuentas bancarias de proveedores existentes por RUT')]
class ImportProviderBankDetails extends Command
{
    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error('No se encontró la planilla indicada.');

            return self::FAILURE;
        }

        try {
            $rows = IOFactory::load($file)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable $exception) {
            $this->error('No se pudo leer la planilla: '.$exception->getMessage());

            return self::FAILURE;
        }

        $expectedHeaders = ['Rut_Proveedor', 'Razon_Social', 'Titular_Banco', 'RUT_Titular_Banco', 'Banco', 'Tipo_Cuenta', 'Nro_Cuenta', 'CorreoContacto', 'CorreoContacto2'];
        $headers = array_map(fn ($value) => trim((string) $value), array_slice($rows[0] ?? [], 0, count($expectedHeaders)));
        if ($headers !== $expectedHeaders) {
            $this->error('Las columnas de la planilla no corresponden al formato esperado.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $providers = Provider::query()->with('bankAccounts')->where('tenant_id', $tenant->id)->get()
            ->groupBy(fn (Provider $provider) => $this->normaliseRut($provider->tax_id));
        $bankNames = array_flip(Banco::query()->where('is_active', true)->pluck('banco')->all());
        $accountTypes = array_flip(TipoCuentaBancaria::query()->where('is_active', true)->pluck('tipo_cuenta')->all());
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $unchanged = 0;
        $excluded = 0;
        $missingPrimaryEmail = 0;
        $secondEmails = 0;
        $pending = [];
        $seen = [];

        try {
            DB::transaction(function () use ($rows, $providers, $bankNames, $accountTypes, $dryRun, &$updated, &$unchanged, &$excluded, &$missingPrimaryEmail, &$secondEmails, &$pending, &$seen): void {
                foreach (array_slice($rows, 1) as $index => $row) {
                    $line = $index + 2;
                    $rut = $this->normaliseRut($row[0] ?? null);
                    if (in_array($rut, ['', 'NA', '00', '01', '02'], true)) {
                        $excluded++;

                        continue;
                    }
                    if (isset($seen[$rut])) {
                        $pending[] = "Fila {$line}: RUT duplicado en la planilla.";

                        continue;
                    }
                    $seen[$rut] = true;
                    $matches = $providers->get($rut);
                    if (! $matches || $matches->count() !== 1) {
                        $pending[] = "Fila {$line}: proveedor {$row[0]} sin coincidencia única por RUT.";

                        continue;
                    }

                    $bankName = match (trim((string) ($row[4] ?? ''))) {
                        'Copeuch' => 'Coopeuch',
                        'Banco Ripley' => 'Ripley',
                        default => trim((string) ($row[4] ?? '')),
                    };
                    $accountType = trim((string) ($row[5] ?? ''));
                    $number = $this->accountNumber($row[6] ?? null);
                    $holder = $this->cleanText($row[2] ?? null);
                    $holderRut = $this->normaliseRut($row[3] ?? null);
                    if ($bankName === '' || $accountType === '' || $number === '' || $holder === '' || $holderRut === '') {
                        $pending[] = "Fila {$line}: {$row[0]} sin datos bancarios completos.";

                        continue;
                    }
                    if (! isset($bankNames[$bankName]) || ! isset($accountTypes[$accountType]) || ! preg_match('/^[0-9]+$/', $number)) {
                        $pending[] = "Fila {$line}: {$row[0]} tiene banco, tipo o número de cuenta inválido.";

                        continue;
                    }

                    $primaryEmail = $this->email($row[7] ?? null);
                    $secondaryEmail = $this->email($row[8] ?? null);
                    if ($primaryEmail === false || $secondaryEmail === false) {
                        $pending[] = "Fila {$line}: {$row[0]} tiene un correo inválido.";

                        continue;
                    }
                    $missingPrimaryEmail += (int) ($primaryEmail === null);
                    $secondEmails += (int) ($secondaryEmail !== null);

                    /** @var Provider $provider */
                    $provider = $matches->first();
                    $providerChanges = [];
                    if ($primaryEmail !== null && $provider->contact_email !== $primaryEmail) {
                        $providerChanges['contact_email'] = $primaryEmail;
                    }
                    if ($secondaryEmail !== null && $provider->contact_email_secondary !== $secondaryEmail) {
                        $providerChanges['contact_email_secondary'] = $secondaryEmail;
                    }

                    $account = $provider->bankAccounts->sortByDesc('is_primary')->first();
                    $desiredAccount = [
                        'account_holder_name' => $holder,
                        'account_holder_tax_id' => substr($holderRut, 0, -1).'-'.substr($holderRut, -1),
                        'bank_name' => $bankName,
                        'account_type' => $accountType,
                        'account_number' => $number,
                        'is_primary' => true,
                        'is_active' => true,
                    ];
                    $accountChanges = [];
                    foreach ($desiredAccount as $field => $value) {
                        $currentValue = $field === 'account_number' && $account
                            ? $this->currentAccountNumber($account)
                            : ($account?->{$field});
                        if (! $account || (string) $currentValue !== (string) $value || ($field === 'account_number' && $this->accountNeedsEncryption($account))) {
                            $accountChanges[$field] = $value;
                        }
                    }
                    if ($providerChanges === [] && $accountChanges === []) {
                        $unchanged++;

                        continue;
                    }
                    $updated++;
                    if ($dryRun) {
                        continue;
                    }
                    if ($providerChanges !== []) {
                        $provider->update($providerChanges);
                    }
                    if ($account) {
                        if (isset($accountChanges['account_number'])) {
                            $accountChanges['account_number'] = Crypt::encryptString($accountChanges['account_number']);
                        }
                        DB::table('PPR_provider_bank_accounts')->where('id', $account->id)->update([...$accountChanges, 'updated_at' => now()]);
                    } else {
                        $provider->bankAccounts()->create($desiredAccount);
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->error('La actualización se revirtió: '.$exception->getMessage());

            return self::FAILURE;
        }

        $action = $dryRun ? 'Se actualizarían' : 'Se actualizaron';
        $this->info("{$action} {$updated} proveedores; {$unchanged} ya estaban al día; ".count($pending)." pendientes; {$excluded} filas auxiliares excluidas.");
        $this->line("{$missingPrimaryEmail} proveedores con cuenta no traen correo principal; {$secondEmails} traen segundo correo.");
        foreach ($pending as $issue) {
            $this->warn($issue);
        }

        return self::SUCCESS;
    }

    private function normaliseRut(mixed $value): string
    {
        return preg_replace('/[^0-9K]/', '', strtoupper((string) $value)) ?? '';
    }

    private function cleanText(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function accountNumber(mixed $value): string
    {
        return is_numeric($value) ? sprintf('%.0f', (float) $value) : trim((string) $value);
    }

    private function currentAccountNumber(ProviderBankAccount $account): string
    {
        try {
            return (string) $account->account_number;
        } catch (Throwable) {
            return (string) $account->getRawOriginal('account_number');
        }
    }

    private function accountNeedsEncryption(ProviderBankAccount $account): bool
    {
        try {
            Crypt::decryptString((string) $account->getRawOriginal('account_number'));

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    private function email(mixed $value): string|false|null
    {
        $email = strtolower(trim((string) $value));
        if ($email === '' || $email === 'n/a') {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }
}
