<?php

namespace App\Console\Commands;

use App\Fleet\FleetAccess;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('fleet:bootstrap-admin {email} {--name=}')]
#[Description('Create the first local Fleet administrator without displaying a password')]
class FleetBootstrapAdmin extends Command
{
    public function handle(FleetAccess $access): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name'));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('Ingresa un correo válido.');

            return self::FAILURE;
        }
        if (DB::table('tenant_users')->where('role_code', 'administrator')->where('is_active', true)->exists()) {
            $this->error('Ya existe un administrador activo. Usa la pantalla de Administración.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null && $name === '') {
            $this->error('Indica --name para crear una cuenta nueva.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($access, $email, $name, &$user): void {
            if ($user === null) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Str::password(64),
                ]);
                $access->audit(null, null, $user->id, 'bootstrap_user_created', null, ['email' => $email]);
            }
            foreach (Tenant::query()->whereIn('code', ['4N', 'PMCB'])->get() as $tenant) {
                DB::table('tenant_users')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                    ['role_code' => 'administrator', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
                );
                $access->audit(null, $tenant, $user->id, 'bootstrap_admin_associated', null, ['role_code' => 'administrator', 'is_active' => true]);
            }
        });

        $this->info('Administrador local creado/asociado en 4N y PMCB.');
        $this->warn('La contraseña inicial aleatoria no se muestra. Ejecuta fleet:set-password desde una terminal local interactiva.');

        return self::SUCCESS;
    }
}
