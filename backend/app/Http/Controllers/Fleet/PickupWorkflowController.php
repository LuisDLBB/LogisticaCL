<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\CoordinationService;
use App\Fleet\FleetAccess;
use App\Fleet\LatePickupAuthorization;
use App\Fleet\MicrosoftRetMail;
use App\Fleet\PickupCustomerService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\ServiceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PickupWorkflowController extends Controller
{
    public function point(Request $request, int $client, FleetAccess $access): JsonResponse
    {
        $selected = Client::where('tenant_id', $access->tenant($request)->id)->where('is_active', true)->findOrFail($client);
        $data = $request->validate(['point_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:255'], 'address' => ['required', 'string', 'max:255'], 'commune' => ['required', 'string', 'max:100']]);
        $point = DB::transaction(function () use ($data, $selected) {
            if (! empty($data['point_id'])) {
                $point = ClientBranch::where('client_id', $selected->id)->where('is_active', true)->lockForUpdate()->findOrFail($data['point_id']);
                abort_unless($point->address_status !== 'ready', 409, 'Este punto ya tiene dirección. Actualízalo desde la ficha del comerciante.');
                $point->update(['address' => $data['address'], 'commune_name' => $data['commune'], 'address_status' => 'ready']);
            } else {
                $point = ClientBranch::create(['client_id' => $selected->id, 'code' => 'RET-'.strtoupper(bin2hex(random_bytes(5))), 'name' => $data['name'], 'address' => $data['address'], 'commune_name' => $data['commune'], 'address_status' => 'ready', 'is_active' => true]);
            }

            return $point;
        });

        return response()->json(['id' => $point->id, 'name' => $point->name, 'address' => $point->address, 'commune' => $point->commune_name]);
    }

    private function authorizeRet(Request $request, ServiceRequest $ret, FleetAccess $access, string $permission, int $level = 2): void
    {
        abort_unless($ret->tenant_id === $access->tenant($request)->id, 404);
        abort_unless($access->allows($request->user(), $access->tenant($request), $permission, $level), 403);
    }

    public function reschedule(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeRet($request, $serviceRequest, $access, 'operations.scheduling');
        $data = $request->validate(['reason_code' => ['required', Rule::in(array_keys(PickupCustomerService::REASONS))], 'reason_detail' => ['required_if:reason_code,other', 'nullable', 'string', 'max:2000'], 'proposed_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('America/Santiago')->toDateString()], 'proposed_start' => ['required', 'date_format:H:i'], 'proposed_end' => ['required', 'date_format:H:i', 'after:proposed_start']]);
        DB::transaction(function () use ($serviceRequest, $request, $data, $service): void {
            $ret = ServiceRequest::lockForUpdate()->findOrFail($serviceRequest->id);
            abort_unless(in_array($ret->status, ['requested', 'correction_requested', 'scheduled', 'rescheduled'], true), 409);
            abort_if(DB::table('ret_coordination_tasks')->where('service_request_id', $ret->id)->where('status', 'pending')->exists() || $ret->reprogrammings()->where('status', 'pending')->exists(), 409, 'Ya existe una coordinación pendiente.');
            $reason = PickupCustomerService::REASONS[$data['reason_code']].(filled($data['reason_detail'] ?? null) ? ': '.$data['reason_detail'] : '');
            $id = DB::table('ret_coordination_tasks')->insertGetId(['service_request_id' => $ret->id, 'assigned_to_user_id' => $ret->requested_by_user_id, 'requested_by_user_id' => $request->user()->id, 'kind' => 'reschedule', 'previous_status' => $ret->status, 'reason_code' => $data['reason_code'], 'reason' => $reason, 'proposed_date' => $data['proposed_date'], 'proposed_start' => $data['proposed_start'], 'proposed_end' => $data['proposed_end'], 'created_at' => now(), 'updated_at' => now()]);
            $ret->update(['status' => 'reprogramming_pending']);
            $service->event($ret, $request->user(), 'requester_reschedule_requested', ['task_id' => $id, 'reason' => $reason, 'assigned_to_user_id' => $ret->requested_by_user_id, 'proposed_date' => $data['proposed_date']]);
            DB::afterCommit(fn () => $this->notifyRequester($ret, $reason));
        });

        return back()->with('status', 'Reagendamiento solicitado a quien ingresó el retiro. La fecha y el horario vigentes se conservan mientras coordina con el cliente.');
    }

    private function notifyRequester(ServiceRequest $ret, string $reason): void
    {
        $email = $ret->requester?->email;
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($email), '.local')) {
            return;
        }
        $html = '<p>Coordina con el cliente el retiro <strong>'.e($ret->ret_code).'</strong>.</p><p>'.e($reason).'</p><p><a href="'.e(route('fleet.requests.show', ['serviceRequest' => $ret, 'vista' => 'solicitante'])).'">Abrir tarea de coordinación</a></p>';
        try {
            $mail = app(MicrosoftRetMail::class);
            if (! app()->environment('testing') && ! $ret->is_test && ($mail->settings($ret->tenant_id)['automatic_receipts'] ?? false)) {
                $mail->sendCustomer($ret->tenant_id, $ret->ret_code.' | Coordinar reagendamiento', $html, [$email]);
            } else {
                Mail::mailer('log')->html($html, fn ($message) => $message->from(MicrosoftRetMail::SENDER)->to($email)->subject($ret->ret_code.' | Coordinar reagendamiento'));
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function resolve(Request $request, ServiceRequest $serviceRequest, int $task, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeRet($request, $serviceRequest, $access, 'coordination.requests');
        $taskRow = DB::table('ret_coordination_tasks')->where('service_request_id', $serviceRequest->id)->where('id', $task)->first();
        abort_unless($taskRow, 404);
        $needsSchedule = $taskRow->kind === 'reschedule';
        $data = $request->validate(['decision' => ['required', 'in:confirmed,rejected'], 'customer_response' => ['required', 'string', 'max:2000'], 'service_date' => [Rule::excludeIf(! $needsSchedule || $request->input('decision') !== 'confirmed'), Rule::requiredIf($needsSchedule && $request->input('decision') === 'confirmed'), 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.now('America/Santiago')->toDateString()], 'window_start' => [Rule::excludeIf(! $needsSchedule || $request->input('decision') !== 'confirmed'), Rule::requiredIf($needsSchedule && $request->input('decision') === 'confirmed'), 'nullable', 'date_format:H:i'], 'window_end' => [Rule::excludeIf(! $needsSchedule || $request->input('decision') !== 'confirmed'), Rule::requiredIf($needsSchedule && $request->input('decision') === 'confirmed'), 'nullable', 'date_format:H:i', 'after:window_start']]);
        DB::transaction(function () use ($serviceRequest, $task, $request, $access, $data, $service): void {
            $ret = ServiceRequest::lockForUpdate()->findOrFail($serviceRequest->id);
            $row = DB::table('ret_coordination_tasks')->where('service_request_id', $ret->id)->where('id', $task)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'pending', 409);
            abort_unless($row->assigned_to_user_id === $request->user()->id || $access->allows($request->user(), $access->tenant($request), 'admin.permissions', 3), 403);
            abort_if(in_array($ret->status, ['cancelled', 'retired'], true), 409);
            if ($data['decision'] === 'confirmed' && $row->kind === 'reschedule') {
                $ret->update(['service_date' => $data['service_date'], 'window_start' => $data['window_start'], 'window_end' => $data['window_end'], 'shift' => (int) substr($data['window_start'], 0, 2) < 12 ? ((int) substr($data['window_end'], 0, 2) >= 12 ? 'AM/PM' : 'AM') : 'PM', 'status' => 'requested', 'driver_user_id' => null, 'vehicle_id' => null, 'assigned_at' => null, 'assigned_by_user_id' => null, 'scheduled_at' => null, 'email_status' => 'not_prepared', 'email_body' => null, 'email_subject' => null, 'driver_token_hash' => null, 'driver_token_expires_at' => null]);
                DB::table('ret_notifications')->where('service_request_id', $ret->id)->whereIn('status', ['prepared', 'error'])->update(['status' => 'superseded', 'updated_at' => now()]);
                DB::table('service_request_overload_authorizations')->where('service_request_id', $ret->id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'expired', 'updated_at' => now()]);
                $late = app(LatePickupAuthorization::class);
                if ($late->afterCutoff($data['service_date'], $ret->tenant_id)) {
                    $late->request($ret, $request->user(), $data['service_date'], $data['customer_response']);
                }
            } elseif ($row->kind === 'reschedule') {
                $ret->update(['status' => $row->previous_status]);
            }
            DB::table('ret_coordination_tasks')->where('id', $task)->update(['status' => $data['decision'], 'customer_response' => $data['customer_response'], 'resolved_at' => now(), 'updated_at' => now()]);
            $service->event($ret, $request->user(), 'requester_reschedule_resolved', ['reason' => $row->reason, 'customer_response' => $data['customer_response'], 'decision' => $data['decision'], 'proposed_date' => $data['service_date'] ?? null]);
        });

        return back()->with('status', 'Respuesta del cliente registrada. Si confirmó un nuevo horario, Operaciones debe programar nuevamente el retiro.');
    }

    private function link(string $token, bool $lock = false): object
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $query = DB::table('ret_customer_notifications')->where('kind', 'receipt')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->whereNull('responded_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function customerForm(string $token, string $action): Response
    {
        $row = $this->link($token);
        $ret = ServiceRequest::findOrFail($row->service_request_id);
        abort_unless(in_array($action, ['correction', 'cancel'], true) && ($action !== 'cancel' || $ret->fixed_pickup_id), 404);
        abort_if(in_array($ret->status, ['retired', 'cancelled'], true), 410);

        return response()->view('fleet::coordination.customer-action', ['ret' => $ret, 'token' => $token, 'action' => $action, 'correctionDeadline' => CarbonImmutable::parse($row->updated_at)->addMinutes(20), 'correctionExpired' => now()->gte(CarbonImmutable::parse($row->updated_at)->addMinutes(20))])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function customerAct(Request $request, string $token, string $action, CoordinationService $service, PickupCustomerService $customer): RedirectResponse
    {
        $isCorrection = $action === 'correction';
        $totalWeightMode = $request->input('weight_mode', 'individual') === 'total';
        $data = $request->validate([
            'confirmed' => ['accepted'],
            'reason' => [$isCorrection ? 'nullable' : 'required', 'string', 'max:2000'],
            'packages' => [Rule::excludeIf(! $isCorrection), 'required', 'integer', 'min:1', 'max:1000'],
            'weight_mode' => [Rule::excludeIf(! $isCorrection), 'nullable', 'in:individual,total'],
            'total_weight_kg' => [Rule::excludeIf(! $isCorrection || ! $totalWeightMode), 'nullable', 'numeric', 'gt:0', 'max:100000000'],
            'package_weights' => [Rule::excludeIf(! $isCorrection || $totalWeightMode), 'nullable', 'array', 'list', 'max:1000'],
            'package_weights.*' => [Rule::excludeIf(! $isCorrection || $totalWeightMode), 'nullable', 'numeric', 'gt:0', 'max:100000'],
        ], [
            'total_weight_kg.required' => 'Ingresa el peso total de todos los bultos en kg.',
            'total_weight_kg.gt' => 'El peso total debe ser mayor que cero.',
            'packages.required' => 'Ingresa la nueva cantidad de bultos.',
            'package_weights.required' => 'Ingresa el peso de cada bulto en kg.',
            'package_weights.*.required' => 'Ingresa el peso de cada bulto en kg.',
            'package_weights.*.gt' => 'El peso de cada bulto debe ser mayor que cero.',
        ]);
        if ($isCorrection && ! $totalWeightMode && filled(array_filter($data['package_weights'] ?? [], fn ($weight) => filled($weight))) && count($data['package_weights']) !== (int) $data['packages']) {
            throw ValidationException::withMessages(['package_weights' => 'Debes informar un peso por cada bulto.']);
        }
        if ($isCorrection) {
            $weights = $totalWeightMode ? null : array_map(fn ($weight) => filled($weight) ? (float) $weight : null, $data['package_weights'] ?? []);
            $completeWeights = $weights !== null && count($weights) === (int) $data['packages'] && ! in_array(null, $weights, true);
            $data['package_weights'] = $weights;
            $data['weight_mode'] = $totalWeightMode ? 'total' : 'individual';
            $data['total_weight_kg'] = $totalWeightMode ? (filled($data['total_weight_kg'] ?? null) ? (float) $data['total_weight_kg'] : null) : ($completeWeights ? array_sum($weights) : null);
            $weightDescription = $weights !== null && array_filter($weights, fn ($weight) => $weight !== null) ? ' Peso por bulto (kg): '.implode(', ', array_map(fn ($weight) => $weight === null ? 'Sin informar' : $weight, $weights)).'.' : '';
            $data['reason'] = 'Nueva cantidad: '.$data['packages'].' bultos.'.$weightDescription.($data['total_weight_kg'] !== null ? ' Peso total: '.$data['total_weight_kg'].' kg.' : ' Peso total: Sin informar.').(filled($data['reason'] ?? null) ? ' Indicaciones: '.$data['reason'] : '');
        }

        DB::transaction(function () use ($token, $action, $data, $service, $customer): void {
            $row = $this->link($token, true);
            $ret = ServiceRequest::lockForUpdate()->findOrFail($row->service_request_id);
            abort_unless(in_array($action, ['correction', 'cancel'], true) && ($action !== 'cancel' || $ret->fixed_pickup_id), 404);
            abort_if(in_array($ret->status, ['retired', 'cancelled'], true), 410);
            if ($action === 'cancel') {
                $ret->update(['status' => 'cancelled', 'cancellation_reason' => 'Cliente sin carga: '.$data['reason'], 'cancelled_at' => now(), 'driver_token_hash' => null, 'driver_token_expires_at' => null]);
                $ret->reprogrammings()->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_notes' => 'Cliente sin carga']);
                DB::table('ret_coordination_tasks')->where('service_request_id', $ret->id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('service_request_overload_authorizations')->where('service_request_id', $ret->id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('ret_notifications')->where('service_request_id', $ret->id)->whereIn('status', ['prepared', 'error'])->update(['status' => 'superseded', 'updated_at' => now()]);
                $customer->prepare($ret, 'cancellation', view('fleet::emails.pickup-cancelled', ['ret' => $ret])->render());
            } else {
                if (now()->gte(CarbonImmutable::parse($row->updated_at)->addMinutes(20))) {
                    throw ValidationException::withMessages(['correction' => 'El plazo de 20 minutos terminó. Se mantiene la cantidad de bultos informada en la solicitud. Contacta a tu ejecutivo de Postventa.']);
                }
                abort_if(DB::table('ret_coordination_tasks')->where('service_request_id', $ret->id)->where('status', 'pending')->exists(), 409, 'Ya hay una coordinación pendiente. Contacta a tu ejecutivo.');
                DB::table('ret_coordination_tasks')->insert(['service_request_id' => $ret->id, 'assigned_to_user_id' => $ret->requested_by_user_id, 'kind' => 'correction', 'previous_status' => $ret->status, 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
                $ret->update(['packages' => (int) $data['packages'], 'requested_weight_kg' => $data['total_weight_kg'], 'email_status' => 'not_prepared', 'email_body' => null, 'email_subject' => null, 'email_prepared_at' => null]);
                DB::table('ret_notifications')->where('service_request_id', $ret->id)->whereIn('status', ['prepared', 'error'])->update(['status' => 'superseded', 'updated_at' => now()]);
                DB::table('service_request_overload_authorizations')->where('service_request_id', $ret->id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'expired', 'updated_at' => now()]);
                DB::afterCommit(fn () => $this->notifyRequester($ret, $data['reason']));
            }
            DB::table('ret_customer_notifications')->where('id', $row->id)->update(['responded_at' => now(), 'updated_at' => now()]);
            $service->event($ret, null, 'customer_'.$action, ['reason' => $data['reason'], 'proposed_packages' => $data['packages'] ?? null, 'weight_mode' => $data['weight_mode'] ?? null, 'package_weights_kg' => $data['package_weights'] ?? null, 'total_weight_kg' => $data['total_weight_kg'] ?? null]);
        });

        return redirect()->route('fleet.customer.done');
    }

    public function done(): Response
    {
        return response('<h1>Recibimos tu aviso</h1><p>La respuesta quedó registrada y tu ejecutivo podrá revisarla.</p>')->header('Cache-Control', 'no-store');
    }

    public function send(Request $request, ServiceRequest $serviceRequest, int $notification, FleetAccess $access, PickupCustomerService $customer): RedirectResponse
    {
        $this->authorizeRet($request, $serviceRequest, $access, 'coordination.requests');
        $row = DB::table('ret_customer_notifications')->where('service_request_id', $serviceRequest->id)->where('id', $notification)->first();
        abort_unless($row, 404);
        $data = $request->validate(['mode' => ['required', 'in:test,customer']]);
        abort_if($data['mode'] === 'customer' && $serviceRequest->is_test, 409, 'Los datos de prueba solo pueden enviarse a Hans.');
        $customer->deliver($notification, $data['mode'] === 'test', true);

        return back()->with('status', 'Microsoft aceptó el aviso. Revisa el estado y la bandeja de Enviados.');
    }

    public function receipt(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, PickupCustomerService $customer): RedirectResponse
    {
        $this->authorizeRet($request, $serviceRequest, $access, 'coordination.requests');
        abort_unless(in_array($serviceRequest->status, ['requested', 'correction_requested'], true), 409);
        $customer->receipt($serviceRequest);

        return back()->with('status', 'Aviso de solicitud recibida preparado.');
    }

    public function mailSettings(Request $request, FleetAccess $access, MicrosoftRetMail $mail): RedirectResponse
    {
        $data = $request->validate(['automatic_receipts' => ['required', 'boolean']]);
        $id = $access->tenant($request)->id;
        $settings = $mail->settings($id);
        abort_if($data['automatic_receipts'] && ($settings['account'] ?? '') !== MicrosoftRetMail::SENDER, 422, 'Conecta primero la cuenta de retiros.');
        $mail->save($id, array_replace($settings, ['automatic_receipts' => (bool) $data['automatic_receipts']]));

        return back()->with('status', 'Configuración de avisos actualizada.');
    }
}
