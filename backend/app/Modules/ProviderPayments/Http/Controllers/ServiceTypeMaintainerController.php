<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceTypeMaintainerController
{
    public function index(): View
    {
        return view('provider-payments::service-types-index', [
            'services' => ServiceType::query()->orderBy('service_code')->get(),
            'nextCode' => ((int) ServiceType::query()->max('service_code')) + 1,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
        ]);
        $name = trim($validated['name']);

        if (ServiceType::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => 'Este servicio ya existe.']);
        }

        $service = DB::transaction(function () use ($name): ServiceType {
            return ServiceType::create([
                'service_code' => ((int) ServiceType::query()->max('service_code')) + 1,
                'name' => $name,
                'is_active' => true,
            ]);
        });

        return redirect()->route('provider-payments.maintainers.servicios')
            ->with('status', "Servicio {$service->name} creado con ID {$service->service_code}.");
    }
}
