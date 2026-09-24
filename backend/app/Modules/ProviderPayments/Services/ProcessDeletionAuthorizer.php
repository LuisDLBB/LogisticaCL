<?php

namespace App\Modules\ProviderPayments\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProcessDeletionAuthorizer
{
    public function authorize(Request $request): void
    {
        $password = $request->validate(['password' => ['required', 'string', 'max:255']])['password'];
        $configuredPassword = config('provider-payments.process_deletion_key');

        if (! is_string($configuredPassword) || $configuredPassword === '' || ! hash_equals($configuredPassword, $password)) {
            throw ValidationException::withMessages(['password' => 'Clave maestra incorrecta. No se eliminó ningún registro.']);
        }
    }
}
