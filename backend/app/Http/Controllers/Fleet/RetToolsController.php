<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Fleet\RetNotificationService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\FixedPickup;
use App\Models\RetFeasibility;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RetToolsController extends Controller
{
    public function settings(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $settings = DB::table('ret_settings')->where('tenant_id', $tenant->id)->first();
        $points = ClientBranch::with(['client', 'contacts'])->whereHas('client', fn ($q) => $q->where('tenant_id', $tenant->id))->orderBy('client_id')->get();
        $clients = Client::where('tenant_id', $tenant->id)->get();
        $types = ServiceType::where('is_active', true)->get();
        $configured = DB::table('client_service_type')->whereIn('client_id', $clients->modelKeys())->where('is_active', true)->get()->groupBy('client_id');
        $vehicles = Vehicle::where('tenant_id', $tenant->id)->orderBy('plate')->get();
        $licenses = DB::table('ret_license_types')->get();

        return view('fleet::coordination.settings', compact('settings', 'points', 'clients', 'types', 'configured', 'vehicles', 'licenses'));
    }

    public function saveVehicle(Request $request, Vehicle $vehicle, FleetAccess $access): RedirectResponse
    {
        abort_unless($vehicle->tenant_id === $access->tenant($request)->id, 404);
        $data = $request->validate([
            'max_weight_kg' => ['required', 'integer', 'min:1', 'max:100000'],
            'payload_capacity_kg' => ['nullable', 'integer', 'min:1', 'lt:max_weight_kg'],
            'capacity_m3' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
        ]);
        $vehicle->update($data);

        return back()->with('status', 'Peso bruto y capacidades del vehículo actualizados.');
    }

    public function saveSettings(Request $request, FleetAccess $access, RetNotificationService $mail): RedirectResponse
    {
        $data = $request->validate(['sender' => ['required', 'email', 'max:255'], 'postventa_cc' => ['required', 'string', 'max:2000']]);
        if ($mail->emails($data['postventa_cc']) === []) {
            throw ValidationException::withMessages(['postventa_cc' => 'Indica correos válidos separados por coma o punto y coma.']);
        }
        DB::table('ret_settings')->updateOrInsert(['tenant_id' => $access->tenant($request)->id], $data + ['updated_at' => now(), 'created_at' => now()]);

        return back()->with('status', 'Remitente y copia a Postventa guardados.');
    }

    public function savePoint(Request $request, ClientBranch $branch, FleetAccess $access, RetNotificationService $mail): RedirectResponse
    {
        abort_unless($branch->client->tenant_id === $access->tenant($request)->id, 404);
        $data = $request->validate(['address' => ['required', 'string', 'max:255'], 'commune_name' => ['required', 'string', 'max:100'], 'operational_emails' => ['nullable', 'string', 'max:2000'], 'location_reference' => ['nullable', 'string', 'max:2000'], 'postventa_cc' => ['nullable', 'string', 'max:2000'], 'contact_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'contact_email' => ['nullable', 'email', 'max:255']]);
        if (! empty($data['operational_emails']) && $mail->emails($data['operational_emails']) === []) {
            throw ValidationException::withMessages(['operational_emails' => 'Revisa los correos del punto.']);
        }
        if (! empty($data['postventa_cc']) && $mail->emails($data['postventa_cc']) === []) {
            throw ValidationException::withMessages(['postventa_cc' => 'Revisa los correos de Postventa.']);
        }
        DB::transaction(function () use ($branch, $data): void {
            $branch->update(collect($data)->only(['address', 'commune_name', 'operational_emails', 'location_reference', 'postventa_cc'])->all() + ['address_status' => 'ready', 'operational_email_pending' => empty($data['operational_emails'])]);
            if (! empty($data['contact_name'])) {
                $contact = $branch->contacts()->where('is_primary', true)->first();
                $values = ['name' => $data['contact_name'], 'phone' => $data['phone'] ?? null, 'email' => $data['contact_email'] ?? null, 'is_active' => true, 'is_primary' => true];
                if ($contact) {
                    $contact->update($values);
                } else {
                    $branch->contacts()->create($values);
                }
            }
            FixedPickup::where('client_branch_id', $branch->id)->update(['operational_emails' => $branch->operational_emails, 'operational_email_pending' => $branch->operational_email_pending]);
        });

        return back()->with('status', 'Punto y contacto actualizados; los retiros anteriores conservan sus datos históricos.');
    }

    public function clientServices(Request $request, Client $client, FleetAccess $access): RedirectResponse
    {
        abort_unless($client->tenant_id === $access->tenant($request)->id, 404);
        $request->merge(['services' => $request->input('services', [])]);
        $data = $request->validate(['services' => ['present', 'array'], 'services.*' => ['integer', Rule::exists('service_types', 'id')->where('is_active', true)]]);
        DB::transaction(function () use ($client, $data): void {
            DB::table('client_service_type')->where('client_id', $client->id)->update(['is_active' => false, 'updated_at' => now()]);
            foreach ($data['services'] as $id) {
                DB::table('client_service_type')->updateOrInsert(['client_id' => $client->id, 'service_type_id' => $id], ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        if ($request->boolean('return_to_request')) {
            return redirect()->route('fleet.merchants.show', ['client' => $client, 'return_to_request' => 1])
                ->with('status', 'Servicios del cliente guardados.')->with('ret_services_saved', true);
        }

        return back()->with('status', 'Servicios del cliente guardados.');
    }

    public function cleanup(Request $request, FleetAccess $access): View
    {
        $this->guardCleanup($request, $access);
        $ids = $this->testIds($access->tenant($request)->id);
        $counts = $this->counts($ids);
        $request->session()->put('ret_cleanup_preview', ['ids' => $ids, 'counts' => $counts]);

        return view('fleet::coordination.cleanup', compact('counts'));
    }

    public function destroy(Request $request, FleetAccess $access): RedirectResponse
    {
        $this->guardCleanup($request, $access);
        $request->validate(['confirmation' => ['required', 'in:LIMPIAR DATOS DE PRUEBA']]);
        $preview = $request->session()->get('ret_cleanup_preview');
        abort_unless(is_array($preview), 409, 'Primero revisa la vista previa.');
        DB::transaction(function () use ($request, $access, $preview): void {
            $tenant = $access->tenant($request);
            RetFeasibility::where('tenant_id', $tenant->id)->where('is_test', true)->orderBy('id')->lockForUpdate()->get();
            FixedPickup::where('tenant_id', $tenant->id)->where('is_test', true)->orderBy('id')->lockForUpdate()->get();
            ServiceRequest::where('tenant_id', $tenant->id)->where('is_test', true)->orderBy('id')->lockForUpdate()->get();
            $ids = $this->testIds($tenant->id);
            $counts = $this->counts($ids);
            abort_unless($ids === ($preview['ids'] ?? null) && $counts === ($preview['counts'] ?? null), 409, 'Los datos cambiaron. Actualiza la vista previa antes de limpiar.');
            DB::table('ret_cleanup_audits')->insert(['tenant_id' => $tenant->id, 'actor_user_id' => $request->user()->id, 'counts' => json_encode($counts), 'record_ids' => json_encode($ids), 'created_at' => now()]);
            RetFeasibility::whereIn('id', $ids['feasibilities'])->where('is_test', true)->delete();
            ServiceRequest::whereIn('id', $ids['requests'])->where('is_test', true)->delete();
            FixedPickup::whereIn('id', $ids['fixed'])->where('is_test', true)->delete();
        }, 3);
        $request->session()->forget('ret_cleanup_preview');

        return redirect()->route('fleet.page.coordination-requests')->with('status', 'Datos de prueba eliminados; maestros y registros reales conservados.');
    }

    private function testIds(int $tenant): array
    {
        $requests = ServiceRequest::where('tenant_id', $tenant)->where('is_test', true)->whereNotIn('id', RetFeasibility::where('is_test', false)->whereNotNull('service_request_id')->select('service_request_id'))->orderBy('id')->pluck('id')->all();
        $feasibilities = RetFeasibility::where('tenant_id', $tenant)->where('is_test', true)->where(fn ($q) => $q->whereNull('service_request_id')->orWhereIn('service_request_id', $requests))->orderBy('id')->pluck('id')->all();
        $fixed = FixedPickup::where('tenant_id', $tenant)->where('is_test', true)->whereDoesntHave('requests', fn ($q) => $q->whereNotIn('id', $requests))->orderBy('id')->pluck('id')->all();

        return compact('requests', 'feasibilities', 'fixed');
    }

    private function counts(array $ids): array
    {
        $counts = ['Solicitudes' => count($ids['requests']), 'Factibilidad' => count($ids['feasibilities']), 'Reglas de prueba' => count($ids['fixed'])];
        foreach (['service_request_events' => 'Eventos de solicitudes', 'service_request_reprogrammings' => 'Reprogramaciones', 'service_request_overload_authorizations' => 'Autorizaciones', 'ret_notifications' => 'Correos capturados'] as $table => $label) {
            $counts[$label] = DB::table($table)->whereIn('service_request_id', $ids['requests'])->count();
        }
        $counts['Eventos de factibilidad'] = DB::table('ret_feasibility_events')->whereIn('ret_feasibility_id', $ids['feasibilities'])->count();

        return $counts;
    }

    private function guardCleanup(Request $request, FleetAccess $access): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('fleet.test_tools_enabled'), 404);
        abort_unless(in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true), 404);
        abort_unless(DB::table('tenant_users')->where('tenant_id', $access->tenant($request)->id)->where('user_id', $request->user()->id)->where('is_active', true)->where('role_code', 'administrator')->exists(), 403);
        abort_unless($access->allows($request->user(), $access->tenant($request), 'admin.permissions', 3), 403);
    }

    public function retry(Request $request, int $notification, FleetAccess $access, RetNotificationService $mail): RedirectResponse
    {
        $row = DB::table('ret_notifications')->find($notification);
        abort_unless($row && ServiceRequest::where('id', $row->service_request_id)->where('tenant_id', $access->tenant($request)->id)->exists(), 404);
        $mail->capture($notification);

        return back()->with('status', 'Estado de captura actualizado; no se enviaron correos a clientes.');
    }
}
