<?php

namespace App\Console\Commands;

use App\Fleet\FleetAccess;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

#[Signature('fleet:set-password {email}')]
#[Description('Set a Fleet user password through a hidden local terminal prompt')]
class FleetSetPassword extends Command
{
    public function handle(FleetAccess $access): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error('No existe una cuenta con ese correo.');

            return self::FAILURE;
        }

        $password = $this->secret('Nueva contraseña (mínimo 12 caracteres)');
        $confirmation = $this->secret('Confirmar contraseña');
        if ($password === null || Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'min:12', 'confirmed']],
        )->fails()) {
            $this->error('Las contraseñas no coinciden o no cumplen el mínimo de 12 caracteres.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($access, $user, $password): void {
            $user->update(['password' => $password]);
            $access->audit(null, null, $user->id, 'local_password_set', null, ['password_changed' => true]);
        });

        $this->info('Contraseña actualizada. Ya puedes ingresar en localhost.');

        return self::SUCCESS;
    }
}
